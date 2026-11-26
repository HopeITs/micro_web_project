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
 * from the desktop that counted every beneficiary for sex. Every filter of the search panel applies to every axis, the request year and
 * fund year lists included. Read only; needs applications.view in a city, export also reports.export there.
 *
 * An axis may be crossed with a second one (or with the city): the same pass over the projects fills both, so a row of the cross table
 * always adds up to the row of the single axis. A project with no value on the second axis is shown in its own "does not apply" column.
 * shape() only arranges the numbers for display and export (series shown, order, the largest N with the rest added up); it never changes them.
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

    /** Axes whose buckets have a natural order (a line may join them). */
    public const ORDERED = ['partnership', 'age', 'fund_year', 'fund_amount', 'sustainability'];

    /** The second dimension "per city", beside any other axis. */
    public const BY_CITY = 'city';

    public const SORTS = ['default', 'desc', 'asc', 'name'];

    /** "Largest N, the rest together": 0 = every bucket. */
    public const TOPS = [0, 5, 10, 15, 20];

    /** The column of a cross table for projects with no value on the second axis, and the row that adds up what "top N" leaves out. */
    public const NOT_APPLICABLE = 'na';

    public const OTHERS = 'others';

    /** Axes that are a list filter of the same name. */
    private const LIST_AXES = ['status', 'fund_type', 'project_type', 'subtype', 'category', 'subcategory', 'parish', 'marital', 'donor', 'age', 'sex', 'fund_year'];

    private const OWNER_AXES = ['parish', 'sex', 'marital', 'age'];

    private const LOAN_AXES = ['fund_year', 'fund_amount', 'sustainability'];

    private const PROJECT_AXES = ['fund_type' => 'fund_type_id', 'project_type' => 'project_type_id', 'subtype' => 'project_subtype_id', 'category' => 'project_category_id', 'subcategory' => 'project_subcategory_id', 'donor' => 'donor_id'];

    private const LOOKUPS = [
        'fund_type' => 'fund_types', 'project_type' => 'project_types', 'subtype' => 'project_subtypes', 'category' => 'project_categories', 'subcategory' => 'project_subcategories',
        'donor' => 'donors', 'parish' => 'parishes', 'marital' => 'marital_statuses',
    ];

    /** [from, to) bounds in US dollars of the fund amount buckets; the first two are "no rate" and "zero or less". */
    private const AMOUNT_BUCKETS = [[0, 1000], [1000, 2000], [2000, 3000], [3000, 4000], [4000, 5000], [5000, 6000]];

    /** [from, to) bounds in years of the sustainability buckets. */
    private const YEAR_BUCKETS = [[0, 1], [1, 2], [2, 3], [3, 4], [4, 5]];

    private ?Collection $ageRanges = null;

    public function __construct(private SearchService $search, private AccountAuditTrail $audit) {}

    /** The filters of a statistics view: the search panel's (year lists included) and the cities. */
    public static function filters(array $input): array
    {
        return SearchService::filters($input);
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

    /** @return Collection<int, object> the permitted cities, refused (403, audited) when there is none */
    private function permitted(WebAccount $account, bool $export = false): Collection
    {
        $permitted = $this->cities($account, $export);
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }

        return $permitted;
    }

    /** City ids in scope: the chosen ones among the permitted, else all permitted. @return list<int> */
    private function ids(Collection $permitted, array $filters): array
    {
        $all = $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $chosen = array_values(array_intersect($filters['cities'] ?? [], $all));

        return $chosen !== [] ? $chosen : $all;
    }

    /** One row per project (with its state in force) of these cities that passes the filters: p, ci, cur, cs, st. */
    private function scope(array $cityIds, array $filters): Builder
    {
        $current = DB::table('project_state_history')->where('is_current', true)->whereNull('deleted_at')->groupBy('project_id')->select('project_id', DB::raw('MAX(id) as history_id'));
        $q = DB::table('projects as p')->join('cities as ci', 'ci.id', '=', 'p.city_id')
            ->joinSub($current, 'cur', 'cur.project_id', '=', 'p.id')
            ->join('project_state_history as cs', 'cs.id', '=', 'cur.history_id')
            ->join('project_states as st', 'st.id', '=', 'cs.project_state_id')
            ->whereIn('p.city_id', $cityIds === [] ? [0] : $cityIds)->whereNull('p.deleted_at');

        return ProjectReportFilters::apply($q, $filters);
    }

    /**
     * The headline numbers under the same filters as the axes: every project, the funded ones, and among them the ongoing and the closed
     * ones by the state in force (the sustainability axis counts only those of them that have a loan date).
     *
     * @return array{all: int, funded: int, ongoing: int, closed: int}
     */
    public function summary(WebAccount $account, array $filters): array
    {
        $filters = self::filters($filters);
        $ids = $this->ids($this->permitted($account), $filters);
        $out = ['all' => 0, 'funded' => 0, 'ongoing' => 0, 'closed' => 0];
        foreach ($this->scope($ids, $filters)->groupBy('st.name_en')->selectRaw('st.name_en as state_key, COUNT(*) as n')->get() as $row) {
            $n = (int) $row->n;
            $out['all'] += $n;
            $out['funded'] += in_array($row->state_key, self::FUNDED, true) ? $n : 0;
            $out['ongoing'] += in_array($row->state_key, self::ONGOING, true) ? $n : 0;
            $out['closed'] += in_array($row->state_key, self::CLOSED, true) ? $n : 0;
        }

        return $out;
    }

    /** @return array<int|string, int> projects per value of one filter, under every other filter (permitted cities only) */
    public function facetCounts(WebAccount $account, string $key, array $filters): array
    {
        $filters = self::filters($filters);
        $ids = $this->ids($this->permitted($account), $filters);

        return app(ReportFacets::class)->projects($key, $filters, fn (array $without) => $this->scope($ids, $without)->select('p.id'));
    }

    /**
     * The numbers of one axis, optionally crossed with a second one (`$by`: another axis, or `city`).
     *
     * @return array{axis: string, by: ?string, series: list<string>, rows: list<array<string, mixed>>, totals: array<string, int>, columns: array<string, string>, cities: Collection, shown: list<int>}
     */
    public function axis(WebAccount $account, string $axis, array $filters, bool $export = false, ?string $by = null): array
    {
        abort_unless(isset(self::AXES[$axis]), 404);
        $by = self::validBy($axis, $by);
        $permitted = $this->permitted($account, $export);
        $filters = self::filters($filters);
        $ids = $this->ids($permitted, $filters);
        $series = match ($axis) {
            'donor', 'fund_year', 'fund_amount' => ['all'],
            'sustainability' => ['ongoing', 'closed'],
            default => ['all', 'funded'],
        };

        $groups = [];   // bucket key => ['counts' => [series => n], 'by_city' => [city => n], 'city_counts' => [...], 'cells' => [column => [series => n]]]
        $found = [];    // second-axis keys met
        foreach ($this->projects($axis, $by, $ids, $filters) as $r) {
            $key = $this->bucket($axis, $r);
            if ($key === null) {
                continue;
            }
            $in = match ($axis) {
                'sustainability' => in_array($r->state_key, self::ONGOING, true) ? ['ongoing'] : (in_array($r->state_key, self::CLOSED, true) ? ['closed'] : []),
                'donor', 'fund_year', 'fund_amount' => ['all'],
                default => in_array($r->state_key, self::FUNDED, true) ? ['all', 'funded'] : ['all'],
            };
            $city = (int) $r->city_id;
            $column = null;
            if ($by !== null) {
                $column = $by === self::BY_CITY ? (string) $city : ($this->bucket($by, $r) ?? self::NOT_APPLICABLE);
                $found[$column] = true;
            }
            foreach ($in as $s) {
                $groups[$key]['counts'][$s] = ($groups[$key]['counts'][$s] ?? 0) + 1;
                $groups[$key]['city_counts'][$s][$city] = ($groups[$key]['city_counts'][$s][$city] ?? 0) + 1;
                if ($column !== null) {
                    $groups[$key]['cells'][$column][$s] = ($groups[$key]['cells'][$column][$s] ?? 0) + 1;
                }
            }
            $groups[$key]['by_city'][$city] = ($groups[$key]['by_city'][$city] ?? 0) + 1;
        }

        $shownCities = $permitted->filter(fn ($c) => in_array((int) $c->id, $ids, true))->values();
        $columns = $by === null ? [] : $this->columns($by, $found, $shownCities);
        $labels = $this->labels($axis, $groups);
        $out = [];
        $totals = array_fill_keys($series, 0);
        foreach ($labels as $key => $label) {
            $counts = [];
            foreach ($series as $s) {
                $counts[$s] = (int) ($groups[$key]['counts'][$s] ?? 0);
                $totals[$s] += $counts[$s];
            }
            $out[] = [
                'key' => (string) $key, 'label' => $label, 'counts' => $counts, 'by_city' => $groups[$key]['by_city'] ?? [], 'city_counts' => $groups[$key]['city_counts'] ?? [],
                'cells' => $groups[$key]['cells'] ?? [], 'link' => $this->link($axis, (string) $key, $filters, $ids),
            ];
        }

        return ['axis' => $axis, 'by' => $by, 'series' => $series, 'rows' => $out, 'totals' => $totals, 'columns' => $columns, 'cities' => $shownCities, 'shown' => $ids];
    }

    /** The second dimension allowed beside an axis, or null. */
    public static function validBy(string $axis, ?string $by): ?string
    {
        return $by !== null && $by !== $axis && ($by === self::BY_CITY || isset(self::AXES[$by])) ? $by : null;
    }

    /** One row per project in scope with the values the axes bucket by. */
    private function projects(string $axis, ?string $by, array $cityIds, array $filters): iterable
    {
        $axes = array_values(array_filter([$axis, $by]));
        $q = $this->scope($cityIds, $filters)->select('p.id', 'p.city_id', 'st.id as state_id', 'st.name_en as state_key', 'cs.changed_on', 'p.partnership', 'p.request_date');
        foreach (self::PROJECT_AXES as $key => $column) {
            if (in_array($key, $axes, true)) {
                $q->addSelect('p.'.$column.' as v_'.$key);
            }
        }
        if (array_intersect($axes, self::OWNER_AXES) !== []) {
            // The project's owner: the first beneficiary (one per project, whatever the number of partners).
            $owner = DB::table('project_applicants')->whereNull('deleted_at')->where('role', ProjectReportFilters::BENEFICIARY_ROLE)->groupBy('project_id')->select('project_id', DB::raw('MIN(id) as applicant_id'));
            $q->leftJoinSub($owner, 'ow', 'ow.project_id', '=', 'p.id')->leftJoin('project_applicants as oa', 'oa.id', '=', 'ow.applicant_id')
                ->leftJoin('people as pe', fn ($j) => $j->on('pe.id', '=', 'oa.person_id')->whereNull('pe.deleted_at'))
                ->addSelect('pe.parish_id', 'pe.marital_status_id', 'pe.sex', 'pe.birth_date');
        }
        if (array_intersect($axes, self::LOAN_AXES) !== []) {
            // One loan per project (the first); a project without a loan has no fund year, amount or duration.
            $first = DB::table('loans')->whereNull('deleted_at')->groupBy('project_id')->select('project_id', DB::raw('MIN(id) as loan_id'));
            $q->leftJoinSub($first, 'ln', 'ln.project_id', '=', 'p.id')->leftJoin('loans as l', 'l.id', '=', 'ln.loan_id')
                ->addSelect('l.id as loan_id', 'l.taken_on', 'l.amount', 'l.currency', 'l.exchange_rate');
        }

        return $q->orderBy('p.id')->cursor();
    }

    /** The bucket a project falls in on the axis, or null when it has no value there (a project without a loan on the loan axes...). */
    private function bucket(string $axis, object $r): ?string
    {
        if (in_array($axis, self::LOAN_AXES, true) && $r->loan_id === null) {
            return null;
        }
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
                $years = $this->yearsBetween($r);
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
                $value = $r->{'v_'.$axis};

                return $value === null ? 'none' : (string) $value;
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
    private function yearsBetween(object $r): ?float
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
        return $this->ageRanges ??= DB::table('age_ranges')->orderBy('min_age')->get(['id', 'name_ar', 'name_en', 'min_age', 'max_age']);
    }

    /** The columns of a cross table: the cities in scope, or the buckets of the second axis that some project falls in. @return array<string, string> */
    private function columns(string $by, array $found, Collection $cities): array
    {
        $en = app()->getLocale() === 'en';
        if ($by === self::BY_CITY) {
            $out = [];
            foreach ($cities as $c) {
                $out[(string) $c->id] = (string) ($en ? $c->name_en : $c->name_ar);
            }

            return $out;
        }
        $keys = array_diff_key($found, [self::NOT_APPLICABLE => true]);
        $out = array_filter($this->labels($by, $keys), fn ($key) => isset($found[$key]), ARRAY_FILTER_USE_KEY);
        if (isset($found[self::NOT_APPLICABLE])) {
            $out[self::NOT_APPLICABLE] = __('statistics.not_applicable');
        }

        return $out;
    }

    /**
     * The buckets of the axis in display order with their labels. Lookup axes list every value of the list (zero counts shown, as the desktop
     * did) plus "not recorded" when some project has none; computed axes list their fixed buckets or the years found.
     *
     * @return array<int|string, string>
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
            foreach (DB::table(self::LOOKUPS[$axis])->orderBy('sort_order')->orderBy('id')->get() as $r) {
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

    /**
     * The filters narrowed to one bucket of an axis (a press on a bar), or null when no filter stands for exactly that bucket: "not
     * recorded", the sustainability buckets, and the partnership buckets (the axis counts a project with no partner number as
     * individual, the "individual" filter takes the number 1 only). A filter already set is narrowed, never widened.
     *
     * @return array<string, mixed>|null
     */
    public static function narrow(array $filters, string $axis, string $key): ?array
    {
        $f = array_filter(ProjectReportFilters::clean($filters), fn ($v, $k) => $k !== 'q' && $v !== [] && $v !== '', ARRAY_FILTER_USE_BOTH);
        $name = $axis;
        if (in_array($axis, self::LIST_AXES, true)) {
            if ($axis === 'sex' ? ! in_array($key, ['male', 'female'], true) : preg_match('/\A[1-9]\d{0,17}\z/', $key) !== 1) {
                return null;
            }
            $f[$axis] = [$axis === 'sex' ? $key : (int) $key];
        } elseif ($axis === 'fund_amount') {
            if (preg_match('/\Aa(\d)\z/', $key, $m) === 1 && isset(self::AMOUNT_BUCKETS[(int) $m[1]])) {
                [$from, $to] = self::AMOUNT_BUCKETS[(int) $m[1]];
                [$from, $to] = [$from + 0.01, (float) $to];
            } elseif ($key === 'over') {
                [$from, $to] = [self::AMOUNT_BUCKETS[count(self::AMOUNT_BUCKETS) - 1][1] + 0.01, null];
            } elseif ($key === 'zero') {
                [$from, $to] = [null, 0.0];
            } else {
                return null;
            }
            if ($from !== null) {
                $f['usd_from'] = (string) max($from, (float) ($f['usd_from'] ?? $from));
            }
            if ($to !== null) {
                $f['usd_to'] = (string) min($to, (float) ($f['usd_to'] ?? $to));
            }
        } else {
            return null;
        }
        if (isset($f['not'])) {
            $f['not'] = array_values(array_diff($f['not'], [$name]));
            if ($f['not'] === []) {
                unset($f['not']);
            }
        }

        return $f;
    }

    /**
     * The filters narrowed to one series (funded, ongoing, closed: a set of states), or null when no project could be in both.
     *
     * @return array<string, mixed>|null
     */
    public function narrowSeries(array $filters, string $series): ?array
    {
        $f = array_filter(ProjectReportFilters::clean($filters), fn ($v, $k) => $k !== 'q' && $v !== [] && $v !== '', ARRAY_FILTER_USE_BOTH);
        $names = ['funded' => self::FUNDED, 'ongoing' => self::ONGOING, 'closed' => self::CLOSED][$series] ?? null;
        if ($names === null) {
            return $f;
        }
        $states = DB::table('project_states')->whereIn('name_en', $names)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $chosen = $f['status'] ?? [];
        if ($chosen !== []) {
            $states = in_array('status', $f['not'] ?? [], true) ? array_values(array_diff($states, $chosen)) : array_values(array_intersect($states, $chosen));
        }
        if ($states === []) {
            return null;
        }
        $f['status'] = $states;
        $f['not'] = array_values(array_diff($f['not'] ?? [], ['status']));
        if ($f['not'] === []) {
            unset($f['not']);
        }

        return $f;
    }

    /** The applications search with this bucket and the same filters, or null when no search filter stands for the bucket. */
    private function link(string $axis, string $key, array $filters, array $cityIds): ?array
    {
        $f = self::narrow($filters, $axis, $key);

        return $f === null ? null : ['mode' => 'applications', 'city' => $cityIds, 'f' => $f];
    }

    /**
     * The numbers arranged as they are shown and exported: the series chosen, the order, the largest N with the rest added up, empty
     * buckets left out. `measure` is the series whose sum ranks the rows and fills the cross table and the city columns.
     *
     * @param  array{show?: string, sort?: string, top?: int, nozero?: bool}  $view
     */
    public function shape(array $result, array $view = []): array
    {
        $series = $result['series'];
        $show = $view['show'] ?? 'both';
        $visible = in_array($show, ['all', 'funded'], true) && in_array($show, $series, true) ? [$show] : $series;
        // Ongoing and closed are two parts of one whole; funded is a part of all.
        $measure = $visible === ['ongoing', 'closed'] ? $visible : [$visible[0]];
        $amount = fn (array $counts) => array_sum(array_map(fn ($s) => $counts[$s] ?? 0, $measure));
        $crossed = ($result['by'] ?? null) !== null;

        $rows = array_map(function ($row) use ($amount, $result, $measure) {
            $row['value'] = $amount($row['counts']);
            $cells = [];
            foreach (array_keys($result['columns'] ?? []) as $column) {
                $cells[$column] = $amount($row['cells'][$column] ?? []);
            }
            $row['values'] = $cells;
            $perCity = [];
            foreach ($result['cities'] as $c) {
                $perCity[(int) $c->id] = array_sum(array_map(fn ($s) => $row['city_counts'][$s][(int) $c->id] ?? 0, $measure));
            }
            $row['per_city'] = $perCity;
            $row['other'] = false;

            return $row;
        }, $result['rows']);

        if (! empty($view['nozero'])) {
            $rows = array_values(array_filter($rows, fn ($r) => array_sum(array_map(fn ($s) => $r['counts'][$s], $visible)) > 0));
        }

        $top = in_array((int) ($view['top'] ?? 0), self::TOPS, true) ? (int) ($view['top'] ?? 0) : 0;
        $others = null;
        if ($top > 0 && count($rows) > $top) {
            $ranked = array_keys($rows);
            usort($ranked, fn ($a, $b) => [$rows[$b]['value'], $a] <=> [$rows[$a]['value'], $b]);
            $keep = array_flip(array_slice($ranked, 0, $top));
            $rest = array_values(array_diff_key($rows, $keep));
            $rows = array_values(array_intersect_key($rows, $keep));
            $add = fn (string $field) => array_reduce($rest, function (array $sum, array $row) use ($field) {
                foreach ($row[$field] as $k => $n) {
                    $sum[$k] = ($sum[$k] ?? 0) + $n;
                }

                return $sum;
            }, []);
            $counts = $add('counts');
            if (array_sum($counts) > 0) {
                $others = [
                    'key' => self::OTHERS, 'label' => __('statistics.others', ['count' => count($rest)]), 'counts' => $counts, 'value' => array_sum(array_column($rest, 'value')),
                    'values' => $add('values'), 'per_city' => $add('per_city'), 'by_city' => $add('by_city'), 'city_counts' => [], 'cells' => [], 'link' => null, 'other' => true,
                ];
            }
        }

        $sort = in_array($view['sort'] ?? '', self::SORTS, true) ? $view['sort'] : 'default';
        if ($sort === 'desc' || $sort === 'asc') {
            $way = $sort === 'desc' ? -1 : 1;
            usort($rows, fn ($a, $b) => $way * ($a['value'] <=> $b['value']));
        } elseif ($sort === 'name') {
            $collator = class_exists(\Collator::class) ? new \Collator(app()->getLocale()) : null;
            $collator?->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
            usort($rows, fn ($a, $b) => $collator ? (int) $collator->compare($a['label'], $b['label']) : strnatcasecmp($a['label'], $b['label']));
        }
        $others !== null && $rows[] = $others;

        $total = $amount($result['totals']);
        $rows = array_map(function ($row) use ($total) {
            $row['share'] = $total > 0 ? round($row['value'] / $total * 100, 1) : 0.0;

            return $row;
        }, $rows);

        $columnTotals = [];
        foreach (array_keys($result['columns'] ?? []) as $column) {
            $columnTotals[$column] = array_sum(array_map(fn ($r) => $amount($r['cells'][$column] ?? []), $result['rows']));
        }
        $cityTotals = [];
        foreach ($result['cities'] as $c) {
            $cityTotals[(int) $c->id] = array_sum(array_map(fn ($r) => array_sum(array_map(fn ($s) => $r['city_counts'][$s][(int) $c->id] ?? 0, $measure)), $result['rows']));
        }

        return ['rows' => $rows, 'visible' => $visible, 'measure' => $measure, 'total' => $total, 'column_totals' => $columnTotals, 'city_totals' => $cityTotals, 'crossed' => $crossed] + $result;
    }

    /**
     * Rows of an export of a shaped result. A cross table: label, each column, the row total, its share. Otherwise: label, each series
     * shown, share, then the cities when there are several.
     *
     * @return list<list<mixed>>
     */
    public function exportRows(array $result): array
    {
        $result = isset($result['visible']) ? $result : $this->shape($result);
        $many = count($result['cities']) > 1;
        $lines = [];
        foreach ($result['rows'] as $row) {
            if ($result['crossed']) {
                $lines[] = [$row['label'], ...array_values($row['values']), $row['value'], $row['share']];

                continue;
            }
            $cells = [$row['label']];
            foreach ($result['visible'] as $s) {
                $cells[] = $row['counts'][$s];
            }
            $cells[] = $row['share'];
            $many && array_push($cells, ...array_values($row['per_city']));
            $lines[] = $cells;
        }
        $share = $result['total'] > 0 ? 100.0 : 0.0;
        if ($result['crossed']) {
            $lines[] = [__('statistics.total'), ...array_values($result['column_totals']), $result['total'], $share];

            return $lines;
        }
        $cells = [__('statistics.total')];
        foreach ($result['visible'] as $s) {
            $cells[] = $result['totals'][$s];
        }
        $cells[] = $share;
        $many && array_push($cells, ...array_values($result['city_totals']));
        $lines[] = $cells;

        return $lines;
    }

    /** @return list<string> */
    public function exportHeadings(array $result): array
    {
        $result = isset($result['visible']) ? $result : $this->shape($result);
        $en = app()->getLocale() === 'en';
        $h = [__('statistics.axes.'.$result['axis'])];
        if ($result['crossed']) {
            return [...$h, ...array_values($result['columns']), __('statistics.total'), __('statistics.share')];
        }
        foreach ($result['visible'] as $s) {
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
     * An export of the table as shown: authorised (reports.export + applications.view per city) and audited before it returns, so before
     * the first byte.
     *
     * @param  array{by?: ?string, show?: string, sort?: string, top?: int, nozero?: bool}  $view
     * @return array{0: list<string>, 1: list<list<mixed>>, 2: list<int>}
     */
    public function export(WebAccount $account, string $axis, array $filters, array $view = []): array
    {
        $by = is_string($view['by'] ?? null) ? $view['by'] : null;
        $result = $this->shape($this->axis($account, $axis, $filters, true, $by), $view);
        $rows = $this->exportRows($result);
        $headings = $this->exportHeadings($result);
        $name = $axis.($result['crossed'] ? '/by:'.$result['by'] : '');
        $this->audit->record($account->id, 'reports.exported', 'succeeded', decision: 'statistics:'.$name.'/rows:'.count($rows));

        return [$headings, $rows, range(1, count($headings) - 1)];
    }
}
