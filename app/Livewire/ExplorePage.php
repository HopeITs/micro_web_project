<?php

namespace App\Livewire;

use App\Livewire\Concerns\HasDatasetColumns;
use App\Livewire\Concerns\HasSmartFilters;
use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Reports\ReportDrill;
use App\Services\Reports\ReportExport;
use App\Services\Reports\ReportQuery;
use App\Services\Reports\TableExport;
use App\Support\Datasets\Dataset;
use App\Support\Datasets\DatasetRegistry;
use App\Support\Datasets\ExplorePresets;
use App\Support\Datasets\InvalidQuerySpec;
use App\Support\Datasets\QuerySpec;
use App\Support\Datasets\ReportSpec;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * The Explore page (task X2): one dataset (projects, people or instalments), ONE shared filter bar (the same HasSmartFilters bar,
 * chips and saved views the lists and the report builder use) and three views over exactly those filters: List (dynamic columns,
 * sort, page size, export), Pivot (rows / columns / measure, totals, drill-down into the list) and Chart. KPI tiles above show
 * the count and, where the dataset has a currency, the money totals per currency (never added across currencies).
 *
 * Authorisation is the report builder's: a dataset is offered only where the account holds its view capabilities in some city, and
 * every view (list, KPI, pivot, chart) covers only the cities where it holds ALL of them (ReportQuery::cityIds), on top of the
 * CityScope the dataset's base query carries. Exports go through the existing single-use tokens and their capability / audit code.
 *
 * Everything in the address is a field key, a grain, a measure name or a lookup id; each is checked against the dataset on every
 * request. Free text (names, national numbers) lives only in the component state, never in the address.
 */
class ExplorePage extends Component
{
    use HasDatasetColumns;
    use HasSmartFilters;
    use WithPagination;

    public const CHARTS = ['bar', 'stacked', 'line', 'pie', 'doughnut'];

    #[Url(as: 'ds', except: '')]
    public string $source = '';

    /** list | pivot | chart */
    #[Url(as: 'v', except: 'list')]
    public string $show = 'list';

    #[Url(as: 'rows', except: '')]
    public string $rows = '';

    #[Url(as: 'rg', except: '')]
    public string $rowGrain = '';

    #[Url(as: 'pc', except: '')]
    public string $columns = '';

    #[Url(as: 'cg', except: '')]
    public string $columnGrain = '';

    #[Url(as: 'm', except: 'count')]
    public string $measure = 'count';

    #[Url(as: 'mf', except: '')]
    public string $measureField = '';

    #[Url(as: 'chart', except: 'bar')]
    public string $chart = 'bar';

    public string $auditedSignature = '';

    public ?string $error = null;

    private ?Dataset $datasetInstance = null;

    private ?string $datasetInstanceKey = null;

    protected function dataset(): Dataset
    {
        if ($this->datasetInstance === null || $this->datasetInstanceKey !== $this->source) {
            $this->datasetInstance = DatasetRegistry::find($this->source) ?? DatasetRegistry::find('projects');
            $this->datasetInstanceKey = $this->source;
        }

        return $this->datasetInstance;
    }

    private function account(): WebAccount
    {
        $account = auth()->user();
        abort_unless($account instanceof WebAccount, 403);

        return $account;
    }

    /** @return array<string, Dataset> */
    private function readable(): array
    {
        return DatasetRegistry::readableBy($this->account());
    }

    /** @return list<int> the cities every view covers: where the account holds all the dataset's view capabilities */
    private function cities(): array
    {
        return app(ReportQuery::class)->cityIds($this->account(), $this->dataset());
    }

    /** The one query all three views and the KPI tiles start from: city scope, then the bar's filters. */
    protected function filteredQuery(?string $exceptField = null): Builder
    {
        $query = $this->dataset()->query();
        $cities = $this->cities();
        $query->whereIn($this->dataset()->cityColumn(), $cities === [] ? [0] : $cities);

        return $this->applySmartFilters($query, $exceptField);
    }

    // ---------------------------------------------------------------- saved views (kind `report`, shared with the report builder)

    protected function savedViewKind(): string
    {
        return 'report';
    }

    protected function savedViewShareCapability(): string
    {
        return $this->dataset()->viewCapabilities()[0];
    }

    protected function filtersCameFromUrl(): bool
    {
        return $this->filters !== [] || $this->rows !== '' || $this->columns !== '' || $this->measure !== 'count' || $this->show !== 'list'
            || $this->tableColumns !== '' || $this->tableSort !== '';
    }

    protected function savedViewExtra(): array
    {
        return [...array_diff_key($this->specFromState()->toArray(), ['filters' => 1]), 'explore_view' => $this->show];
    }

    protected function restoreViewExtra(array $spec): void
    {
        try {
            $stored = ReportSpec::fromArray($spec);
        } catch (InvalidQuerySpec) {
            $this->error = __('report_builder.view_dropped');

            return;
        }
        $this->rows = $stored->rows;
        $this->rowGrain = $stored->rowGrain ?? '';
        $this->columns = $stored->columns ?? '';
        $this->columnGrain = $stored->columnGrain ?? '';
        $this->measure = $stored->measure;
        $this->measureField = $stored->measureField ?? '';
        $stored = $spec['explore_view'] ?? null;
        $this->show = is_string($stored) && in_array($stored, ExplorePresets::VIEWS, true) ? $stored : $this->show;
        $this->enforce(true);
    }

    // ---------------------------------------------------------------- mount and consistency

    public function mount(): void
    {
        abort_if($this->readable() === [], 403);
        $this->enforce(false);
    }

    /** Replaces the trait's mount hook: the dataset is settled first, so the address's filters are checked against the right one. */
    public function mountHasSmartFilters(): void
    {
        $this->normalizeSource();
        $this->filters = $this->sanitizeFilters($this->filters, false);
        if (! $this->filtersCameFromUrl()) {
            $this->applyDefaultView();
        }
    }

    private function normalizeSource(): void
    {
        $readable = $this->readable();
        if ($readable === []) {
            abort(403);
        }
        if (! isset($readable[$this->source])) {
            $this->source = array_key_first($readable);
        }
    }

    private function enforce(bool $report): void
    {
        $this->normalizeSource();
        $dataset = $this->dataset();
        $groupable = $dataset->groupable();
        $reset = false;
        if (! in_array($this->show, ExplorePresets::VIEWS, true)) {
            $this->show = 'list';
        }
        if (! isset($groupable[$this->rows])) {
            $reset = $reset || $this->rows !== '';
            $this->rows = isset($groupable['state']) ? 'state' : (string) array_key_first($groupable);
        }
        if ($this->columns !== '' && (! isset($groupable[$this->columns]) || $this->columns === $this->rows)) {
            $reset = true;
            $this->columns = '';
        }
        foreach (['rowGrain' => $this->rows, 'columnGrain' => $this->columns] as $property => $key) {
            $date = $key !== '' && $dataset->field($key)?->type === 'date';
            if (! $date) {
                $reset = $reset || $this->{$property} !== '';
                $this->{$property} = '';
            } elseif (! in_array($this->{$property}, Dataset::GRAINS, true)) {
                $this->{$property} = 'year';
            }
        }
        $measurable = $dataset->measurable();
        if (! in_array($this->measure, $this->measureTypes(), true)) {
            $reset = true;
            $this->measure = 'count';
        }
        if (in_array($this->measure, ReportSpec::FIELD_MEASURES, true)) {
            if (! isset($measurable[$this->measureField])) {
                $reset = $reset || $this->measureField !== '';
                $this->measureField = (string) array_key_first($measurable);
            }
        } else {
            $this->measureField = '';
        }
        if (! in_array($this->chart, self::CHARTS, true)) {
            $this->chart = 'bar';
        }
        if ($report && $reset) {
            $this->error = __('report_builder.invalid');
        }
    }

    /** @return list<string> */
    public function measureTypes(): array
    {
        return $this->dataset()->measurable() === [] ? ['count', 'distinct', 'share'] : ReportSpec::MEASURES;
    }

    /** The dataset changes: the pivot, the columns, the sort and the filters of the old one do not apply. */
    public function updatedSource(): void
    {
        $this->resetForSource();
        $this->enforce(false);
    }

    private function resetForSource(): void
    {
        $this->rows = $this->columns = $this->rowGrain = $this->columnGrain = $this->measureField = '';
        $this->measure = 'count';
        $this->tableColumns = $this->tableSort = '';
        $this->columnError = null;
        $this->datasetInstance = null;
        $this->clearSmartFilters();
        $this->error = null;
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if ($property === 'source' || $property === 'perPage') {
            return;
        }
        $this->error = null;
        $this->enforce(true);
    }

    public function setShow(string $show): void
    {
        if (in_array($show, ExplorePresets::VIEWS, true)) {
            $this->show = $show;
            $this->error = null;
        }
    }

    public function setChart(string $chart): void
    {
        $this->chart = in_array($chart, self::CHARTS, true) ? $chart : 'bar';
    }

    public function clearAll(): void
    {
        $this->resetForSource();
        $this->show = 'list';
        $this->chart = 'bar';
        $this->enforce(false);
    }

    // ---------------------------------------------------------------- ready questions, drill-down, ask box

    /** One click: the preset's filters, rows, columns, measure and view replace the current setup (the dataset stays). */
    public function openQuestion(string $key): void
    {
        $preset = ExplorePresets::find($this->source, $key);
        if ($preset === null) {
            return;
        }
        $this->resetForSource();
        $this->show = $preset['view'];
        $this->rows = $preset['rows'];
        $this->rowGrain = $preset['rowGrain'];
        $this->columns = $preset['columns'];
        $this->columnGrain = $preset['columnGrain'];
        $this->measure = $preset['measure'];
        $this->measureField = $preset['measureField'];
        $this->chart = $preset['chart'];
        $this->putFilters($preset['filters']);
        $this->enforce(true);
    }

    /**
     * A pivot cell: the list view with that cell's filters added to the current ones. $row / $col are group keys ('' = not set,
     * null = every row / column), $currency the slice of a money pivot. A key the dataset cannot read is ignored.
     */
    public function drill(?string $row = null, ?string $col = null, ?string $currency = null): void
    {
        $dataset = $this->dataset();
        $add = [];
        try {
            if ($row !== null && $this->rows !== '') {
                $add[$this->rows] = ReportDrill::constraint($dataset, $this->rows, $this->rowGrain ?: null, $row);
            }
            if ($col !== null && $this->columns !== '') {
                $add[$this->columns] = ReportDrill::constraint($dataset, $this->columns, $this->columnGrain ?: null, $col);
            }
        } catch (Throwable) {
            return; // a malformed date key from a tampered request
        }
        if ($currency !== null && $dataset->currencyField() !== null) {
            $add[$dataset->currencyField()] = ['op' => 'in', 'values' => [$currency]];
        }
        foreach ($add as $key => $filter) {
            $field = $dataset->field($key);
            if ($field === null || QuerySpec::filterErrors($dataset, ['field' => $key, 'op' => $filter['op'], 'values' => $filter['values']]) !== []) {
                return;
            }
        }
        foreach ($add as $key => $filter) {
            $field = $dataset->field($key);
            if ($field->type === 'text' || $field->sensitive) {
                continue; // groupable fields are never text
            }
            unset($this->textFilters[$key]);
            $this->filters[$key] = ['op' => $filter['op'], 'values' => array_map('strval', $filter['values'])];
        }
        $this->show = 'list';
        $this->activeViewId = null;
        $this->viewNotice = null;
        $this->resetPage();
    }

    /** The ask box (another task) sends the question it understood: {dataset, spec: QuerySpec array, report?: {rows, columns, measure}}. */
    #[On('ask-applied')]
    public function applyAsk(string $dataset = '', array $spec = [], ?array $report = null): void
    {
        $readable = $this->readable();
        if (! isset($readable[$dataset])) {
            return;
        }
        try {
            $checked = QuerySpec::fromArray($spec)->validate($readable[$dataset]);
        } catch (InvalidQuerySpec) {
            $this->error = __('explore.ask_invalid');

            return;
        }
        $this->source = $dataset;
        $this->resetForSource();
        $this->show = 'list';
        $this->putFilters($checked->filters);
        $sort = array_map(fn ($s) => ($s['dir'] === 'desc' ? '-' : '').$s['field'], $checked->sort);
        $this->tableSort = implode(',', $sort);
        if ($report !== null) {
            $rows = $report['rows'] ?? '';
            $columns = $report['columns'] ?? '';
            $measure = $report['measure'] ?? 'count';
            $this->rows = is_array($rows) ? (string) ($rows['field'] ?? '') : (string) $rows;
            $this->rowGrain = is_array($rows) ? (string) ($rows['grain'] ?? '') : '';
            $this->columns = is_array($columns) ? (string) ($columns['field'] ?? '') : (string) $columns;
            $this->columnGrain = is_array($columns) ? (string) ($columns['grain'] ?? '') : '';
            $this->measureField = is_array($measure) ? (string) ($measure['field'] ?? '') : '';
            $this->measure = is_array($measure) ? (string) ($measure['type'] ?? 'count') : (string) $measure;
            $this->show = 'pivot';
        }
        $this->enforce(true);
    }

    /** @param list<array{field: string, op: string, values: list<mixed>}> $list already valid filters of the dataset */
    private function putFilters(array $list): void
    {
        $dataset = $this->dataset();
        foreach ($list as $filter) {
            $field = $dataset->field($filter['field']);
            if ($field === null || QuerySpec::filterErrors($dataset, $filter) !== []) {
                continue;
            }
            $value = ['op' => $filter['op'], 'values' => array_values($filter['values'])];
            if ($field->type === 'text' || $field->sensitive) {
                $this->textFilters[$field->key] = $value;
            } else {
                $this->filters[$field->key] = $value;
            }
        }
    }

    // ---------------------------------------------------------------- list: header sort and export

    protected function exportRoute(): string
    {
        return match ($this->source) {
            'people' => 'people.export',
            'installments' => 'payments.export',
            default => 'projects.export',
        };
    }

    /**
     * Hands the list to the dataset's own export route through ITS single-use token (the route re-checks the export capability per
     * city, audits "<dataset>.exported" and streams exactly the shown columns, filters and sort).
     */
    public function exportTable(string $format): void
    {
        abort_unless(in_array($format, TableExport::FORMATS, true), 422);
        $account = $this->account();
        abort_unless($this->canExportTable(), 403);
        $filters = $this->filters;
        $component = match ($this->source) {
            'people' => PeopleIndex::class,
            'installments' => PaymentSchedule::class,
            default => ProjectsIndex::class,
        };
        $state = ['search' => '', 'cities' => [], 'filters' => $filters, 'textFilters' => $this->textFilters];
        if ($this->source === 'projects') {
            $state['path'] = '';
        }
        if ($this->source === 'installments') {
            // The schedule shows only what is due until a filter narrows it; the bar's neutral filter makes it list every instalment.
            $neutral = $this->dataset()->listNeutralFilter();
            if ($neutral !== null && $this->querySpec()->filters === []) {
                $state['filters'][$neutral['field']] = ['op' => $neutral['op'], 'values' => $neutral['values']];
            }
            $state += ['tab' => 'due', 'year' => '', 'month' => '', 'group' => '', 'currency' => '', 'tableSort' => $this->tableSort];
        }
        $token = Str::random(40);
        Cache::put('dataset-export:'.$token, [
            'account' => $account->id, 'component' => $component, 'format' => $format,
            'state' => $state, 'columns' => $this->visibleColumns(), 'sort' => $this->sortList(),
        ], now()->addMinutes(5));
        $this->redirect(route($this->exportRoute(), ['locale' => app()->getLocale(), 'token' => $token]));
    }

    // ---------------------------------------------------------------- pivot export (the report builder's token)

    public function canExportReport(): bool
    {
        return app(ReportExport::class)->allowed($this->account(), $this->source);
    }

    private function specFromState(): ReportSpec
    {
        return new ReportSpec(
            $this->rows, $this->rowGrain === '' ? null : $this->rowGrain,
            $this->columns === '' ? null : $this->columns, $this->columnGrain === '' ? null : $this->columnGrain,
            $this->measure, $this->measureField === '' ? null : $this->measureField, $this->querySpec(),
        );
    }

    public function exportReport(string $format): void
    {
        abort_unless(in_array($format, TableExport::FORMATS, true), 422);
        $account = $this->account();
        $this->enforce(false);
        abort_unless($this->canExportReport(), 403);
        $spec = $this->specFromState();
        try {
            $spec->validate($this->dataset());
        } catch (InvalidQuerySpec) {
            abort(422);
        }
        $token = Str::random(40);
        Cache::put(ReportExport::TOKEN_PREFIX.$token, ['account' => $account->id, 'format' => $format, 'dataset' => $this->source, 'spec' => $spec->toArray()], now()->addMinutes(5));
        $this->redirect(route('reports.export', ['locale' => app()->getLocale(), 'token' => $token]));
    }

    // ---------------------------------------------------------------- render

    /** Audited once per distinct set of fields and view (never values). */
    private function audit(ReportSpec $spec): void
    {
        $signature = implode('|', [$this->source, $this->show, $this->rows, $this->rowGrain, $this->columns, $this->columnGrain, $this->measure, $this->measureField, implode(',', $spec->fieldKeys())]);
        if ($signature === $this->auditedSignature) {
            return;
        }
        $this->auditedSignature = $signature;
        app(AccountAuditTrail::class)->record($this->account()->id, 'reports.statistics', 'succeeded', decision: implode('/', array_filter([
            'explore', 'ds:'.$this->source, 'view:'.$this->show, 'rows:'.$this->rows, $this->columns !== '' ? 'cols:'.$this->columns : null,
            'm:'.$this->measure.($this->measureField !== '' ? '.'.$this->measureField : ''), 'fields:'.implode('.', $spec->fieldKeys()),
        ])));
    }

    /** @return array{count: int, money: list<array{field: string, label: string, currency: string, total: float}>} */
    private function kpis(): array
    {
        $dataset = $this->dataset();
        $count = (int) $this->filteredQuery()->toBase()->reorder()->count();
        $money = [];
        $currency = $dataset->currencyField() === null ? null : $dataset->field($dataset->currencyField());
        if ($currency !== null && $count > 0) {
            foreach ($dataset->measurable() as $field) {
                if ($field->type !== 'money') {
                    continue;
                }
                $rows = $this->filteredQuery()->toBase()->reorder()
                    ->select(DB::raw("{$currency->sql} as kpi_cur"))->selectRaw("SUM({$field->sql}) as kpi_sum")
                    ->groupBy('kpi_cur')->orderBy('kpi_cur')->get();
                foreach ($rows as $row) {
                    if ($row->kpi_cur !== null) {
                        $money[] = ['field' => $field->key, 'label' => $field->label(), 'currency' => (string) $row->kpi_cur, 'total' => (float) $row->kpi_sum];
                    }
                }
            }
        }

        return ['count' => $count, 'money' => $money];
    }

    public function render(): View
    {
        $account = $this->account();
        $dataset = $this->dataset();
        $spec = $this->specFromState();
        $result = null;
        $errors = $spec->errors($dataset);
        if ($errors === [] && $this->show !== 'list') {
            $result = app(ReportQuery::class)->run($account, $dataset, $spec);
        }
        if ($errors === []) {
            $this->audit($spec);
        }
        $rows = $this->show === 'list' ? $this->tableQuery()->toBase()->paginate($this->perPage()) : null;

        return view('livewire.explore-page', [
            'datasets' => array_map(fn (Dataset $d) => __('report_builder.dataset_'.$d->key()), $this->readable()),
            'groupable' => array_map(fn ($f) => ['label' => $f->label(), 'date' => $f->type === 'date'], $dataset->groupable()),
            'measurable' => array_map(fn ($f) => $f->label().($f->type === 'money' ? ' ('.__('report_builder.per_currency').')' : ''), $dataset->measurable()),
            'measureTypes' => $this->measureTypes(),
            'questions' => ExplorePresets::for($this->source),
            'kpis' => $this->kpis(),
            'rowsPage' => $rows,
            'table' => $this->dataTable(),
            'columnPicker' => $this->columnPicker(),
            'result' => $result,
            'pivots' => $result === null ? [] : array_map(fn ($i) => $result->pivot($i), array_keys($result->slices)),
            'charts' => $result === null ? [] : array_map(fn ($i) => $result->chart($i, $this->chart), array_keys($result->slices)),
            'canDrill' => true,
            'spec' => $spec,
            'dataset' => $dataset,
            'canExportReport' => $this->canExportReport(),
            'filterBar' => $this->filterBar(),
            'askBox' => class_exists(AskBox::class),
        ]);
    }
}
