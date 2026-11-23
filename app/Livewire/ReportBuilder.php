<?php

namespace App\Livewire;

use App\Livewire\Concerns\HasSmartFilters;
use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Reports\ReportDrill;
use App\Services\Reports\ReportExport;
use App\Services\Reports\ReportQuery;
use App\Services\Reports\TableExport;
use App\Support\Datasets\Dataset;
use App\Support\Datasets\DatasetRegistry;
use App\Support\Datasets\InvalidQuerySpec;
use App\Support\Datasets\ReportSpec;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The report builder (smart-search plan 2026-11, phase 3): pick a dataset the account may read, the rows, an optional columns
 * field and a measure, narrow with the same smart filter bar the lists use, and read the pivot as a table and as a chart. A cell
 * opens the dataset's list with the equivalent filters (ReportDrill). Reports are saved through saved_views with kind `report`
 * (HasSmartFilters; the rows, columns and measure travel in the stored spec) and exported as Excel/CSV through a single-use token.
 *
 * Everything in the address is a field key, a grain, a measure name or a lookup id; each is checked against the dataset on every
 * request, so a tampered key falls back to a valid default (or is refused when set from the page) and never reaches SQL.
 */
class ReportBuilder extends Component
{
    use HasSmartFilters;

    public const CHARTS = ['bar', 'stacked', 'line', 'pie', 'doughnut'];

    #[Url(as: 'ds', except: '')]
    public string $source = '';

    #[Url(as: 'rows', except: '')]
    public string $rows = '';

    #[Url(as: 'rg', except: '')]
    public string $rowGrain = '';

    #[Url(as: 'cols', except: '')]
    public string $columns = '';

    #[Url(as: 'cg', except: '')]
    public string $columnGrain = '';

    #[Url(as: 'm', except: 'count')]
    public string $measure = 'count';

    #[Url(as: 'mf', except: '')]
    public string $measureField = '';

    #[Url(as: 'chart', except: 'bar')]
    public string $chart = 'bar';

    /** Audited once per distinct set of fields (never values), so typing in a filter does not flood the trail. */
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

    protected function filteredQuery(?string $exceptField = null): Builder
    {
        $query = $this->dataset()->query();
        $cities = app(ReportQuery::class)->cityIds($this->account(), $this->dataset());
        $query->whereIn($this->dataset()->cityColumn(), $cities === [] ? [0] : $cities);

        return $this->applySmartFilters($query, $exceptField);
    }

    protected function savedViewKind(): string
    {
        return 'report';
    }

    protected function savedViewShareCapability(): string
    {
        return $this->dataset()->viewCapabilities()[0];
    }

    /** A report opened from an address that names its rows is not replaced by the account's default report. */
    protected function filtersCameFromUrl(): bool
    {
        return $this->filters !== [] || $this->rows !== '' || $this->columns !== '' || $this->measure !== 'count';
    }

    protected function savedViewExtra(): array
    {
        return array_diff_key($this->specFromState()->toArray(), ['filters' => 1]);
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
        $this->enforce(true);
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

    public function mount(): void
    {
        $readable = $this->readable();
        abort_if($readable === [], 403);
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

    /** The chosen dataset must be one the account may read; otherwise the first readable one is used. */
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

    /**
     * Puts every control back to a valid value for the dataset. When $report is set (a value came from the page, not from
     * the address) the first thing that had to be reset is announced.
     */
    private function enforce(bool $report): void
    {
        $this->normalizeSource();
        $dataset = $this->dataset();
        $groupable = $dataset->groupable();
        $reset = false;
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
        $types = $this->measureTypes();
        if (! in_array($this->measure, $types, true)) {
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

    /** @return list<string> the measures this dataset offers */
    public function measureTypes(): array
    {
        return $this->dataset()->measurable() === [] ? ['count', 'distinct', 'share'] : ReportSpec::MEASURES;
    }

    public function updatedSource(): void
    {
        $this->rows = $this->columns = $this->rowGrain = $this->columnGrain = $this->measureField = '';
        $this->measure = 'count';
        $this->clearSmartFilters();
        $this->error = null;
        $this->enforce(false);
    }

    public function updated(string $property): void
    {
        if ($property === 'source') {
            return;
        }
        $this->error = null;
        $this->enforce(true);
    }

    /** The report as the controls say it (filters from the bar). */
    private function specFromState(): ReportSpec
    {
        return new ReportSpec(
            $this->rows, $this->rowGrain === '' ? null : $this->rowGrain,
            $this->columns === '' ? null : $this->columns, $this->columnGrain === '' ? null : $this->columnGrain,
            $this->measure, $this->measureField === '' ? null : $this->measureField, $this->querySpec(),
        );
    }

    private function audit(ReportSpec $spec): void
    {
        $signature = implode('|', [$this->source, $this->rows, $this->rowGrain, $this->columns, $this->columnGrain, $this->measure, $this->measureField, implode(',', $spec->fieldKeys())]);
        if ($signature === $this->auditedSignature) {
            return;
        }
        $this->auditedSignature = $signature;
        app(AccountAuditTrail::class)->record($this->account()->id, 'reports.statistics', 'succeeded', decision: implode('/', array_filter([
            'report', 'ds:'.$this->source, 'rows:'.$this->rows, $this->columns !== '' ? 'cols:'.$this->columns : null,
            'm:'.$this->measure.($this->measureField !== '' ? '.'.$this->measureField : ''), 'fields:'.implode('.', $spec->fieldKeys()),
        ])));
    }

    // ---------------------------------------------------------------- export

    public function canExportReport(): bool
    {
        return app(ReportExport::class)->allowed($this->account(), $this->source);
    }

    /** Hands the report to the export route through a single-use token kept server-side for five minutes. */
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

    public function clearReport(): void
    {
        $this->updatedSource();
        $this->chart = 'bar';
    }

    public function render(): View
    {
        $account = $this->account();
        $dataset = $this->dataset();
        $spec = $this->specFromState();
        $result = null;
        $errors = $spec->errors($dataset);
        if ($errors === []) {
            $result = app(ReportQuery::class)->run($account, $dataset, $spec);
            $this->audit($spec);
        }

        return view('livewire.report-builder', [
            'datasets' => array_map(fn (Dataset $d) => __('report_builder.dataset_'.$d->key()), $this->readable()),
            'groupable' => array_map(fn ($f) => ['label' => $f->label(), 'date' => $f->type === 'date'], $dataset->groupable()),
            'measurable' => array_map(fn ($f) => $f->label().($f->type === 'money' ? ' ('.__('report_builder.per_currency').')' : ''), $dataset->measurable()),
            'measureTypes' => $this->measureTypes(),
            'result' => $result,
            'pivots' => $result === null ? [] : array_map(fn ($i) => $result->pivot($i), array_keys($result->slices)),
            'charts' => $result === null ? [] : array_map(fn ($i) => $result->chart($i, $this->chartType()), array_keys($result->slices)),
            'canDrill' => ReportDrill::canLink($dataset, $spec),
            'spec' => $spec,
            'dataset' => $dataset,
            'canExport' => $this->canExportReport(),
            'filterBar' => $this->filterBar(),
            'chartType' => $this->chartType(),
        ]);
    }

    /** The chart type as <x-hc.chart> names it. */
    private function chartType(): string
    {
        return in_array($this->chart, self::CHARTS, true) ? $this->chart : 'bar';
    }
}
