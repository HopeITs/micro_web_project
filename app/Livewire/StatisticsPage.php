<?php

namespace App\Livewire;

use App\Models\WebAccount;
use App\Services\Reports\ProjectReportFilters;
use App\Services\Reports\Search\SearchService;
use App\Services\Reports\StatisticsService;
use App\Services\Reports\TableExport;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Statistics (M4.17 part C): one of the 15 axes as a table, a chart and an export, over the permitted cities, with the search panel's filters.
 * The axis, cities, series switch, chart type and lookup/date filters may be in the URL (ids and keys only); the export goes through a
 * single-use token. Read only; StatisticsService checks applications.view (and reports.export for the export) per city on every call.
 */
class StatisticsPage extends Component
{
    public const CHARTS = ['bar', 'stacked', 'line', 'pie', 'doughnut'];

    #[Url(as: 'axis', except: 'status')]
    public string $axis = 'status';

    /** @var list<int> */
    #[Url(as: 'city', except: [])]
    public array $cities = [];

    /** @var array<string, mixed> */
    #[Url(as: 'f', except: [])]
    public array $filters = [];

    /** both | all | funded */
    #[Url(as: 'show', except: 'both')]
    public string $show = 'both';

    #[Url(as: 'chart', except: 'bar')]
    public string $chart = 'bar';

    public function mount(StatisticsService $statistics): void
    {
        $this->axis = isset(StatisticsService::AXES[$this->axis]) ? $this->axis : 'status';
        $this->show = in_array($this->show, ['both', 'all', 'funded'], true) ? $this->show : 'both';
        $this->chart = in_array($this->chart, self::CHARTS, true) ? $this->chart : 'bar';
        $this->cities = array_values(array_filter(array_map('intval', $this->cities)));
        $this->filters = $this->kept();
        $statistics->opened($this->account(), $this->axis);
    }

    private function account(): WebAccount
    {
        return auth()->user();
    }

    /** @return array<string, mixed> the filters worth keeping (cleaned, nothing empty, no text) */
    private function kept(): array
    {
        $clean = StatisticsService::filters($this->filters);

        return array_filter($clean, fn ($v, $k) => ! in_array($k, ['q', 'cities', 'last'], true) && $v !== [] && $v !== '', ARRAY_FILTER_USE_BOTH);
    }

    private function state(): array
    {
        return ['cities' => $this->cities] + $this->filters;
    }

    public function updatedAxis(StatisticsService $statistics): void
    {
        $this->axis = isset(StatisticsService::AXES[$this->axis]) ? $this->axis : 'status';
        $statistics->opened($this->account(), $this->axis);
    }

    public function toggleCity(int $city): void
    {
        $this->cities = in_array($city, $this->cities, true) ? array_values(array_diff($this->cities, [$city])) : [...$this->cities, $city];
    }

    public function clearFilters(): void
    {
        $this->filters = [];
    }

    public function exportTable(string $format, StatisticsService $statistics): void
    {
        abort_unless(in_array($format, TableExport::FORMATS, true), 422);
        abort_unless($statistics->canExport($this->account()), 403);
        $token = Str::random(40);
        Cache::put('statistics-export:'.$token, ['account' => $this->account()->id, 'axis' => $this->axis, 'format' => $format, 'state' => $this->state()], now()->addMinutes(5));
        $this->redirect(route('statistics.export', ['locale' => app()->getLocale(), 'token' => $token]));
    }

    public function render(StatisticsService $statistics, SearchService $search): View
    {
        $account = $this->account();
        $this->axis = isset(StatisticsService::AXES[$this->axis]) ? $this->axis : 'status';
        $result = $statistics->axis($account, $this->axis, $this->state());
        $series = $result['series'];
        $visible = $this->show === 'all' ? array_intersect($series, ['all']) : ($this->show === 'funded' ? array_intersect($series, ['funded']) : $series);
        $visible = $visible === [] ? $series : array_values($visible);
        $first = $visible[0];
        $total = max(1, $result['totals'][$first]);
        $canSearch = isset($search->available($account)['applications']);
        $locale = app()->getLocale();
        foreach ($result['rows'] as &$row) {
            $row['share'] = round($row['counts'][$first] / $total * 100, 1);
            $row['url'] = $canSearch && $row['link'] ? route('search.index', ['locale' => $locale, 'mode' => 'applications', 'city' => $row['link']['city'], 'f' => $row['link']['f']]) : null;
        }
        unset($row);
        // The chart leaves out buckets nobody falls in, so it stays readable on long lists.
        $drawn = array_values(array_filter($result['rows'], fn ($r) => array_sum(array_map(fn ($s) => $r['counts'][$s], $visible)) > 0));
        $charts = [
            'labels' => array_map(fn ($r) => $r['label'], $drawn),
            'datasets' => array_map(fn ($s) => ['label' => __('statistics.series.'.$s), 'data' => array_map(fn ($r) => $r['counts'][$s], $drawn)], $visible),
        ];

        return view('livewire.statistics-page', [
            'result' => $result, 'visible' => $visible, 'charts' => $charts, 'shown' => $result['cities'], 'cityOptions' => $statistics->cities($account),
            'canExport' => $statistics->canExport($account), 'options' => ProjectReportFilters::options($result['shown']), 'years' => $this->years($result['shown']),
            'activeFilters' => count($this->filters),
        ]);
    }

    /** @return array{request: list<int>, fund: list<int>} the years found in the cities in scope */
    private function years(array $cityIds): array
    {
        $ids = $cityIds === [] ? [0] : $cityIds;
        $year = fn ($column) => substr((string) $column, 0, 4);
        $request = DB::table('projects')->whereIn('city_id', $ids)->whereNull('deleted_at')->whereNotNull('request_date')->distinct()->pluck('request_date')->map($year)->unique()->sortDesc()->values()->map(fn ($y) => (int) $y)->all();
        $fund = DB::table('loans')->whereIn('city_id', $ids)->whereNull('deleted_at')->whereNotNull('taken_on')->distinct()->pluck('taken_on')->map($year)->unique()->sortDesc()->values()->map(fn ($y) => (int) $y)->all();

        return ['request' => $request, 'fund' => $fund];
    }
}
