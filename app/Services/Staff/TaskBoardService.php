<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Finance\Fields;
use App\Services\Lifecycle\ProjectTaskService;
use App\Services\ProjectNumberAllocator;
use App\Support\ArabicSearch;
use App\Support\TableTools\TableSort;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The task board (M4.16, desktop `TaskBoard_Form`) on the unified schema. Two views of the same data:
 *  - the grid: one row per project, one done / not done cell per task, for the funded board ("f", tasks of accepted projects) or the
 *    application board ("a", the study tasks), across the cities where the account holds `tasks.view` and `applications.view`;
 *  - "my tasks": the open tasks of the projects on the boards that the account is responsible for (`account_city_tasks`).
 * A cell is ticked by an account that holds `tasks.manage` and is responsible for that task in the city, or holds `tasks.manage_all`
 * (owner decision, 22 Nov 2026); the rule and the audit live in `ProjectTaskService::set`, which the project page uses too.
 */
final class TaskBoardService
{
    public const PER_PAGE = 50;

    /** English names of the states whose projects are listed on the funded board: Accepted, Financed, Finished, Canceled, Closed. */
    public const FUNDED_STATES = ['Accepted', 'Financed', 'Finished', 'Canceled', 'Closed'];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit, private ProjectTaskService $tasks) {}

    /** Cities where the account may read the task board. */
    public function cities(WebAccount $account): Collection
    {
        $view = $this->access->cities($account, 'applications.view')->pluck('id')->all();

        return $this->access->cities($account, 'tasks.view')->filter(fn ($city) => in_array($city->id, $view, true))->values();
    }

    /** The task columns of a board in the desktop order; the funded board leaves out the acceptance task, which every accepted project has. */
    public function columns(string $kind): Collection
    {
        $query = DB::table('task_definitions')->where('type', $kind)->where('state', 1)->where('is_active', 1);
        if ($kind === 'f') {
            $first = DB::table('task_definitions')->where('type', 'f')->orderBy('sort_order')->orderBy('id')->value('id');
            $query->where('id', '<>', $first ?? 0);
        }

        return $query->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en'])->map(function ($task) {
            $task->id = (int) $task->id;
            $task->label = app()->isLocale('en') && $task->name_en ? $task->name_en : $task->name_ar;

            return $task;
        });
    }

    /** @return list<int> ids of the states listed on the funded board */
    private function fundedStates(): array
    {
        return DB::table('project_states')->whereIn('name_en', self::FUNDED_STATES)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  array{kind?: ?string, cities?: list<int>, q?: ?string, state?: ?int, donor?: ?int, type?: ?int, tasks?: array<int, string>, sort?: ?string, dir?: ?string}  $filters
     */
    public function board(WebAccount $account, array $filters): array
    {
        [$query, $ids, $columns, $kind, $permitted] = $this->boardQuery($account, $filters, 'tasks.board');
        $rows = $this->ordered($query, $columns, $kind, $filters)->paginate(self::PER_PAGE);
        $rows->setCollection($this->decorate($account, $rows->getCollection(), $columns, $kind));

        return [
            'rows' => $rows, 'permitted' => $permitted, 'kind' => $kind, 'columns' => $columns, 'options' => $this->options($kind),
            'responsible' => $this->responsible($ids, $columns), 'shown' => $ids,
        ];
    }

    /** @return array{0: Builder, 1: list<int>, 2: Collection, 3: string, 4: Collection} query, shown cities, task columns, board kind, permitted cities */
    private function boardQuery(WebAccount $account, array $filters, string $event, bool $export = false): array
    {
        $permitted = $this->cities($account);
        if ($export) {
            // An export needs its own grant in each city, on top of the page's: a city without it is left out (and none left is a refusal).
            $permitted = $permitted->filter(fn ($city) => $this->access->check($account, (int) $city->id, 'reports.export'))->values();
        }
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $kind = ($filters['kind'] ?? 'f') === 'a' ? 'a' : 'f';
        $selected = array_values(array_intersect(array_map('intval', $filters['cities'] ?? []), $permitted->pluck('id')->map(fn ($id) => (int) $id)->all()));
        $ids = $selected !== [] ? $selected : $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $columns = $this->columns($kind);
        $this->audit->record($account->id, $event, 'succeeded');

        $query = DB::table('projects as p')->join('cities as ci', 'ci.id', '=', 'p.city_id')->whereIn('p.city_id', $ids)->whereNull('p.deleted_at');
        $query->whereExists(function (Builder $s) use ($kind, $filters) {
            $s->selectRaw('1')->from('project_state_history as h')->whereColumn('h.project_id', 'p.id')->where('h.is_current', true)->whereNull('h.deleted_at');
            $kind === 'f' && $s->whereIn('h.project_state_id', $this->fundedStates() ?: [0]);
            ! empty($filters['state']) && $s->where('h.project_state_id', (int) $filters['state']);
        });
        ! empty($filters['donor']) && $query->where('p.donor_id', (int) $filters['donor']);
        ! empty($filters['type']) && $query->where('p.project_type_id', (int) $filters['type']);
        foreach ($filters['tasks'] ?? [] as $task => $flag) {
            if (! $columns->contains('id', (int) $task) || ! in_array((string) $flag, ['0', '1'], true)) {
                continue;
            }
            $query->whereExists(fn (Builder $t) => $t->selectRaw('1')->from('project_tasks as t')->whereColumn('t.project_id', 'p.id')->whereNull('t.deleted_at')
                ->where('t.task_definition_id', (int) $task)->where('t.state', (string) $flag === '1' ? 1 : 0));
        }
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $number = ProjectNumberAllocator::parse($search);
            $query->where(function (Builder $q) use ($search, $number) {
                ArabicSearch::orWhereLike($q, 'p.name', $search);
                if ($number !== null) {
                    $q->orWhere(fn ($n) => $n->where('p.project_number', $number[1])->when($number[0] !== null, fn ($c) => $c->where('ci.code', $number[0])));
                }
                $q->orWhereExists(function (Builder $e) use ($search) {
                    $e->selectRaw('1')->from('project_applicants as a')->join('people as pe', 'pe.id', '=', 'a.person_id')->whereColumn('a.project_id', 'p.id')->whereNull('a.deleted_at');
                    // Every word of the search in the first, last or father's name, or the mobile.
                    foreach (array_slice(preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $word) {
                        $e->where(function ($n) use ($word) {
                            foreach (['pe.first_name', 'pe.last_name', 'pe.father_name'] as $column) {
                                ArabicSearch::orWhereLike($n, $column, $word);
                            }
                            $n->orWhere('pe.mobile', 'like', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%');
                        });
                    }
                });
            });
        }

        return [$query->select(['p.id', 'p.city_id', 'p.project_number', 'p.name', 'p.donor_id', 'p.project_type_id', 'ci.code', 'ci.name_en as city_en', 'ci.name_ar as city_ar']), $ids, $columns, $kind, $permitted];
    }

    /** @return list<string> the keys the grid may be sorted by: the fixed columns and one per task of the board */
    public static function sortKeys(Collection $columns, string $kind): array
    {
        return ['number', 'beneficiary', 'state', ...($kind === 'f' ? ['loan'] : []), ...$columns->map(fn ($c) => 'task-'.(int) $c->id)->all()];
    }

    /** The grid's order (screen and file alike): the asked header column, then the newest project number first. */
    private function ordered(Builder $query, Collection $columns, string $kind, array $filters): Builder
    {
        $person = fn (string $column) => DB::table('project_applicants as sa')->join('people as sp', 'sp.id', '=', 'sa.person_id')
            ->whereColumn('sa.project_id', 'p.id')->whereNull('sa.deleted_at')
            ->orderByRaw("CASE WHEN sa.role = 'مستفيد' THEN 0 WHEN sa.role = 'شريك' THEN 1 ELSE 2 END")->orderBy('sa.id')->select('sp.'.$column)->limit(1);
        $stateName = app()->isLocale('en') ? "COALESCE(NULLIF(ss.name_en, ''), ss.name_ar)" : 'ss.name_ar';
        $sortable = [
            'number' => 'p.project_number',
            'beneficiary' => [$person('first_name'), $person('last_name')],
            'state' => DB::table('project_state_history as sh')->join('project_states as ss', 'ss.id', '=', 'sh.project_state_id')
                ->whereColumn('sh.project_id', 'p.id')->where('sh.is_current', true)->whereNull('sh.deleted_at')->selectRaw('MIN('.$stateName.')'),
            'loan' => DB::table('loans as sl')->whereColumn('sl.project_id', 'p.id')->whereNull('sl.deleted_at')->selectRaw('MIN(sl.taken_on)'),
        ];
        foreach ($columns as $column) {
            // Ascending: open first, then done; projects without the task come last.
            $sortable['task-'.(int) $column->id] = DB::table('project_tasks as st')->whereColumn('st.project_id', 'p.id')->whereNull('st.deleted_at')
                ->where('st.task_definition_id', (int) $column->id)->selectRaw('MAX(st.state)');
        }
        TableSort::apply($query, array_intersect_key($sortable, array_flip(self::sortKeys($columns, $kind))), $filters['sort'] ?? null, $filters['dir'] ?? null);

        return $query->orderByDesc('p.project_number')->orderBy('p.city_id')->orderBy('p.id');
    }

    /** Filter lists of the board: the states it lists, the donors and the project types. */
    private function options(string $kind): array
    {
        $states = DB::table('project_states');
        $kind === 'f' && $states->whereIn('id', $this->fundedStates() ?: [0]);

        return [
            'states' => $states->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']),
            'donors' => DB::table('donors')->where('is_active', 1)->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en', 'abbreviation']),
            'types' => DB::table('project_types')->where('is_active', 1)->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']),
        ];
    }

    /**
     * Every project of the board's filters (not one page) for the Excel/CSV export: one row per project, one column per task.
     * Authorization and the audit record happen here, before the first byte is sent.
     *
     * @return array{0: list<string>, 1: \Generator<int, list<string|int|null>>, 2: string} the task column headings, the rows and the board kind
     */
    public function exportRows(WebAccount $account, array $filters): array
    {
        [$query, , $columns, $kind] = $this->boardQuery($account, $filters, 'reports.exported', true);
        $this->ordered($query, $columns, $kind, $filters);
        $rows = (function () use ($query, $account, $columns, $kind) {
            foreach ($query->lazy(200)->chunk(200) as $chunk) {
                foreach ($this->decorate($account, collect($chunk->all()), $columns, $kind) as $row) {
                    $line = [$row->city_en, $row->display, implode('، ', $row->people), $row->mobile, $row->name, $row->state_name, $row->donor_name, $row->type_name];
                    $kind === 'f' && $line[] = $row->loan_day;
                    foreach ($columns as $column) {
                        $cell = $row->cells[$column->id];
                        $line[] = ! $cell['exists'] ? __('staff.tasks.not_assigned') : ($cell['done'] ? __('staff.tasks.done') : __('staff.tasks.open'));
                    }

                    yield $line;
                }
            }
        })();

        return [$columns->pluck('label')->all(), $rows, $kind];
    }

    /**
     * Ticks or unticks one cell. The submitted `expected` state is the one the page showed: a stale or repeated submission is refused.
     * The permission, the audit record and the "purchases finished" notice are in `ProjectTaskService::set`.
     */
    public function toggle(WebAccount $account, int $projectTaskId, bool $expected, bool $done): void
    {
        DB::transaction(function () use ($account, $projectTaskId, $expected, $done) {
            $cell = DB::table('project_tasks')->where('id', $projectTaskId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_if($cell === null, 404);
            abort_unless(((int) $cell->state === 1) === $expected && $expected !== $done, 409, 'The task changed. Reload before saving.');
            $this->tasks->set($account, (int) $cell->project_id, $projectTaskId, $done);
        });
    }

    /** City → task definition → user names of the accounts responsible for it. */
    private function responsible(array $cityIds, Collection $columns): array
    {
        $result = [];
        $rows = DB::table('account_city_tasks as t')->join('web_accounts as a', 'a.id', '=', 't.account_id')->whereIn('t.city_id', $cityIds)->whereNull('t.revoked_at')->whereNull('a.disabled_at')
            ->whereIn('t.task_definition_id', $columns->pluck('id')->all() ?: [0])->orderBy('a.username')->get(['t.city_id', 't.task_definition_id', 'a.username']);
        foreach ($rows as $row) {
            $result[(int) $row->city_id][(int) $row->task_definition_id][] = (string) $row->username;
        }

        return $result;
    }

    private function decorate(WebAccount $account, Collection $projects, Collection $columns, string $kind): Collection
    {
        $ids = $projects->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return $projects;
        }
        $en = app()->isLocale('en');
        $pick = fn ($ar, $enName) => $en && $enName ? (string) $enName : (string) ($ar ?? '');
        $states = DB::table('project_state_history as h')->join('project_states as s', 's.id', '=', 'h.project_state_id')->whereIn('h.project_id', $ids)->where('h.is_current', true)->whereNull('h.deleted_at')
            ->orderBy('h.id')->get(['h.project_id', 's.name_ar', 's.name_en'])->groupBy('project_id')->map(fn ($g) => $pick($g->first()->name_ar, $g->first()->name_en));
        $loans = $kind === 'f' ? DB::table('loans')->whereIn('project_id', $ids)->whereNull('deleted_at')->whereNotNull('taken_on')->groupBy('project_id')->selectRaw('project_id, MIN(taken_on) as day')->pluck('day', 'project_id') : collect();
        $people = DB::table('project_applicants as a')->join('people as pe', 'pe.id', '=', 'a.person_id')->whereIn('a.project_id', $ids)->whereNull('a.deleted_at')
            ->orderByRaw("CASE WHEN a.role = 'مستفيد' THEN 0 WHEN a.role = 'شريك' THEN 1 ELSE 2 END")->orderBy('a.id')->get(['a.project_id', 'pe.first_name', 'pe.last_name', 'pe.father_name', 'pe.mobile'])->groupBy('project_id');
        $cells = DB::table('project_tasks')->whereIn('project_id', $ids)->whereNull('deleted_at')->orderBy('id')->get(['id', 'project_id', 'task_definition_id', 'state'])
            ->groupBy('project_id')->map(fn ($g) => $g->keyBy('task_definition_id'));
        $donors = DB::table('donors')->get(['id', 'name_ar', 'name_en', 'abbreviation'])->keyBy('id');
        $types = DB::table('project_types')->get(['id', 'name_ar', 'name_en'])->keyBy('id');
        $tickable = [];
        foreach ($projects->pluck('city_id')->unique() as $city) {
            $tickable[(int) $city] = $this->tasks->tickable($account, (int) $city);
        }

        return $projects->map(function ($project) use ($columns, $states, $loans, $people, $cells, $donors, $types, $tickable, $pick) {
            $project->display = ProjectNumberAllocator::display((string) $project->code, (int) $project->project_number);
            $project->city_name = app()->isLocale('en') ? $project->city_en : $project->city_ar;
            $project->state_name = $states[$project->id] ?? null;
            $donor = $donors[$project->donor_id] ?? null;
            $project->donor_name = $donor === null ? null : ($donor->abbreviation ?: $pick($donor->name_ar, $donor->name_en));
            $type = $types[$project->project_type_id] ?? null;
            $project->type_name = $type === null ? null : $pick($type->name_ar, $type->name_en);
            $list = $people[$project->id] ?? collect();
            $project->people = $list->map(fn ($p) => trim(($p->first_name ?? '').' '.($p->last_name ?? '').' '.__('staff.tasks.son_of').' '.($p->father_name ?? '')))->unique()->values()->all();
            $project->mobile = $list->pluck('mobile')->first(fn ($m) => ($m ?? '') !== '');
            $project->loan_day = isset($loans[$project->id]) ? Fields::day($loans[$project->id]) : null;
            $project->cells = [];
            foreach ($columns as $column) {
                $cell = ($cells[$project->id] ?? collect())->get($column->id);
                $project->cells[$column->id] = [
                    'exists' => $cell !== null, 'done' => $cell !== null && (int) $cell->state === 1, 'id' => $cell?->id,
                    'manageable' => $cell !== null && in_array($column->id, $tickable[(int) $project->city_id] ?? [], true),
                ];
            }

            return $project;
        });
    }

    /**
     * "My tasks": the open tasks the account is responsible for, on the projects the boards list, grouped by project, newest project first.
     *
     * @return array{cities: Collection, projects: Collection, total: int, responsible: bool}
     */
    public function mine(WebAccount $account): array
    {
        $permitted = $this->cities($account);
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $this->audit->record($account->id, 'tasks.mine', 'succeeded');
        $en = app()->isLocale('en');
        $ids = $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $assigned = DB::table('account_city_tasks')->where('account_id', $account->id)->whereIn('city_id', $ids)->whereNull('revoked_at')->get(['city_id', 'task_definition_id']);
        $funded = $this->fundedStates();
        $rows = collect();
        foreach ($assigned->groupBy('city_id') as $city => $list) {
            // Only tasks of the permitted board columns that the account may still tick.
            $tickable = $this->tasks->tickable($account, (int) $city);
            $defs = $list->pluck('task_definition_id')->map(fn ($id) => (int) $id)->intersect($tickable)->values()->all();
            if ($defs === []) {
                continue;
            }
            $found = DB::table('project_tasks as t')->join('task_definitions as d', 'd.id', '=', 't.task_definition_id')->join('projects as p', 'p.id', '=', 't.project_id')->join('cities as ci', 'ci.id', '=', 'p.city_id')
                ->where('p.city_id', (int) $city)->whereNull('p.deleted_at')->whereNull('t.deleted_at')->where('t.state', 0)->whereIn('t.task_definition_id', $defs)->where('d.state', 1)
                ->whereExists(fn (Builder $s) => $s->selectRaw('1')->from('project_state_history as h')->whereColumn('h.project_id', 'p.id')->where('h.is_current', true)->whereNull('h.deleted_at')
                    ->when(true, fn ($q) => $q->where(fn ($w) => $w->where('d.type', 'a')->orWhereIn('h.project_state_id', $funded ?: [0]))))
                ->orderByDesc('p.project_number')->orderBy('d.sort_order')
                ->get(['t.id as cell', 'p.id as project', 'p.project_number', 'p.name', 'ci.code', 'ci.name_en as city_en', 'ci.name_ar as city_ar', 'd.name_ar', 'd.name_en', 'd.type']);
            $rows = $rows->concat($found);
        }
        $names = DB::table('project_applicants as a')->join('people as pe', 'pe.id', '=', 'a.person_id')->whereIn('a.project_id', $rows->pluck('project')->unique()->all() ?: [0])->whereNull('a.deleted_at')
            ->orderByRaw("CASE WHEN a.role = 'مستفيد' THEN 0 ELSE 1 END")->orderBy('a.id')->get(['a.project_id', 'pe.first_name', 'pe.last_name'])->groupBy('project_id')
            ->map(fn ($g) => trim(($g->first()->first_name ?? '').' '.($g->first()->last_name ?? '')));
        $projects = $rows->groupBy('project')->map(function ($group) use ($names, $en) {
            $first = $group->first();

            return (object) [
                'id' => (int) $first->project, 'display' => ProjectNumberAllocator::display((string) $first->code, (int) $first->project_number), 'name' => $first->name,
                'city' => $en ? $first->city_en : $first->city_ar, 'beneficiary' => $names[$first->project] ?? '',
                'tasks' => $group->map(fn ($t) => (object) ['cell' => (int) $t->cell, 'label' => $en && $t->name_en ? $t->name_en : $t->name_ar, 'kind' => $t->type])->values(),
            ];
        })->values();

        return ['permitted' => $permitted, 'projects' => $projects, 'total' => $rows->count(), 'responsible' => $assigned->isNotEmpty()];
    }
}
