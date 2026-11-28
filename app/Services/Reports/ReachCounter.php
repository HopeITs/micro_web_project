<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The three counting units of the programme (owner decision, 28 November 2026; X4):
 *   projects       the projects matched;
 *   beneficiaries  distinct people linked to those projects as owner or partner (`project_applicants`);
 *   reached        the beneficiaries plus every member of their families (`family_members` of the families the
 *                  beneficiaries belong to), each person counted once.
 * The input is any city-scoped projects query (filters already applied); its selects and order are dropped, so the
 * counts always follow exactly the rows a list shows. Soft-deleted links, members and families are not counted.
 */
final class ReachCounter
{
    /** @return array{projects: int, beneficiaries: int, reached: int, partners: int, without_person: int} */
    public function count(Builder $projects): array
    {
        $ids = $this->projectIds($projects);

        $projectCount = (int) DB::query()->fromSub($ids, 'p')->count();
        $links = DB::table('project_applicants as pa')->whereNull('pa.deleted_at')->whereIn('pa.project_id', $ids);
        $beneficiaries = (int) (clone $links)->distinct()->count('pa.person_id');
        $partners = (int) (clone $links)->where('pa.role', 'شريك')->distinct()->count('pa.person_id');
        $withPerson = (int) (clone $links)->distinct()->count('pa.project_id');

        return [
            'projects' => $projectCount,
            'beneficiaries' => $beneficiaries,
            'reached' => $this->reached($ids),
            'partners' => $partners,
            'without_person' => max(0, $projectCount - $withPerson),
        ];
    }

    /** The projects' ids as a subquery, without the list's own columns, sort or pagination. */
    private function projectIds(Builder $projects): QueryBuilder
    {
        $query = (clone $projects)->toBase();
        $query->columns = null;
        $query->orders = null;
        $query->limit = null;
        $query->offset = null;
        $query->unionOrders = null;

        return $query->select('projects.id')->distinct();
    }

    private function reached(QueryBuilder $ids): int
    {
        $people = DB::table('project_applicants')->whereNull('deleted_at')->whereIn('project_id', $ids)->select('person_id');
        $families = DB::table('family_members as fm')
            ->join('families as f', 'f.id', '=', 'fm.family_id')
            ->whereNull('fm.deleted_at')->whereNull('f.deleted_at')
            ->whereIn('fm.person_id', $people)
            ->select('fm.family_id');
        $members = DB::table('family_members')->whereNull('deleted_at')->whereIn('family_id', $families)->select('person_id');

        return (int) DB::query()->fromSub((clone $people)->union($members), 'r')->count();
    }
}
