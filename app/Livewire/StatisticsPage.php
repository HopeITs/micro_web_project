<?php

namespace App\Livewire;

use App\Livewire\Concerns\HasReportFilters;
use App\Livewire\Concerns\HasReportViews;
use App\Models\WebAccount;
use App\Services\Reports\Search\SearchModes;
use App\Services\Reports\Search\SearchService;
use App\Services\Reports\StatisticsService;
use App\Services\Reports\TableExport;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Statistics (M4.17 part C): the headline numbers, one of the 15 axes (optionally crossed with a second one or with the city) as a chart
 * and a table, ready reports, and an export of what is shown, over the permitted cities with the shared filter bar. A bar or a row can be
 * added to the filters or opened in the search. The axis, second dimension, cities, view settings and filters may be in the URL (ids and
 * keys only); the export goes through a single-use token. Read only; StatisticsService checks applications.view (and reports.export for
 * the export) per city on every call.
 */
class StatisticsPage extends Component
{
    use HasReportFilters, HasReportViews;

    public const CHARTS = ['bar', 'hbar', 'stacked', 'line', 'pie', 'doughnut'];

    /** Ready reports: view settings, optionally with a ready period added to the filters. The cities and other filters stay. */
    public const REPORTS = [
        'status' => ['axis' => 'status'],
        'status_city' => ['axis' => 'status', 'by' => 'city'],
        'requests_year' => ['axis' => 'project_type', 'sort' => 'desc', 'period' => ['request', 'this_year']],
        'top_donors' => ['axis' => 'donor', 'sort' => 'desc', 'top' => 10, 'chart' => 'hbar'],
        'funding_years' => ['axis' => 'fund_year', 'by' => 'fund_type'],
        'age_sex' => ['axis' => 'age', 'by' => 'sex', 'chart' => 'stacked'],
        'top_categories' => ['axis' => 'category', 'show' => 'funded', 'sort' => 'desc', 'top' => 10],
        'sustainability' => ['axis' => 'sustainability', 'chart' => 'stacked'],
    ];

    /** The most slices or stacked parts a chart draws before the rest are added up (the palette has eight colours, never repeated). */
    private const PARTS = 8;

    #[Url(as: 'axis', except: 'status')]
    public string $axis = 'status';

    /** The second dimension: another axis, "city", or none. */
    #[Url(as: 'by', except: '')]
    public string $by = '';

    /** both | all | funded */
    #[Url(as: 'show', except: 'both')]
    public string $show = 'both';

    /** One of CHARTS, or "auto". */
    #[Url(as: 'chart', except: 'auto')]
    public string $chart = 'auto';

    #[Url(as: 'sort', except: 'default')]
    public string $sortRows = 'default';

    /** 0 = every row, else the largest N with the rest added up. */
    #[Url(as: 'top', except: '0')]
    public string $top = '0';

    #[Url(as: 'nozero', except: false)]
    public bool $hideZero = false;

    /** The bar last pressed in the chart, whose actions are shown. */
    public ?string $picked = null;

    public function mount(StatisticsService $statistics): void
    {
        $this->normalize();
        $this->cities = array_values(array_filter(array_map('intval', $this->cities)));
        $this->filters = $this->keptFilters();
        $statistics->opened($this->account(), $this->axis);
    }

    private function account(): WebAccount
    {
        return auth()->user();
    }

    protected function reportFilterKind(): string
    {
        return 'project';
    }

    protected function permittedCityIds(): array
    {
        return app(StatisticsService::class)->cities($this->account())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    protected function facetCountsFor(string $field): array
    {
        return app(StatisticsService::class)->facetCounts($this->account(), $field, $this->state());
    }

    protected function filtersChanged(): void
    {
        $this->picked = null;
    }

    protected function viewDataset(): string
    {
        return 'statistics';
    }

    protected function viewShareCapability(): string
    {
        return 'applications.view';
    }

    protected function viewSettings(): array
    {
        return ['axis' => $this->axis, 'by' => $this->by, 'show' => $this->show, 'chart' => $this->chart, 'sort' => $this->sortRows, 'top' => $this->top, 'nozero' => $this->hideZero];
    }

    protected function applyViewSettings(array $settings): bool
    {
        $read = fn (string $key, string $default) => is_scalar($settings[$key] ?? null) ? (string) $settings[$key] : $default;
        $wanted = ['axis' => $read('axis', 'status'), 'by' => $read('by', ''), 'show' => $read('show', 'both'), 'chart' => $read('chart', 'auto'), 'sort' => $read('sort', 'default'), 'top' => $read('top', '0')];
        [$this->axis, $this->by, $this->show, $this->chart, $this->sortRows, $this->top] = array_values($wanted);
        $this->hideZero = (bool) ($settings['nozero'] ?? false);
        $this->normalize();
        $this->picked = null;

        return [$this->axis, $this->by, $this->show, $this->chart, $this->sortRows, $this->top] === array_values($wanted);
    }

    /** Everything that may come from the address or the page is brought back to a listed value. */
    private function normalize(): void
    {
        $this->axis = isset(StatisticsService::AXES[$this->axis]) ? $this->axis : 'status';
        $this->by = StatisticsService::validBy($this->axis, $this->by) ?? '';
        $this->show = in_array($this->show, ['both', 'all', 'funded'], true) ? $this->show : 'both';
        $this->chart = in_array($this->chart, [...self::CHARTS, 'auto'], true) ? $this->chart : 'auto';
        $this->sortRows = in_array($this->sortRows, StatisticsService::SORTS, true) ? $this->sortRows : 'default';
        $this->top = in_array($this->top, array_map('strval', StatisticsService::TOPS), true) ? $this->top : '0';
    }

    private function state(): array
    {
        return ['cities' => $this->cities] + $this->filters;
    }

    private function viewOptions(): array
    {
        return ['by' => $this->by !== '' ? $this->by : null, 'show' => $this->show, 'sort' => $this->sortRows, 'top' => (int) $this->top, 'nozero' => $this->hideZero];
    }

    public function updatedAxis(StatisticsService $statistics): void
    {
        $this->normalize();
        $this->picked = null;
        $statistics->opened($this->account(), $this->axis);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['by', 'show', 'top', 'sortRows'], true)) {
            $this->normalize();
            $this->picked = null;
        }
    }

    public function applyReport(string $key, StatisticsService $statistics): void
    {
        $report = self::REPORTS[$key] ?? null;
        if ($report === null) {
            return;
        }
        $this->axis = $report['axis'];
        $this->by = $report['by'] ?? '';
        $this->show = $report['show'] ?? 'both';
        $this->chart = $report['chart'] ?? 'auto';
        $this->sortRows = $report['sort'] ?? 'default';
        $this->top = (string) ($report['top'] ?? 0);
        $this->hideZero = false;
        $this->normalize();
        $this->picked = null;
        $this->activeViewId = null;
        if (isset($report['period'])) {
            $this->setPreset($report['period'][0], $report['period'][1]);
        }
        $statistics->opened($this->account(), $this->axis);
    }

    /** A header of the table: the labels switch between the axis order and A to Z, a number column between largest and smallest first. */
    public function sortBy(string $column): void
    {
        $this->sortRows = $column === 'label'
            ? ($this->sortRows === 'name' ? 'default' : 'name')
            : ($this->sortRows === 'desc' ? 'asc' : 'desc');
    }

    public function pick(string $key): void
    {
        $this->picked = $this->picked === $key ? null : $key;
    }

    /** Adds the bucket of the current axis to the filters (a press on its bar or row). */
    public function filterBy(string $key): void
    {
        $narrowed = StatisticsService::narrow($this->filters, $this->axis, $key);
        if ($narrowed !== null) {
            $this->editing = null;
            $this->changed($narrowed);
        }
    }

    /** A headline tile: only the projects of that series, or all again when pressed a second time. */
    public function filterSeries(string $series, StatisticsService $statistics): void
    {
        if (! in_array($series, ['funded', 'ongoing', 'closed'], true)) {
            return;
        }
        $filters = $this->keptFilters();
        if ($this->seriesChosen($series, $statistics)) {
            unset($filters['status']);
            $this->changed($filters);

            return;
        }
        // The tiles swap with each other, so the state filter a tile set is replaced rather than narrowed.
        foreach (['funded', 'ongoing', 'closed'] as $other) {
            if ($this->seriesChosen($other, $statistics)) {
                unset($filters['status']);
            }
        }
        $narrowed = $statistics->narrowSeries($filters, $series);
        if ($narrowed === null) {
            $this->filterNotice = __('statistics.no_overlap');

            return;
        }
        $this->editing = null;
        $this->changed($narrowed);
    }

    private function seriesChosen(string $series, StatisticsService $statistics): bool
    {
        $filters = $this->keptFilters();
        $states = $statistics->narrowSeries([], $series)['status'] ?? [];
        $chosen = $filters['status'] ?? [];
        sort($states);
        sort($chosen);

        return $states !== [] && $states === $chosen && ! in_array('status', $filters['not'] ?? [], true);
    }

    public function exportTable(string $format, StatisticsService $statistics): void
    {
        abort_unless(in_array($format, TableExport::FORMATS, true), 422);
        abort_unless($statistics->canExport($this->account()), 403);
        $this->normalize();
        $token = Str::random(40);
        Cache::put('statistics-export:'.$token, ['account' => $this->account()->id, 'axis' => $this->axis, 'format' => $format, 'state' => $this->state(), 'view' => $this->viewOptions()], now()->addMinutes(5));
        $this->redirect(route('statistics.export', ['locale' => app()->getLocale(), 'token' => $token]));
    }

    public function render(StatisticsService $statistics, SearchService $search): View
    {
        $account = $this->account();
        $this->normalize();
        $raw = $statistics->axis($account, $this->axis, $this->state(), false, $this->by !== '' ? $this->by : null);
        $result = $statistics->shape($raw, $this->viewOptions());
        $summary = $statistics->summary($account, $this->state());
        $cityOptions = $statistics->cities($account);
        $result = $this->linked($result, $statistics, $search);
        $chart = $this->chartData($result);
        $bar = $this->reportFilterBar();
        $picked = $this->picked === null ? null : collect($result['rows'])->firstWhere('key', $this->picked);
        $tiles = [];
        foreach (['all', 'funded', 'ongoing', 'closed'] as $series) {
            $tiles[$series] = [
                'count' => $summary[$series], 'share' => $summary['all'] > 0 ? round($summary[$series] / $summary['all'] * 100, 1) : 0.0,
                'active' => $series !== 'all' && $this->seriesChosen($series, $statistics),
            ];
        }

        return view('livewire.statistics-page', [
            'result' => $result, 'visible' => $result['visible'], 'chartData' => $chart, 'shown' => $result['cities'], 'cityOptions' => $cityOptions,
            'canExport' => $statistics->canExport($account), 'bar' => $bar, 'views' => $this->reportViewsBar(), 'sentences' => $this->filterSentences($bar),
            'tiles' => $tiles, 'pickedRow' => $picked, 'canFilterRow' => fn (string $key) => StatisticsService::narrow([], $this->axis, $key) !== null,
            'reports' => array_keys($cityOptions->count() > 1 ? self::REPORTS : array_diff_key(self::REPORTS, ['status_city' => true])),
        ]);
    }

    /** Adds to each shown number the address of the applications search that lists exactly those projects, where one exists. */
    private function linked(array $result, StatisticsService $statistics, SearchService $search): array
    {
        $account = $this->account();
        $available = $search->available($account);
        // The search must be able to read every city counted here, else its list would be shorter than the number.
        $searchable = isset($available['applications'])
            && array_diff($result['shown'], $search->cities($account, SearchModes::make('applications')->for($account))->pluck('id')->map(fn ($id) => (int) $id)->all()) === [];
        $locale = app()->getLocale();
        $address = fn (?array $filters, array $cities) => $filters === null ? null
            : route('search.index', ['locale' => $locale, 'mode' => 'applications', 'city' => $cities, 'f' => $filters]);
        $series = [];
        $narrow = function (?array $filters, string $name) use (&$series, $statistics) {
            if ($filters === null || $name === 'all') {
                return $filters;
            }
            $key = $name.'|'.json_encode($filters['status'] ?? []).'|'.json_encode(in_array('status', $filters['not'] ?? [], true));
            if (! array_key_exists($key, $series)) {
                $series[$key] = $statistics->narrowSeries(array_intersect_key($filters, ['status' => true, 'not' => true]), $name);
            }
            if ($series[$key] === null) {
                return null;
            }
            $not = array_values(array_diff($filters['not'] ?? [], ['status']));

            return array_filter(['status' => $series[$key]['status'], 'not' => $not] + $filters, fn ($v) => $v !== [] && $v !== '');
        };
        $measure = count($result['measure']) === 1 ? $result['measure'][0] : null;

        foreach ($result['rows'] as &$row) {
            $row['urls'] = [];
            $row['cell_urls'] = [];
            $base = $searchable && ! $row['other'] ? StatisticsService::narrow($this->filters, $result['axis'], $row['key']) : null;
            if ($base === null) {
                continue;
            }
            foreach ($result['visible'] as $name) {
                $row['urls'][$name] = ($row['counts'][$name] ?? 0) > 0 ? $address($narrow($base, $name), $result['shown']) : null;
            }
            if (! $result['crossed'] || $measure === null) {
                continue;
            }
            foreach ($row['values'] as $column => $value) {
                if ($value === 0) {
                    continue;
                }
                $cell = $result['by'] === StatisticsService::BY_CITY ? $base : StatisticsService::narrow($base, $result['by'], (string) $column);
                $row['cell_urls'][$column] = $address($narrow($cell, $measure), $result['by'] === StatisticsService::BY_CITY ? [(int) $column] : $result['shown']);
            }
        }
        unset($row);

        return $result;
    }

    /** The chart of a shaped result: type, labels, series and the bucket keys its bars stand for. */
    private function chartData(array $result): array
    {
        $crossed = $result['crossed'];
        // The chart leaves out buckets nobody falls in, so it stays readable on long lists.
        $drawn = array_values(array_filter($result['rows'], fn ($r) => $r['value'] > 0 || (! $crossed && array_sum(array_map(fn ($s) => $r['counts'][$s] ?? 0, $result['visible'])) > 0)));
        $available = $crossed ? ['stacked', 'hbar', 'bar'] : ['bar', 'hbar',
            ...(in_array($result['axis'], StatisticsService::ORDERED, true) ? ['line'] : []),
            ...($result['visible'] === ['ongoing', 'closed'] ? ['stacked'] : []),
            'pie', 'doughnut'];
        $chosen = in_array($this->chart, $available, true) ? $this->chart : (count($drawn) > 6 && ! in_array($result['axis'], StatisticsService::ORDERED, true) ? 'hbar' : ($crossed ? 'stacked' : 'bar'));
        $round = in_array($chosen, ['pie', 'doughnut'], true);
        $labels = array_column($drawn, 'label');
        $keys = array_map(fn ($r) => $r['other'] ? '' : (string) $r['key'], $drawn);
        $other = false;
        $measureLabel = count($result['measure']) > 1 ? __('statistics.series.all') : __('statistics.series.'.$result['measure'][0]);

        if ($crossed) {
            $columns = array_filter($result['column_totals'], fn ($n) => $n > 0);
            $fold = [];
            if (count($columns) > self::PARTS) {
                $ranked = $columns;
                arsort($ranked);
                $keep = array_flip(array_slice(array_keys($ranked), 0, self::PARTS - 1));
                $fold = array_keys(array_diff_key($columns, $keep));
                $columns = array_intersect_key($columns, $keep);
            }
            $datasets = [];
            foreach (array_keys($columns) as $column) {
                $datasets[] = ['label' => $result['columns'][$column], 'data' => array_map(fn ($r) => $r['values'][$column] ?? 0, $drawn)];
            }
            if ($fold !== []) {
                $datasets[] = ['label' => __('statistics.others', ['count' => count($fold)]), 'data' => array_map(fn ($r) => array_sum(array_intersect_key($r['values'], array_flip($fold))), $drawn)];
                $other = true;
            }
        } elseif ($round) {
            $data = array_column($drawn, 'value');
            if (count($drawn) > self::PARTS) {
                $ranked = $data;
                arsort($ranked);
                $keep = array_slice(array_keys($ranked), 0, self::PARTS - 1);
                sort($keep);
                $rest = array_diff_key($data, array_flip($keep));
                $labels = [...array_map(fn ($i) => $labels[$i], $keep), __('statistics.others', ['count' => count($rest)])];
                $keys = [...array_map(fn ($i) => $keys[$i], $keep), ''];
                $data = [...array_map(fn ($i) => $data[$i], $keep), array_sum($rest)];
                $other = true;
            }
            $datasets = [['label' => $measureLabel, 'data' => $data]];
        } else {
            $datasets = array_map(fn ($s) => ['label' => __('statistics.series.'.$s), 'data' => array_map(fn ($r) => $r['counts'][$s] ?? 0, $drawn)], $result['visible']);
            $other = $drawn !== [] && end($drawn)['other'] && count($datasets) === 1;
        }

        $horizontal = $chosen === 'hbar';
        $type = $horizontal ? ($crossed ? 'stacked' : 'bar') : $chosen;
        $height = $horizontal ? min(1600, max(300, count($labels) * ($crossed ? 36 : 22 * max(1, count($datasets)) + 14) + 80)) : 340;

        return [
            'type' => $type, 'chosen' => $chosen, 'available' => $available, 'labels' => $labels, 'keys' => $keys, 'datasets' => $datasets, 'horizontal' => $horizontal,
            'height' => $height, 'other' => $other && $round, 'othersLast' => $other && ! $round, 'measure' => $measureLabel,
        ];
    }
}
