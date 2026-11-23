<?php

namespace App\Livewire;

use App\Models\WebAccount;
use App\Services\Reports\ApplicationDetailsExport;
use App\Services\Reports\ProjectReportFilters;
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
 * The search page (M4.17 part A): one of the 22 search types, the cities, the common filters, a text box and the result table. Mode, cities and
 * the lookup/date/amount filters may be in the URL; the text never is (it can hold a name or a national number): it lives only in the Livewire
 * request body and reaches the export through a single-use token kept server-side.
 */
class SearchPage extends Component
{
    use WithPagination;

    #[Url(as: 'mode', except: '')]
    public string $mode = '';

    /** @var list<int> */
    #[Url(as: 'city', except: [])]
    public array $cities = [];

    /** @var array<string, mixed> */
    #[Url(as: 'f', except: [])]
    public array $filters = [];

    #[Url(except: false)]
    public bool $last = false;

    public string $term = '';

    public function mount(SearchService $search): void
    {
        $available = $search->available($this->account());
        $this->mode = isset($available[$this->mode]) ? $this->mode : (string) array_key_first($available);
        $this->cities = array_values(array_filter(array_map('intval', $this->cities)));
        $this->filters = $this->cleanFilters();
        $search->opened($this->account(), $this->mode);
    }

    private function account(): WebAccount
    {
        return auth()->user();
    }

    /** @return array<string, mixed> the common filters worth keeping in the URL (no text) */
    private function cleanFilters(): array
    {
        return array_filter(ProjectReportFilters::clean($this->filters), fn ($v, $k) => $k !== 'q' && $v !== [] && $v !== '', ARRAY_FILTER_USE_BOTH);
    }

    /** @return array<string, mixed> everything that decides the rows, text included */
    private function state(): array
    {
        return ['q' => $this->term, 'cities' => $this->cities, 'last' => $this->last] + $this->filters;
    }

    public function updatedMode(SearchService $search): void
    {
        $search->opened($this->account(), $this->mode);
        $this->filters = [];
        $this->term = '';
        $this->last = false;
        $this->resetPage();
    }

    public function updated(string $name): void
    {
        if ($name !== 'mode') {
            $this->resetPage();
        }
    }

    public function toggleCity(int $city): void
    {
        $this->cities = in_array($city, $this->cities, true) ? array_values(array_diff($this->cities, [$city])) : [...$this->cities, $city];
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->filters = [];
        $this->last = false;
        $this->resetPage();
    }

    /** Hands the search (filters, cities, text) to the export route through a single-use token, so the text never appears in an address. */
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
        $filters = ProjectReportFilters::clean($this->filters);
        $options = $mode->filterKind() === 'none' ? [] : ProjectReportFilters::options($cityOptions->pluck('id')->map(fn ($id) => (int) $id)->all());

        return view('livewire.search-page', $result + [
            'cityOptions' => $cityOptions, 'groups' => $groups, 'options' => $options, 'filterKind' => $mode->filterKind(), 'headings' => $mode->headings(), 'numeric' => $mode->numeric(),
            'linkColumn' => $mode->linkColumn(), 'canExport' => $search->canExport($account, $this->mode), 'canExportDetails' => $this->mode === 'applications' && $search->canExport($account, 'applications'),
            'activeFilters' => count(array_filter($filters, fn ($v, $k) => $k !== 'q' && $v !== [] && $v !== '', ARRAY_FILTER_USE_BOTH)) + ($this->last ? 1 : 0),
        ]);
    }
}
