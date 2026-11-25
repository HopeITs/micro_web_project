<?php

namespace App\Livewire;

use App\Livewire\Concerns\HasDatasetColumns;
use App\Livewire\Concerns\HasSmartFilters;
use App\Services\Finance\FinanceService;
use App\Services\Finance\PaymentScheduleService;
use App\Support\Datasets\Dataset;
use App\Support\Datasets\InstallmentsDataset;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The payment schedule page (M4.9, owner decision 4): count tiles that filter the list, the filters, totals per currency. */
class PaymentSchedule extends Component
{
    use HasDatasetColumns;
    use HasSmartFilters {
        savableViewFilters as smartSavableViewFilters;
        loadViewFilters as smartLoadViewFilters;
    }
    use WithPagination;

    /** Project numbers / names (several separated by a comma or a new line); can hold a name, so it stays out of the URL. */
    public string $search = '';

    private ?InstallmentsDataset $datasetInstance = null;

    protected function dataset(): Dataset
    {
        return $this->datasetInstance ??= new InstallmentsDataset;
    }

    protected function smartFilterHidden(): array
    {
        return ['city', 'group', 'currency']; // city chips and the selects of the page
    }

    protected function savedViewShareCapability(): string
    {
        return 'finance.view';
    }

    /** The city chips are saved as a `city` filter (ids only); the search box and the tab are not saved. */
    protected function filtersCameFromUrl(): bool
    {
        return $this->filters !== [] || $this->cities !== [];
    }

    protected function savableViewFilters(): array
    {
        $filters = $this->smartSavableViewFilters();
        $allowed = app(PaymentScheduleService::class)->cities(auth()->user())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $chosen = array_values(array_intersect(array_map('intval', $this->cities), $allowed));
        if ($chosen !== []) {
            $filters[] = ['field' => 'city', 'op' => 'in', 'values' => array_map('strval', $chosen)];
        }

        return $filters;
    }

    protected function loadViewFilters(array $filters): void
    {
        $city = $filters['city'] ?? null;
        unset($filters['city']);
        $this->cities = $city !== null && $city['op'] === 'in' ? array_map('intval', $city['values']) : [];
        $this->smartLoadViewFilters($filters);
    }

    protected function resetForView(): void
    {
        $this->search = '';
    }

    /** The list query with every filter but `$exceptField`'s (facet counts); the account's finance cities come first. */
    protected function filteredQuery(?string $exceptField = null): Builder
    {
        $schedule = app(PaymentScheduleService::class);
        $filters = $this->filters();
        $ids = $schedule->cityIdsFor(auth()->user(), $filters);

        return $schedule->builder($ids, $filters + ['spec' => $this->querySpec(), 'search' => $this->search], $exceptField);
    }

    #[Url(as: 'view')]
    public string $tab = 'due';

    /** @var list<int> */
    #[Url]
    public array $cities = [];

    #[Url]
    public string $year = '';

    #[Url]
    public string $month = '';

    #[Url]
    public string $group = '';

    #[Url]
    public string $currency = '';

    protected function exportRoute(): string
    {
        return 'payments.export';
    }

    /** Everything the list query depends on travels to the export through the server-side token, never in an address. */
    protected function exportStateProperties(): array
    {
        return ['search', 'tab', 'cities', 'year', 'month', 'group', 'currency', 'filters', 'textFilters', 'tableSort'];
    }

    /** The page's list query for the export: view, year, month and filters applied, in schedule order unless the user sorted. */
    public function exportQuery(): Builder
    {
        [$query, $dateColumn] = app(PaymentScheduleService::class)->exportList(auth()->user(), $this->scheduleFilters());

        return $this->sortList() === [] ? $query->orderBy($dateColumn) : $query;
    }

    /** @return array<string, mixed> the page's filters with the bar's spec and the search */
    private function scheduleFilters(): array
    {
        return $this->filters() + ['spec' => $this->querySpec(), 'search' => $this->search];
    }

    public function mount(): void
    {
        // The page visit is audited once (and refused without finance in any city); later round trips only filter.
        app(PaymentScheduleService::class)->opened(auth()->user());
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function show(string $view): void
    {
        $this->tab = in_array($view, PaymentScheduleService::VIEWS, true) ? $view : 'due';
        $this->resetPage();
    }

    public function toggleCity(int $city): void
    {
        $chosen = array_map('intval', $this->cities);
        $this->cities = in_array($city, $chosen, true) ? array_values(array_diff($chosen, [$city])) : [...$chosen, $city];
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['tab', 'cities', 'year', 'month', 'group', 'currency', 'search']);
        $this->clearSmartFilters();
        $this->resetPage();
    }

    /** @return array<string, mixed> the filters, cleaned */
    public function filters(): array
    {
        $year = filter_var($this->year, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
        $month = filter_var($this->month, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
        $group = filter_var($this->group, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return [
            'view' => in_array($this->tab, PaymentScheduleService::VIEWS, true) ? $this->tab : 'due',
            'cities' => array_values(array_filter(array_map('intval', $this->cities))),
            'year' => $year === false ? null : $year, 'month' => $month === false ? null : $month, 'group' => $group === false ? null : $group,
            'currency' => in_array($this->currency, FinanceService::CURRENCIES, true) ? $this->currency : null,
        ];
    }

    public function render(PaymentScheduleService $schedule): View
    {
        $filters = $this->filters();
        $smart = $this->scheduleFilters();
        $overview = $schedule->overview(auth()->user(), $smart, audit: false);
        $query = $overview['query'];
        if ($this->sortList() === []) {
            $query->orderBy($overview['dateColumn']);
        }

        return view('livewire.payment-schedule', [
            'rows' => $this->tableQuery($query)->toBase()->paginate($this->perPage()),
            'totals' => $overview['totals'], 'tiles' => $overview['tiles'], 'cityOptions' => $overview['cityOptions'], 'groups' => $overview['groups'],
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== []),
            'relaxed' => $filters['view'] === 'due' && PaymentScheduleService::narrowed($smart),
            'filterBar' => $this->filterBar(),
            'table' => $this->dataTable(),
            'columnPicker' => $this->columnPicker(),
        ]);
    }
}
