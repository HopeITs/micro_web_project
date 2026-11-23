<?php

declare(strict_types=1);

namespace App\Services\Reports\Search;

use App\Services\Reports\ProjectReportFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Monitoring visits (1 to 5)" (desktop `bind_NewVisits`): every M&E visit with its project and the visited person, net profit, result and
 * the evaluation (the active `me_result_ranges` row holding the result), plus the marks of the first and the last recorded visitor (the same
 * when only one is recorded). Switch "last visit only": the highest visit number of each person.
 */
final class MeVisitSearch extends SearchMode
{
    public function key(): string
    {
        return 'me-visits';
    }

    public function group(): string
    {
        return 'field';
    }

    public function capabilities(): array
    {
        return ['visits.view', 'me_visits.view'];
    }

    public function hasLastSwitch(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return ['number', 'beneficiary', 'career', 'visit_date', 'visit_number', 'visit_kind', 'profit', 'result', 'evaluation', 'visitor1_mark', 'visitor2_mark', 'donor', 'category', 'subcategory'];
    }

    public function numeric(): array
    {
        return [1, 5, 7, 8];
    }

    public function query(array $cityIds, array $filters): Builder
    {
        $q = $this->joinProject(DB::table('me_visits as m')->whereNull('m.deleted_at'), 'm.project_id', $cityIds)
            ->leftJoin('people as pe', fn ($j) => $j->on('pe.id', '=', 'm.person_id')->whereNull('pe.deleted_at'))
            ->addSelect([...$this->baseColumns(), 'm.id as visit_id', 'm.visit_date', 'm.visit_number', 'm.kind', 'm.profit', 'm.result', 'pe.first_name', 'pe.last_name', 'pe.father_name']);
        $this->lookup($q, 'careers', 'cr', 'p.career_id', 'career');
        $this->lookup($q, 'donors', 'dn', 'p.donor_id', 'donor');
        $this->lookup($q, 'project_categories', 'pc', 'p.project_category_id', 'category');
        $this->lookup($q, 'project_subcategories', 'ps', 'p.project_subcategory_id', 'subcategory');
        if ($filters['last'] ?? false) {
            $q->whereNotExists(fn ($n) => $n->selectRaw('1')->from('me_visits as m2')->whereColumn('m2.person_id', 'm.person_id')->whereNull('m2.deleted_at')
                ->where(fn ($w) => $w->whereColumn('m2.visit_number', '>', 'm.visit_number')->orWhere(fn ($same) => $same->whereColumn('m2.visit_number', 'm.visit_number')->whereColumn('m2.id', '>', 'm.id'))));
        }

        return ProjectReportFilters::apply($q, $filters);
    }

    public function order(Builder $query): Builder
    {
        return $query->orderBy('p.project_number')->orderBy('p.city_id')->orderBy('m.id');
    }

    public function lines(Collection $rows): array
    {
        $marks = DB::table('me_visit_visitors')->whereIn('me_visit_id', $rows->pluck('visit_id')->all() ?: [0])->whereNull('deleted_at')->orderBy('id')->get(['me_visit_id', 'mark'])->groupBy('me_visit_id');
        $ranges = DB::table('me_result_ranges')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        return $rows->map(function ($r) use ($marks, $ranges) {
            $mine = $marks->get($r->visit_id, collect());
            $range = $r->result === null ? null : $ranges->first(fn ($x) => (int) $r->result >= (int) $x->min_value && (int) $r->result <= (int) $x->max_value);

            return [$this->number($r), $this->personName($r, true), $this->name($r, 'career'), $this->day($r->visit_date), (string) $r->visit_number,
                strtolower((string) $r->kind) === 'v' ? __('search.me_kind.v') : __('search.me_kind.o'), $r->profit ?? '', $r->result ?? '', $this->pick($range),
                (string) ($mine->first()->mark ?? ''), (string) ($mine->last()->mark ?? ''), $this->name($r, 'donor'), $this->name($r, 'category'), $this->name($r, 'subcategory')];
        })->all();
    }
}
