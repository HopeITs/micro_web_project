<?php

declare(strict_types=1);

namespace App\Services\Reports\Search;

use App\Services\Reports\ProjectReportFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "M&E visits" (desktop `bind_Visits`): one row per project with the date of its opening visit, its five M&E visits and its closing visit.
 * Opening = visits of kind `*-op`; closing = kinds ending `-cl` (rehabilitation closings included); M&E visits are the project's, by number 1–5
 * (the first date when a number was recorded twice). The desktop's totals panel is not reproduced.
 */
final class VisitDatesSearch extends SearchMode
{
    public function key(): string
    {
        return 'visit-dates';
    }

    public function group(): string
    {
        return 'field';
    }

    public function capabilities(): array
    {
        return ['visits.view'];
    }

    public function columns(): array
    {
        return ['number', 'beneficiaries', 'career', 'category', 'subcategory', 'opening_visit', 'm1', 'm2', 'm3', 'm4', 'm5', 'closing_visit'];
    }

    public function query(array $cityIds, array $filters): Builder
    {
        $q = $this->projectBase($cityIds)->addSelect($this->baseColumns());
        $this->lookup($q, 'careers', 'cr', 'p.career_id', 'career');
        $this->lookup($q, 'project_categories', 'pc', 'p.project_category_id', 'category');
        $this->lookup($q, 'project_subcategories', 'ps', 'p.project_subcategory_id', 'subcategory');

        return ProjectReportFilters::apply($q, $filters);
    }

    public function sorts(): array
    {
        return $this->projectSorts(['career' => 'cr', 'category' => 'pc', 'subcategory' => 'ps']);
    }

    public function order(Builder $query): Builder
    {
        return $query->orderBy('p.project_number')->orderBy('p.city_id')->orderBy('p.id');
    }

    public function lines(Collection $rows): array
    {
        $ids = $rows->pluck('project_id')->all() ?: [0];
        $people = $this->applicants($ids);
        $visits = DB::table('visits')->whereIn('project_id', $ids)->whereNull('deleted_at')->whereNotNull('visit_date')->orderBy('visit_date')->orderBy('id')->get(['project_id', 'kind', 'visit_date'])->groupBy('project_id');
        $me = DB::table('me_visits')->whereIn('project_id', $ids)->whereNull('deleted_at')->whereNotNull('visit_date')->orderBy('visit_date')->orderBy('id')->get(['project_id', 'visit_number', 'visit_date'])->groupBy('project_id');

        return $rows->map(function ($r) use ($people, $visits, $me) {
            $mine = $visits->get($r->project_id, collect());
            $meMine = $me->get($r->project_id, collect());
            $kind = fn (string $suffix) => $this->day($mine->first(fn ($v) => str_ends_with(strtolower((string) $v->kind), $suffix))->visit_date ?? null);
            $number = fn (int $n) => $this->day($meMine->first(fn ($v) => (string) $v->visit_number === (string) $n)->visit_date ?? null);

            return [$this->number($r), $people[$r->project_id]['all'] ?? '', $this->name($r, 'career'), $this->name($r, 'category'), $this->name($r, 'subcategory'),
                $kind('-op'), $number(1), $number(2), $number(3), $number(4), $number(5), $kind('-cl')];
        })->all();
    }
}
