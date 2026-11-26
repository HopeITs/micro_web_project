<?php

declare(strict_types=1);

namespace App\Services\Assessments;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\ProjectNumberAllocator;
use App\Support\ArabicSearch;
use App\Support\TableTools\TableSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The general checklist page (owner request 22 Nov 2026): every recorded checklist in the cities where the account holds
 * `assessments.view`, newest first or by any column header, with a search by project number or name and state and city filters. Each row opens the project's checklist
 * page (ChecklistService checks the capability again). New checklists start from the "add" picker on the same page.
 */
final class ChecklistListService
{
    public const PER_PAGE = 25;

    /** The sortable columns. */
    public const SORT_KEYS = ['number', 'beneficiary', 'project', 'state', 'city', 'home_visit', 'project_visit', 'team', 'committee'];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit) {}

    public function cities(WebAccount $account): Collection
    {
        return $this->access->cities($account, 'assessments.view');
    }

    public function opened(WebAccount $account): void
    {
        if ($this->cities($account)->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $this->audit->record($account->id, 'applications.list', 'succeeded');
    }

    /**
     * @param  array{q?: string, cities?: list<int>, state?: ?int, sort?: ?string, dir?: ?string}  $filters
     * @return array{rows: LengthAwarePaginator, cities: Collection, states: Collection}
     */
    public function page(WebAccount $account, array $filters): array
    {
        $permitted = $this->cities($account);
        $all = $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $chosen = array_values(array_intersect(array_map('intval', $filters['cities'] ?? []), $all));
        $ids = $chosen !== [] ? $chosen : $all;

        $current = DB::table('project_state_history')->select('project_id', 'project_state_id')->where('is_current', true)->whereNull('deleted_at');
        $query = DB::table('checklists as ch')->join('projects as p', 'p.id', '=', 'ch.project_id')->join('cities as c', 'c.id', '=', 'p.city_id')
            ->join('people as b', 'b.id', '=', 'ch.person_id')
            ->leftJoinSub($current, 'cs', 'cs.project_id', '=', 'p.id')->leftJoin('project_states as st', 'st.id', '=', 'cs.project_state_id')
            ->whereNull('ch.deleted_at')->whereNull('p.deleted_at')->whereIn('p.city_id', $ids === [] ? [0] : $ids)
            ->select(['ch.id', 'p.id as project_id', 'p.project_number', 'p.name', 'c.code', 'c.name_ar as city_ar', 'c.name_en as city_en',
                'b.first_name', 'b.father_name', 'b.last_name', 'st.name_ar as state_ar', 'st.name_en as state_en',
                'ch.home_visit_date', 'ch.project_visit_date', 'ch.team_date', 'ch.committee_date']);

        $term = trim(mb_substr((string) ($filters['q'] ?? ''), 0, 100));
        if ($term !== '') {
            $number = ProjectNumberAllocator::parse($term);
            if ($number !== null) {
                [$code, $value] = $number;
                $query->whereRaw('CAST(p.project_number AS CHAR) LIKE ?', [$value.'%']);
                if ($code !== null) {
                    $query->where('c.code', $code);
                }
            } else {
                foreach (preg_split('/\s+/u', $term) as $word) {
                    $query->where(function ($q) use ($word) {
                        foreach (['b.first_name', 'b.father_name', 'b.last_name', 'p.name'] as $column) {
                            ArabicSearch::orWhereLike($q, $column, $word);
                        }
                    });
                }
            }
        }

        ! empty($filters['state']) && $query->where('cs.project_state_id', (int) $filters['state']);
        $name = fn (string $alias) => app()->isLocale('en') ? "COALESCE(NULLIF({$alias}.name_en, ''), {$alias}.name_ar)" : $alias.'.name_ar';
        TableSort::apply($query, [
            'number' => ['c.code', 'p.project_number'], 'beneficiary' => ['b.first_name', 'b.father_name', 'b.last_name'], 'project' => 'p.name', 'state' => $name('st'), 'city' => $name('c'),
            'home_visit' => 'ch.home_visit_date', 'project_visit' => 'ch.project_visit_date', 'team' => 'ch.team_date', 'committee' => 'ch.committee_date',
        ], $filters['sort'] ?? null, $filters['dir'] ?? null);
        $rows = $query->orderByDesc('ch.id')->paginate(self::PER_PAGE)->withQueryString();
        $rows->setCollection($rows->getCollection()->map(fn ($r) => (object) [
            'project_id' => (int) $r->project_id,
            'number' => ProjectNumberAllocator::display($r->code, (int) $r->project_number),
            'name' => (string) $r->name,
            'beneficiary' => trim($r->first_name.' '.$r->father_name.' '.$r->last_name),
            'city' => app()->isLocale('en') && $r->city_en ? $r->city_en : $r->city_ar,
            'state_ar' => $r->state_ar, 'state_en' => $r->state_en,
            'home_visit' => $r->home_visit_date ? substr((string) $r->home_visit_date, 0, 10) : null,
            'project_visit' => $r->project_visit_date ? substr((string) $r->project_visit_date, 0, 10) : null,
            'team' => $r->team_date ? substr((string) $r->team_date, 0, 10) : null,
            'committee' => $r->committee_date ? substr((string) $r->committee_date, 0, 10) : null,
        ]));

        return ['rows' => $rows, 'cities' => $permitted, 'states' => DB::table('project_states')->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en'])];
    }
}
