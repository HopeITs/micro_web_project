<?php

namespace App\Livewire;

use App\Livewire\Concerns\HasReportFilters;
use App\Livewire\Concerns\HasReportViews;
use App\Models\WebAccount;
use App\Services\Reports\ApplicationDetailsExport;
use App\Services\Reports\ReportFilterFields;
use App\Services\Reports\Search\SearchMode;
use App\Services\Reports\Search\SearchModes;
use App\Services\Reports\Search\SearchService;
use App\Services\Reports\TableExport;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The search page (M4.17 part A): one of the 22 search types, the cities, the shared filter bar, a text box and the result table, sortable
 * over every page. Mode, cities, filters (lookup ids, years, dates, amounts) and the sort column may be in the URL; the text never is (it
 * can hold a name or a national number): it lives only in the Livewire request body and reaches the export through a single-use token kept
 * server-side.
 */
class SearchPage extends Component
{
    use HasReportFilters, HasReportViews, WithPagination;

    #[Url(as: 'mode', except: '')]
    public string $mode = '';

    #[Url(except: false)]
    public bool $last = false;

    /** The column the results are ordered by ("" = the type's own order). */
    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: 'asc')]
    public string $dir = 'asc';

    public string $term = '';

    public function mount(SearchService $search): void
    {
        $available = $search->available($this->account());
        $this->mode = isset($available[$this->mode]) ? $this->mode : (string) array_key_first($available);
        $this->cities = array_values(array_filter(array_map('intval', $this->cities)));
        $this->filters = $this->keptFilters();
        $this->dir = $this->dir === 'desc' ? 'desc' : 'asc';
        $search->opened($this->account(), $this->mode);
    }

    private function account(): WebAccount
    {
        return auth()->user();
    }

    private function currentMode(): ?SearchMode
    {
        return in_array($this->mode, SearchModes::keys(), true) ? SearchModes::make($this->mode)->for($this->account()) : null;
    }

    protected function reportFilterKind(): string
    {
        return $this->currentMode()?->filterKind() ?? 'none';
    }

    protected function permittedCityIds(): array
    {
        $mode = $this->currentMode();

        return $mode === null ? [] : app(SearchService::class)->cities($this->account(), $mode)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    protected function facetCountsFor(string $field): array
    {
        return app(SearchService::class)->facetCounts($this->account(), $this->mode, $field, $this->state());
    }

    protected function filtersChanged(): void
    {
        $this->resetPage();
    }

    protected function viewDataset(): string
    {
        return 'search';
    }

    protected function viewShareCapability(): string
    {
        return 'projects.view';
    }

    protected function viewSettings(): array
    {
        return ['mode' => $this->mode, 'last' => $this->last, 'sort' => $this->sort, 'dir' => $this->dir];
    }

    protected function applyViewSettings(array $settings): bool
    {
        $mode = is_string($settings['mode'] ?? null) ? $settings['mode'] : '';
        if (! isset(app(SearchService::class)->available($this->account())[$mode])) {
            return false;
        }
        if ($mode !== $this->mode) {
            $this->mode = $mode;
            $this->modeChanged(app(SearchService::class));
        }
        $this->last = (bool) ($settings['last'] ?? false);
        $sort = is_string($settings['sort'] ?? null) ? $settings['sort'] : '';
        $this->sort = isset($this->currentMode()->sorts()[$sort]) ? $sort : '';
        $this->dir = ($settings['dir'] ?? '') === 'desc' ? 'desc' : 'asc';

        return $this->sort === $sort;
    }

    /** @return array<string, mixed> everything that decides the rows, text included */
    private function state(): array
    {
        return ['q' => $this->term, 'cities' => $this->cities, 'last' => $this->last, 'sort' => $this->sort, 'dir' => $this->dir] + $this->filters;
    }

    public function updatedMode(SearchService $search): void
    {
        $this->modeChanged($search);
    }

    /** A type chosen in the picker. */
    public function setMode(string $key, SearchService $search): void
    {
        if ($key !== $this->mode) {
            $this->mode = $key;
            $this->modeChanged($search);
        }
    }

    /** Audits the type; the filters the new type also honours stay, the others are dropped. */
    private function modeChanged(SearchService $search): void
    {
        $search->opened($this->account(), $this->mode);
        $this->permittedMemo = null;
        $this->filters = ReportFilterFields::applicable($this->keptFilters(), $this->reportFilterKind());
        $this->editing = null;
        $this->filterNotice = null;
        $this->activeViewId = null;
        $this->last = false;
        $this->sort = '';
        $this->dir = 'asc';
        $this->resetPage();
    }

    public function updated(string $name): void
    {
        if ($name !== 'mode') {
            $this->resetPage();
        }
    }

    /** A column header: ascending, then descending, then the type's own order again. */
    public function sortBy(string $column): void
    {
        if (! isset($this->currentMode()?->sorts()[$column])) {
            return;
        }
        if ($this->sort !== $column) {
            [$this->sort, $this->dir] = [$column, 'asc'];
        } elseif ($this->dir === 'asc') {
            $this->dir = 'desc';
        } else {
            [$this->sort, $this->dir] = ['', 'asc'];
        }
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->editing = null;
        $this->last = false;
        $this->changed([]);
    }

    public function clearTerm(): void
    {
        $this->term = '';
        $this->resetPage();
    }

    /** Hands the search (filters, cities, text, order) to the export route through a single-use token, so the text never appears in an address. */
    public function exportTable(string $format, SearchService $search): void
    {
        abort_unless(in_array($format, TableExport::FORMATS, true), 422);
        abort_unless(in_array($this->mode, SearchModes::keys(), true) && $search->canExport($this->account(), $this->mode), 403);
        $token = Str::random(40);
        Cache::put('search-export:'.$token, ['account' => $this->account()->id, 'mode' => $this->mode, 'format' => $format, 'state' => $this->state()], now()->addMinutes(5));
        $this->redirect(route('search.export', ['locale' => app()->getLocale(), 'token' => $token]));
    }

    /** M4.18: every field of every matching application (one row per project) as Excel/CSV; the applications type only. */
    public function exportDetails(string $format, ApplicationDetailsExport $details): void
    {
        abort_unless(in_array($format, TableExport::FORMATS, true), 422);
        abort_unless($this->mode === 'applications' && $details->canExport($this->account()), 403);
        $token = Str::random(40);
        Cache::put('search-export:'.$token, ['account' => $this->account()->id, 'mode' => 'applications', 'details' => true, 'format' => $format, 'state' => $this->state()], now()->addMinutes(5));
        $this->redirect(route('search.export', ['locale' => app()->getLocale(), 'token' => $token]));
    }

    public function render(SearchService $search): View
    {
        $account = $this->account();
        $available = $search->available($account);
        abort_if($available === [], 403);
        if (! isset($available[$this->mode])) {
            $this->mode = (string) array_key_first($available);
            $this->permittedMemo = null;
        }
        $result = $search->page($account, $this->mode, $this->state(), $this->getPage());
        // `mode` and `cities` are the component's own (chosen) properties; the objects go to the view as searchMode and cityOptions.
        $mode = $result['mode'];
        $cityOptions = $result['cities'];
        unset($result['cities'], $result['mode']);
        $result['searchMode'] = $mode;
        $groups = [];
        foreach (SearchModes::GROUPS as $group) {
            $items = array_filter($available, fn ($m) => $m->group() === $group);
            $items !== [] && $groups[$group] = $items;
        }
        $bar = $this->reportFilterBar();
        $keys = ['city', ...$mode->columns()];
        $sorts = $mode->sorts();

        return view('livewire.search-page', $result + [
            'cityOptions' => $cityOptions, 'groups' => $groups, 'filterKind' => $mode->filterKind(), 'headings' => $mode->headings(), 'columnKeys' => $keys,
            'sortable' => array_values(array_intersect($keys, array_keys($sorts))), 'sortedBy' => isset($sorts[$this->sort]) ? $this->sort : '',
            'numeric' => $mode->numeric(), 'linkColumn' => $mode->linkColumn(), 'canExport' => $search->canExport($account, $this->mode),
            'canExportDetails' => $this->mode === 'applications' && $search->canExport($account, 'applications'),
            'bar' => $bar, 'views' => $this->reportViewsBar(), 'sentences' => $this->filterSentences($bar),
        ]);
    }
}
