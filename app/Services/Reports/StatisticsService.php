<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Reports\Search\SearchService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statistics (M4.17 part C, desktop `Statistics_Form`): the 15 axes over the account's permitted cities, one query per axis.
 *
 * Every project counts once. Series: all projects and funded projects (state in force Financed, Finished, Canceled or Closed, matched by
 * the state's English key, never its number). Axes 11-13 (donor, fund year, fund amount) have one series; axis 14 has ongoing and closed.
 * Beneficiary axes (parish, sex, marital status, age) count the project's owner (first beneficiary) for all four, a deliberate difference
 * from the desktop that counted every beneficiary for sex. Every filter of the search panel applies to every axis, plus request year and
 * fund year lists. Read only; needs applications.view in a city, export also reports.export there.
 */
final class StatisticsService
{
    /** key => [number, series]; series: 'both' (all and funded), 'single', 'life' (ongoing and closed). */
    public const AXES = [
        'status' => 0, 'fund_type' => 1, 'project_type' => 2, 'subtype' => 3, 'category' => 4, 'subcategory' => 5, 'partnership' => 6, 'parish' => 7,
        'sex' => 8, 'marital' => 9, 'age' => 10, 'donor' => 11, 'fund_year' => 12, 'fund_amount' => 13, 'sustainability' => 14,
    ];

    public const FUNDED = ['Financed', 'Finished', 'Canceled', 'Closed'];

    public const ONGOING = ['Financed', 'Finished'];

    public const CLOSED = ['Canceled', 'Cancelled', 'Closed'];

    /** Axes that map to a filter of the applications search (for the links on the numbers). */
    private const LINKS = ['status' => 'status', 'fund_type' => 'fund_type', 'project_type' => 'project_type', 'subtype' => 'subtype', 'category' => 'category', 'subcategory' => 'subcategory',
        'parish' => 'parish', 'marital' => 'marital', 'donor' => 'donor', 'age' => 'age', 'sex' => 'sex'];

    private const LOOKUPS = [
        'fund_type' => ['fund_types', 'p.fund_type_id'], 'project_type' => ['project_types', 'p.project_type_id'], 'subtype' => ['project_subtypes', 'p.project_subtype_id'],
        'category' => ['project_categories', 'p.project_category_id'], 'subcategory' => ['project_subcategories', 'p.project_subcategory_id'], 'donor' => ['donors', 'p.donor_id'],
        'parish' => ['parishes', 'pe.parish_id'], 'marital' => ['marital_statuses', 'pe.marital_status_id'],
    ];

    /** [from, to) bounds in US dollars of the fund amount buckets; the first two are "no rate" and "zero or less". */
    private const AMOUNT_BUCKETS = [[0, 1000], [1000, 2000], [2000, 3000], [3000, 4000], [4000, 5000], [5000, 6000]];

    /** [from, to) bounds in years of the sustainability buckets. */
    private const YEAR_BUCKETS = [[0, 1], [1, 2], [2, 3], [3, 4], [4, 5]];

    public function __construct(private SearchService $search, private AccountAuditTrail $audit) {}

    /** The filters of a statistics view: the search panel's, the cities and the two year lists. */
    public static function filters(array $input): array
    {
        $years = fn ($v) => array_values(array_unique(array_filter(array_map(fn ($y) => (is_int($y) || (is_string($y) && ctype_digit($y))) && (int) $y >= 1900 && (int) $y <= 2100 ? (int) $y : 0, array_slice((array) $v, 0, 60)))));

        return SearchService::filters($input) + ['request_year' => $years($input['request_year'] ?? []), 'fund_year' => $years($input['fund_year'] ?? [])];
    }

    /** @return array<int, array<string, true>> city id => capabilities held */
    private function held(WebAccount $account): array
    {
        return $this->search->held($account);
    }

    /**
     * Cities where the account may read the statistics (applications.view); `$export` also needs reports.export.
     *
     * @return Collection<int, object{id: int, code: string, name_ar: string, name_en: string}>
     */
    public function cities(WebAccount $account, bool $export = false): Collection
    {
        $held = $this->held($account);
        $need = $export ? ['applications.view', 'reports.export'] : ['applications.view'];

        return DB::table('cities')->orderBy('id')->get(['id', 'code', 'name_ar', 'name_en'])
            ->filter(fn ($c) => count(array_diff($need, array_keys($held[(int) $c->id] ?? []))) === 0)->values();
    }

    /** The page was opened: refused (403, audited) without any city, else audited once. */
    public function opened(WebAccount $account, string $axis): Collection
    {
        $cities = $this->cities($account);
        if ($cities->isEmpty() || ! isset(self::AXES[$axis])) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $this->audit->record($account->id, 'reports.statistics', 'succeeded', decision: 'axis:'.$axis);

        return $cities;
    }

    public function canExport(WebAccount $account): bool
    {
        return $this->cities($account, true)->isNotEmpty();
    }

    /** City ids in scope: the chosen ones among the permitted, else all permitted. @return list<int> */
    private function ids(Collection $permitted, array $filters): array
    {
        $all = $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $chosen = array_values(array_intersect($filters['cities'] ?? [], $all));

        return $chosen !== [] ? $chosen : $all;
    }

    /**
     * The numbers of one axis.
     *
     * @return array{axis: string, series: list<string>, rows: list<array{key: string, label: string, counts: array<string, int>, by_city: array<int, int>, link: ?array}>, totals: array<string, int>, cities: Collection, shown: list<int>}
     */
    public function axis(WebAccount $account, string $axis, array $filters, bool $export = false): array
    {
        abort_unless(isset(self::AXES[$axis]), 404);
        $permitted = $this->cities($account, $export);
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $filters = self::filters($filters);
        $ids = $this->ids($permitted, $filters);
        $rows = $this->projects($axis, $ids, $filters);
        $series = match ($axis) {
            'donor', 'fund_year', 'fund_amount' => ['all'],
            'sustainability' => ['ongoing', 'closed'],
            default => ['all', 'funded'],
        };

        $groups = [];   // bucket key => ['counts' => [series => n], 'by_city' => [city => n]]
        foreach ($rows as $r) {
            $key = $this->bucket($axis, $r);
            if ($key === null) {
                continue;
            }
            $in = match ($axis) {
                'sustainability' => in_array($r->state_key, self::ONGOING, true) ? ['ongoing'] : (in_array($r->state_key, self::CLOSED, true) ? ['closed'] : []),
                'donor', 'fund_year', 'fund_amount' => ['all'],
                default => in_array($r->state_key, self::FUNDED, true) ? ['all', 'funded'] : ['all'],
            };
            foreach ($in as $s) {
                $groups[$key]['counts'][$s] = ($groups[$key]['counts'][$s] ?? 0) + 1;
            }
            $groups[$key]['by_city'][(int) $r->city_id] = ($groups[$key]['by_city'][(int) $r->city_id] ?? 0) + 1;
        }

        $labels = $this->labels($axis, $groups);
        $out = [];
        $totals = array_fill_keys($series, 0);
        foreach ($labels as $key => $label) {
            $counts = [];
            foreach ($series as $s) {
                $counts[$s] = (int) ($groups[$key]['counts'][$s] ?? 0);
                $totals[$s] += $counts[$s];
            }
            $out[] = ['key' => (string) $key, 'label' => $label, 'counts' => $counts, 'by_city' => $groups[$key]['by_city'] ?? [], 'link' => $this->link($axis, (string) $key, $filters, $ids)];
        }

        return ['axis' => $axis, 'series' => $series, 'rows' => $out, 'totals' => $totals, 'cities' => $permitted->filter(fn ($c) => in_array((int) $c->id, $ids, true))->values(), 'shown' => $ids];
    }

    /** One row per project in scope with the values the axis buckets by. */
    private function projects(string $axis, array $cityIds, array $filters): iterable
    {
        $current = DB::table('project_state_history')->where('is_current', true)->whereNull('deleted_at')->groupBy('project_id')->select('project_id', DB::raw('MAX(id) as history_id'));
        $q = DB::table('projects as p')->join('cities as ci', 'ci.id', '=', 'p.city_id')
            ->joinSub($current, 'cur', 'cur.project_id', '=', 'p.id')
            ->join('project_state_history as cs', 'cs.id', '=', 'cur.history_id')
            ->join('project_states as st', 'st.id', '=', 'cs.project_state_id')
            ->whereIn('p.city_id', $cityIds === [] ? [0] : $cityIds)->whereNull('p.deleted_at')
            ->select('p.id', 'p.city_id', 'st.id as state_id', 'st.name_en as state_key');
        ProjectReportFilters::apply($q, $filters);
        $this->years($q, $filters);

        if (in_array($axis, ['parish', 'sex', 'marital', 'age'], true)) {
            // The project's owner: the first beneficiary (one per project, whatever the number of partners).
            $owner = DB::table('project_applicants')->whereNull('deleted_at')->where('role', ProjectReportFilters::BENEFICIARY_ROLE)->groupBy('project_id')->select('project_id', DB::raw('MIN(id) as applicant_id'));
            $q->leftJoinSub($owner, 'ow', 'ow.project_id', '=', 'p.id')->leftJoin('project_applicants as oa', 'oa.id', '=', 'ow.applicant_id')
                ->leftJoin('people as pe', fn ($j) => $j->on('pe.id', '=', 'oa.person_id')->whereNull('pe.deleted_at'))
                ->addSelect('pe.parish_id', 'pe.marital_status_id', 'pe.sex', 'pe.birth_date', 'p.request_date');
        }
        if (in_array($axis, ['fund_year', 'fund_amount', 'sustainability'], true)) {
            // One loan per project; a project without a loan has no fund year or amount.
            $q->join('loans as l', fn ($j) => $j->on('l.project_id', '=', 'p.id')->whereNull('l.deleted_at'))
                ->addSelect('l.taken_on', 'l.amount', 'l.currency', 'l.exchange_rate', 'cs.changed_on');
        }
        match ($axis) {
            'partnership' => $q->addSelect('p.partnership'),
            'fund_type' => $q->addSelect('p.fund_type_id as v'),
            'project_type' => $q->addSelect('p.project_type_id as v'),
            'subtype' => $q->addSelect('p.project_subtype_id as v'),
            'category' => $q->addSelect('p.project_category_id as v'),
            'subcategory' => $q->addSelect('p.project_subcategory_id as v'),
            'donor' => $q->addSelect('p.donor_id as v'),
            default => null,
        };

        return $q->orderBy('p.id')->cursor();
    }

    private function years(Builder $q, array $filters): void
    {
        foreach (['request_year' => 'p.request_date'] as $key => $column) {
            if ($filters[$key] !== []) {
                $q->where(function ($or) use ($filters, $key, $column) {
                    foreach ($filters[$key] as $y) {
                        $or->orWhere(fn ($r) => $r->where($column, '>=', sprintf('%04d-01-01', $y))->where($column, '<', sprintf('%04d-01-01', $y + 1)));
                    }
                });
            }
        }
        if ($filters['fund_year'] !== []) {
            $q->whereExists(function ($e) use ($filters) {
                $e->selectRaw('1')->from('loans as fy')->whereColumn('fy.project_id', 'p.id')->whereNull('fy.deleted_at')->where(function ($or) use ($filters) {
                    foreach ($filters['fund_year'] as $y) {
                        $or->orWhere(fn ($r) => $r->where('fy.taken_on', '>=', sprintf('%04d-01-01', $y))->where('fy.taken_on', '<', sprintf('%04d-01-01', $y + 1)));
                    }
                });
            });
        }
    }

    /** The bucket a project falls in on the axis, or null when it has no value there (a project without a loan on the loan axes, without a donor...). */
    private function bucket(string $axis, object $r): ?string
    {
        switch ($axis) {
            case 'status':
                return (string) $r->state_id;
            case 'partnership':
                return (string) max(1, (int) $r->partnership);
            case 'sex':
                return in_array($r->sex, ['male', 'female'], true) ? $r->sex : 'none';
            case 'age':
                if ($r->birth_date === null || $r->request_date === null) {
                    return 'none';
                }
                $age = (int) CarbonImmutable::parse($r->birth_date)->diffInYears(CarbonImmutable::parse($r->request_date));
                foreach ($this->ageRanges() as $range) {
                    if ($age >= (int) $range->min_age && $age < (int) $range->max_age) {
                        return (string) $range->id;
                    }
                }

                return 'none';
            case 'fund_year':
                return $r->taken_on === null ? null : substr((string) $r->taken_on, 0, 4);
            case 'fund_amount':
                $usd = $this->usd($r);
                if ($usd === null) {
                    return 'norate';
                }
                if ($usd <= 0) {
                    return 'zero';
                }
                foreach (self::AMOUNT_BUCKETS as $i => [$from, $to]) {
                    if ($usd > $from && $usd <= $to) {
                        return 'a'.$i;
                    }
                }

                return 'over';
            case 'sustainability':
                $years = $this->years_between($r);
                if ($years === null) {
                    return null;
                }
                foreach (self::YEAR_BUCKETS as $i => [$from, $to]) {
                    if ($years >= $from && $years < $to) {
                        return 'y'.$i;
                    }
                }

                return 'ymore';
            case 'parish':
                return $r->parish_id === null ? 'none' : (string) $r->parish_id;
            case 'marital':
                return $r->marital_status_id === null ? 'none' : (string) $r->marital_status_id;
            default:
                return $r->v === null ? 'none' : (string) $r->v;
        }
    }

    private function usd(object $r): ?float
    {
        if ($r->amount === null) {
            return null;
        }
        if ($r->currency === 'USD') {
            return round((float) $r->amount, 2);
        }
        $rate = (float) $r->exchange_rate;

        return in_array($r->currency, ['SYP', 'SP'], true) && $rate > 0 ? round((float) $r->amount / $rate, 2) : null;
    }

    /** Years the loan has lasted: to the closing date for a cancelled or closed project, to today for a financed or finished one. */
    private function years_between(object $r): ?float
    {
        if ($r->taken_on === null) {
            return null;
        }
        $end = match (true) {
            in_array($r->state_key, self::CLOSED, true) => $r->changed_on === null ? null : CarbonImmutable::parse($r->changed_on),
            in_array($r->state_key, self::ONGOING, true) => CarbonImmutable::today(),
            default => null,
        };

        return $end === null ? null : round(CarbonImmutable::parse($r->taken_on)->diffInDays($end, false) / 365.25, 2);
    }

    /** @return Collection<int, object> */
    private function ageRanges(): Collection
    {
        static $ranges = null;

        return $ranges ??= DB::table('age_ranges')->orderBy('min_age')->get(['id', 'name_ar', 'name_en', 'min_age', 'max_age']);
    }

    /**
     * The buckets of the axis in display order with their labels. Lookup axes list every value of the list (zero counts shown, as the desktop
     * did) plus "not recorded" when some project has none; computed axes list their fixed buckets or the years found.
     *
     * @return array<string, string>
     */
    private function labels(string $axis, array $groups): array
    {
        $en = app()->getLocale() === 'en';
        $pick = fn ($r) => (string) ($en && ($r->name_en ?? '') !== '' ? $r->name_en : $r->name_ar);
        $none = __('statistics.not_recorded');
        $out = [];
        if ($axis === 'status') {
            foreach (DB::table('project_states')->orderBy('sort_order')->orderBy('id')->get() as $r) {
                $out[(string) $r->id] = $pick($r);
            }

            return $out;
        }
        if (isset(self::LOOKUPS[$axis])) {
            $table = self::LOOKUPS[$axis][0];
            foreach (DB::table($table)->orderBy('sort_order')->orderBy('id')->get() as $r) {
                if ($r->is_active || isset($groups[(string) $r->id])) {
                    $out[(string) $r->id] = $pick($r);
                }
            }
        } else {
            switch ($axis) {
                case 'partnership':
                    $max = max([1, ...array_map('intval', array_keys($groups))]);
                    for ($n = 1; $n <= $max; $n++) {
                        $out[(string) $n] = $n === 1 ? __('statistics.individual') : __('statistics.partners', ['count' => $n]);
                    }
                    break;
                case 'sex':
                    $out = ['male' => __('search.sex.male'), 'female' => __('search.sex.female')];
                    break;
                case 'age':
                    foreach ($this->ageRanges() as $r) {
                        $out[(string) $r->id] = $pick($r);
                    }
                    break;
                case 'fund_year':
                    $years = array_keys($groups);
                    sort($years);
                    foreach ($years as $y) {
                        $out[(string) $y] = (string) $y;
                    }

                    return $out;
                case 'fund_amount':
                    $out['norate'] = __('statistics.no_rate');
                    $out['zero'] = __('statistics.zero_or_less');
                    foreach (self::AMOUNT_BUCKETS as $i => [$from, $to]) {
                        $out['a'.$i] = ($from + 1).' - '.$to;
                    }
                    $out['over'] = __('statistics.over', ['value' => 6000]);

                    return $out;
                case 'sustainability':
                    foreach (self::YEAR_BUCKETS as $i => [$from, $to]) {
                        $out['y'.$i] = $i === 0 ? __('statistics.under', ['value' => 1]) : $from.' - '.$to;
                    }
                    $out['ymore'] = __('statistics.over', ['value' => 5]);

                    return $out;
            }
        }
        if (isset($groups['none'])) {
            $out['none'] = $none;
        }

        return $out;
    }

    /** The applications search with this bucket and the same filters, or null when the axis has no search filter or the account cannot search applications. */
    private function link(string $axis, string $key, array $filters, array $cityIds): ?array
    {
        if (! isset(self::LINKS[$axis]) || $key === 'none') {
            return null;
        }
        $f = array_filter(ProjectReportFilters::clean($filters), fn ($v, $k) => $k !== 'q' && $v !== [] && $v !== '', ARRAY_FILTER_USE_BOTH);
        $filter = self::LINKS[$axis];
        $f[$filter] = $filter === 'sex' ? [$key] : [(int) $key];

        return ['mode' => 'applications', 'city' => $cityIds, 'f' => $f];
    }

    /** @return list<list<mixed>> rows of an export: label, then each series, share, then the cities */
    public function exportRows(array $result): array
    {
        $lines = [];
        $series = $result['series'];
        $first = $series[0];
        $total = max(1, $result['totals'][$first]);
        foreach ($result['rows'] as $row) {
            $cells = [$row['label']];
            foreach ($series as $s) {
                $cells[] = $row['counts'][$s];
            }
            $cells[] = round($row['counts'][$first] / $total * 100, 1);
            if (count($result['cities']) > 1) {
                foreach ($result['cities'] as $c) {
                    $cells[] = $row['by_city'][(int) $c->id] ?? 0;
                }
            }
            $lines[] = $cells;
        }
        $cells = [__('statistics.total')];
        foreach ($series as $s) {
            $cells[] = $result['totals'][$s];
        }
        $cells[] = 100.0;
        if (count($result['cities']) > 1) {
            foreach ($result['cities'] as $c) {
                $cells[] = array_sum(array_map(fn ($r) => $r['by_city'][(int) $c->id] ?? 0, $result['rows']));
            }
        }
        $lines[] = $cells;

        return $lines;
    }

    /** @return list<string> */
    public function exportHeadings(array $result): array
    {
        $en = app()->getLocale() === 'en';
        $h = [__('statistics.axes.'.$result['axis'])];
        foreach ($result['series'] as $s) {
            $h[] = __('statistics.series.'.$s);
        }
        $h[] = __('statistics.share');
        if (count($result['cities']) > 1) {
            foreach ($result['cities'] as $c) {
                $h[] = $en ? $c->name_en : $c->name_ar;
            }
        }

        return $h;
    }

    /**
     * An export: authorised (reports.export + applications.view per city) and audited before it returns, so before the first byte.
     *
     * @return array{0: list<string>, 1: list<list<mixed>>, 2: list<int>}
     */
    public function export(WebAccount $account, string $axis, array $filters): array
    {
        $result = $this->axis($account, $axis, $filters, true);
        $rows = $this->exportRows($result);
        $this->audit->record($account->id, 'reports.exported', 'succeeded', decision: 'statistics:'.$axis.'/rows:'.count($rows));
        $numeric = range(1, count($result['series']) + 1 + (count($result['cities']) > 1 ? count($result['cities']) : 0));

        return [$this->exportHeadings($result), $rows, $numeric];
    }
}
