<?php

declare(strict_types=1);

namespace App\Services\Field;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Finance\Fields;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Visitor statistics (M4.12, owner decision 7: the administrator only, `visits.statistics`; desktop `Visits_Statistics` /
 * `VisitorStatistics.cs`): one row per visitor account with its Opening, M1–M5 and Closing visits and the total of distinct visits, sorted by
 * the total; a totals row counts every visit of the period by stage. Visits come from `visits` + `visit_visitors`, `me_visits` +
 * `me_visit_visitors`, and Kobo submissions not written as a visit (`kobo_monitoring_records` + `kobo_visit_visitors`); a Kobo visit already
 * written as a visit is counted once, its Kobo visitors joining the visit's own.
 */
final class VisitorStatisticsService
{
    public const STAGES = ['Opening', 'M1', 'M2', 'M3', 'M4', 'M5', 'Closing'];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit) {}

    public function cities(WebAccount $account, bool $export = false): Collection
    {
        return $this->access->citiesWithAll($account, ['visits.statistics', ...($export ? ['reports.export'] : [])]);
    }

    private function permitted(WebAccount $account, bool $export): Collection
    {
        $permitted = $this->cities($account, $export);
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }

        return $permitted;
    }

    public function opened(WebAccount $account): void
    {
        $this->permitted($account, false);
        $this->audit->record($account->id, 'visits.statistics', 'succeeded');
    }

    /** Default period: the last year up to today. */
    public static function defaults(): array
    {
        return ['from' => now()->subYear()->toDateString(), 'to' => now()->toDateString()];
    }

    /** @return array{from: string, to: string, type: ?int, cities: list<int>} */
    public static function clean(array $input): array
    {
        $defaults = self::defaults();
        $from = is_string($input['from'] ?? null) && Fields::isDate($input['from']) ? $input['from'] : $defaults['from'];
        $to = is_string($input['to'] ?? null) && Fields::isDate($input['to']) ? $input['to'] : $defaults['to'];
        if ($from > $to) {
            throw ValidationException::withMessages(['from' => __('visits.statistics.errors.range')]);
        }
        $type = filter_var($input['type'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return ['from' => $from, 'to' => $to, 'type' => $type === false ? null : $type, 'cities' => array_values(array_filter(array_map('intval', (array) ($input['cities'] ?? []))))];
    }

    /** @return array{rows: Collection, totals: array<string, int>, cityOptions: Collection, types: Collection, canExport: bool} */
    public function report(WebAccount $account, array $filters, bool $export = false): array
    {
        $permitted = $this->permitted($account, $export);
        $all = $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $cities = array_values(array_intersect($filters['cities'], $all)) ?: $all;
        [$events, $assignments] = $this->events($cities, $filters);
        $names = DB::table('web_accounts')->whereIn('id', array_keys($assignments) ?: [0])->pluck('username', 'id');
        $rows = collect($assignments)->map(function (array $visits, int $account) use ($events, $names) {
            $row = ['name' => (string) ($names[$account] ?? '#'.$account), 'stages' => array_fill_keys(self::STAGES, 0), 'total' => count($visits)];
            foreach (array_keys($visits) as $event) {
                isset($row['stages'][$events[$event]]) && $row['stages'][$events[$event]]++;
            }

            return (object) $row;
        })->sort(fn ($a, $b) => [$b->total, $a->name] <=> [$a->total, $b->name])->values();
        $totals = array_fill_keys(self::STAGES, 0);
        foreach ($events as $stage) {
            isset($totals[$stage]) && $totals[$stage]++;
        }
        $totals['total'] = count($events);

        return ['rows' => $rows, 'totals' => $totals, 'cityOptions' => $permitted, 'types' => DB::table('project_types')->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']),
            'canExport' => $this->cities($account, true)->isNotEmpty()];
    }

    /** @return array{0: array<string, string>, 1: array<int, array<string, true>>} event => stage, account => events */
    private function events(array $cities, array $filters): array
    {
        $events = [];
        $assign = [];
        $add = function (string $event, ?int $account) use (&$assign) {
            if ($account !== null) {
                $assign[$account][$event] = true;
            }
        };
        $typed = fn ($query, string $column) => $filters['type'] ? $query->whereIn($column, DB::table('projects')->where('project_type_id', $filters['type'])->select('id')) : $query;

        $visits = $typed(DB::table('visits')->whereIn('city_id', $cities)->whereNull('deleted_at')->whereBetween('visit_date', [$filters['from'], $filters['to']]), 'project_id')->get(['id', 'kind']);
        foreach ($visits as $v) {
            $events['v:'.$v->id] = match (true) { str_ends_with((string) $v->kind, 'op') => 'Opening', str_ends_with((string) $v->kind, 'cl') => 'Closing', default => '' };
        }
        $ids = $visits->pluck('id')->all();
        foreach (DB::table('visit_visitors')->whereIn('visit_id', $ids ?: [0])->whereNull('deleted_at')->get(['visit_id', 'account_id']) as $r) {
            $add('v:'.$r->visit_id, (int) $r->account_id);
        }
        foreach (DB::table('kobo_submissions as s')->join('kobo_visit_visitors as k', 'k.kobo_submission_id', '=', 's.id')->where('s.local_table', 'visits')->whereIn('s.local_record_id', $ids ?: [0])
            ->whereNull('s.deleted_at')->whereNull('k.deleted_at')->whereNotNull('k.account_id')->get(['s.local_record_id', 'k.account_id']) as $r) {
            $add('v:'.$r->local_record_id, (int) $r->account_id);
        }

        $me = $typed(DB::table('me_visits')->whereIn('city_id', $cities)->whereNull('deleted_at')->whereBetween('visit_date', [$filters['from'], $filters['to']]), 'project_id')->get(['id', 'visit_number']);
        foreach ($me as $m) {
            $events['m:'.$m->id] = 'M'.$m->visit_number;
        }
        $ids = $me->pluck('id')->all();
        foreach (DB::table('me_visit_visitors')->whereIn('me_visit_id', $ids ?: [0])->whereNull('deleted_at')->get(['me_visit_id', 'account_id']) as $r) {
            $add('m:'.$r->me_visit_id, (int) $r->account_id);
        }
        foreach (DB::table('kobo_monitoring_records as r')->join('kobo_visit_visitors as k', 'k.kobo_submission_id', '=', 'r.kobo_submission_id')->whereIn('r.me_visit_id', $ids ?: [0])
            ->whereNull('r.deleted_at')->whereNull('k.deleted_at')->whereNotNull('k.account_id')->get(['r.me_visit_id', 'k.account_id']) as $r) {
            $add('m:'.$r->me_visit_id, (int) $r->account_id);
        }

        // Kobo monitoring submissions that were never written as an M&E visit.
        $records = $typed(DB::table('kobo_monitoring_records as r')->join('kobo_submissions as s', 's.id', '=', 'r.kobo_submission_id')->whereIn('r.city_id', $cities)->whereNull('r.me_visit_id')
            ->whereNull('r.deleted_at')->whereNull('s.deleted_at')->where('s.is_deleted_in_kobo', false)->whereBetween('r.visit_date', [$filters['from'], $filters['to']]), 'r.project_id')
            ->get(['r.id', 'r.kobo_submission_id', 'r.visit_number']);
        foreach ($records as $r) {
            $events['r:'.$r->id] = 'M'.$r->visit_number;
        }
        $bySubmission = $records->groupBy('kobo_submission_id');
        foreach (DB::table('kobo_visit_visitors')->whereIn('kobo_submission_id', $bySubmission->keys()->all() ?: [0])->whereNull('deleted_at')->whereNotNull('account_id')->get(['kobo_submission_id', 'account_id']) as $k) {
            foreach ($bySubmission[$k->kobo_submission_id] as $r) {
                $add('r:'.$r->id, (int) $k->account_id);
            }
        }

        return [$events, $assign];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    public function export(WebAccount $account, array $filters): array
    {
        $report = $this->report($account, $filters, true);
        $this->audit->record($account->id, 'reports.exported', 'succeeded');
        $rows = $report['rows']->map(fn ($r) => [$r->name, ...array_values($r->stages), $r->total])->all();
        $rows[] = [__('visits.statistics.totals'), ...array_map(fn ($s) => $report['totals'][$s], self::STAGES), $report['totals']['total']];

        return [[__('visits.statistics.visitor'), ...self::STAGES, __('visits.statistics.total')], $rows];
    }
}
