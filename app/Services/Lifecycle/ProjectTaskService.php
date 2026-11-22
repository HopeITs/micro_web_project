<?php

declare(strict_types=1);

namespace App\Services\Lifecycle;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Staff\NoticeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The tasks of one project (M4.8, desktop `task_microproject`): the study tasks ("a") opened by the full application and the follow-up
 * tasks ("f") opened by acceptance. Reading needs `tasks.view` in the project's city. Ticking (owner decision, 21 Nov 2026: shown and
 * ticked on the project page; the board of every project is M4.16) needs `tasks.manage` and either the responsibility for that task in
 * the city (`account_city_tasks`, set on the task responsibilities page) or `tasks.manage_all`, as the desktop's TaskBoard.
 */
final class ProjectTaskService
{
    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit, private LifecycleService $lifecycle, private NoticeService $notices) {}

    /** The task whose completion tells the city's communication staff that the purchases are finished (desktop header "مشتريات"). */
    public const PURCHASES_NAME = 'مشتريات';

    public function canView(WebAccount $account, int $city): bool
    {
        return $this->access->check($account, $city, 'tasks.view');
    }

    /** @return list<int> task definitions the account may tick in the city */
    public function tickable(WebAccount $account, int $city): array
    {
        if (! $this->access->check($account, $city, 'tasks.manage')) {
            return [];
        }
        if ($this->access->check($account, $city, 'tasks.manage_all')) {
            return DB::table('task_definitions')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return DB::table('account_city_tasks')->where('account_id', $account->id)->where('city_id', $city)->whereNull('revoked_at')
            ->pluck('task_definition_id')->map(fn ($id) => (int) $id)->all();
    }

    /** The project's tasks in the desktop order, study tasks first. */
    public function tasks(int $projectId): Collection
    {
        return DB::table('project_tasks as t')->join('task_definitions as d', 'd.id', '=', 't.task_definition_id')
            ->where('t.project_id', $projectId)->whereNull('t.deleted_at')
            ->orderByRaw("CASE WHEN d.type = 'a' THEN 0 ELSE 1 END")->orderBy('d.sort_order')->orderBy('d.id')
            ->get(['t.id', 't.task_definition_id', 't.state', 't.task_date', 'd.name_ar', 'd.name_en', 'd.type'])
            ->map(function ($row) {
                $row->done = (int) $row->state === 1;

                return $row;
            });
    }

    /** Read model of the tasks card, or null when the account may not see tasks in the city. */
    public function card(WebAccount $account, int $projectId): ?array
    {
        $project = $this->lifecycle->project($account, $projectId);
        $city = (int) $project->city_id;
        if (! $this->canView($account, $city)) {
            return null;
        }

        return ['tasks' => $this->tasks($projectId), 'tickable' => $this->tickable($account, $city)];
    }

    /** Ticks or unticks one task of the project (state 1/0 and the date, as the desktop's Update_Task_MicroProject). */
    public function set(WebAccount $account, int $projectId, int $projectTaskId, bool $done): void
    {
        $project = $this->lifecycle->project($account, $projectId);
        $city = (int) $project->city_id;
        $task = DB::table('project_tasks as t')->join('task_definitions as d', 'd.id', '=', 't.task_definition_id')
            ->where('t.id', $projectTaskId)->where('t.project_id', $projectId)->whereNull('t.deleted_at')->first(['t.id', 't.state', 't.task_definition_id', 'd.name_ar']);
        abort_if($task === null, 404);
        if (! $this->canView($account, $city) || ! in_array((int) $task->task_definition_id, $this->tickable($account, $city), true)) {
            $this->audit->record($account->id, 'access.denied', 'denied', $city);
            abort(403);
        }
        if (((int) $task->state === 1) === $done) {
            return;
        }
        DB::table('project_tasks')->where('id', $task->id)->update(['state' => $done ? 1 : 0, 'task_date' => now(), 'updated_at' => now()]);
        if ($task->name_ar === self::PURCHASES_NAME) {
            // Finished purchases notify the communication staff; reopening the task clears those notices again (desktop TaskBoard).
            if ($done) {
                $names = DB::table('project_applicants as a')->join('people as pe', 'pe.id', '=', 'a.person_id')->where('a.project_id', $projectId)->whereNull('a.deleted_at')->orderBy('a.id')
                    ->get(['pe.first_name', 'pe.last_name'])->map(fn ($p) => trim($p->first_name.' '.$p->last_name))->unique()->implode(',');
                $this->notices->broadcast($city, 0, $project, 'communication', NoticeService::PURCHASES, now()->toDateString(), $names, 'انتهاء المشتريات', $account->id);
            } else {
                $this->notices->setSeenForProject($project, NoticeService::PURCHASES, 1);
            }
        }
        $this->audit->record($account->id, 'tasks.toggled', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId,
            details: [['field' => 'task', 'before' => $task->name_ar.' — '.__($done ? 'lifecycle.tasks.open' : 'lifecycle.tasks.done', [], 'ar'), 'after' => $task->name_ar.' — '.__($done ? 'lifecycle.tasks.done' : 'lifecycle.tasks.open', [], 'ar')]]);
    }
}
