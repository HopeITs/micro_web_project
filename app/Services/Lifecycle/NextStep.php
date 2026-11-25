<?php

declare(strict_types=1);

namespace App\Services\Lifecycle;

use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountAccess;
use App\Services\Applications\ApplicationService;
use App\Services\Field\VisitKinds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The "next step" box of a project (UX F2, docs/planning/ux-borrowed-features-2026-11.md): one sentence for what has to happen next,
 * computed from the current state and the project's tasks, and whom it waits on. Owner decision (21 Nov 2026): the box names the role
 * only, never people. For a task, the roles are those of the accounts responsible for it in the city; with nobody responsible, the
 * administrator. The action button appears only where the reader may open the page it leads to.
 */
final class NextStep
{
    public function __construct(private AccountAccess $access, private ApplicationService $applications, private ProjectTaskService $tasks) {}

    /** @return ?array{title: string, waiting: ?string, href: ?string, action: ?string, since: ?string} */
    public function for(WebAccount $account, object $project, ?object $current): ?array
    {
        $state = $current?->state_en;
        $city = (int) $project->city_id;
        $locale = app()->getLocale();
        $id = (int) $project->id;
        $tasks = $this->tasks->tasks($id);
        $open = fn (string $type) => $tasks->first(fn ($t) => $t->type === $type && ! $t->done);
        $toTasks = $this->tasks->canView($account, $city) ? '#tasks' : null;
        $toState = $this->access->check($account, $city, 'lifecycle.manage') ? '#lifecycle' : null;

        $step = match ($state) {
            LifecycleService::ON_HOLD => $this->step('complete_full', ['data-entry'],
                $this->applications->canWrite($account, $city) ? route('applications.edit', ['locale' => $locale, 'project' => $id]) : null, 'open_form'),
            LifecycleService::WAITING, LifecycleService::UNDER_REVIEW => match (true) {
                ! $this->hasChecklist($id) => $this->step('checklist', ['data-entry'],
                    $this->access->check($account, $city, 'assessments.view') ? route('checklists.show', ['locale' => $locale, 'project' => $id]) : null, 'open_checklist'),
                ($task = $open('a')) !== null => $this->taskStep($task, $city, $toTasks, $account, $id),
                default => $this->step('decide', ['administrator'], $toState, 'change_state'),
            },
            LifecycleService::DELAYED => $this->step('delayed', ['administrator'], $toState, 'change_state'),
            LifecycleService::ACCEPTED => DB::table('loans')->where('project_id', $id)->whereNull('deleted_at')->exists()
                ? (($task = $open('f')) !== null ? $this->taskStep($task, $city, $toTasks, $account, $id) : null)
                : $this->step('loan', ['financial'], null, null),
            LifecycleService::FINANCED => ($task = $open('f')) !== null ? $this->taskStep($task, $city, $toTasks, $account, $id) : $this->step('instalments', ['financial'], null, null),
            default => null,
        };
        if ($step === null) {
            return null;
        }
        if ($current?->changed_on) {
            $days = (int) CarbonImmutable::parse($current->changed_on)->startOfDay()->diffInDays(CarbonImmutable::today());
            $step['since'] = $days === 0 ? __('lifecycle.next.today') : trans_choice('lifecycle.next.since', $days, ['days' => $days]);
        }

        return $step;
    }

    /** @param list<string> $roles */
    private function step(string $key, array $roles, ?string $href, ?string $action, array $replace = []): array
    {
        return ['title' => __('lifecycle.next.'.$key, $replace), 'waiting' => $this->roleNames($roles),
            'href' => $href, 'action' => $href !== null && $action !== null ? __('lifecycle.next.actions.'.$action) : null, 'since' => null];
    }

    private function taskStep(object $task, int $city, ?string $href, ?WebAccount $account = null, ?int $projectId = null): array
    {
        $name = app()->getLocale() === 'en' && $task->name_en ? $task->name_en : $task->name_ar;
        $roles = DB::table('account_city_tasks as t')->join('account_city_roles as r', fn ($j) => $j->on('r.account_id', '=', 't.account_id')->on('r.city_id', '=', 't.city_id'))
            ->join('web_accounts as a', 'a.id', '=', 't.account_id')
            ->where('t.city_id', $city)->where('t.task_definition_id', $task->task_definition_id)->whereNull('t.revoked_at')->whereNull('r.revoked_at')->whereNull('a.disabled_at')
            ->distinct()->pluck('r.role')->all();

        // M4.12: a visit task (Opening Visit, M1…, Clearance) leads to the project's visits page for those who record visits.
        if ($account !== null && $projectId !== null && in_array($task->name_ar, VisitKinds::TASKS, true)
            && ($this->access->check($account, $city, 'visits.manage') || $this->access->check($account, $city, 'me_visits.manage'))) {
            return $this->step('task', $roles === [] ? ['administrator'] : $roles, route('visits.hub', ['locale' => app()->getLocale(), 'project' => $projectId]), 'open_visits', ['task' => $name]);
        }

        return $this->step('task', $roles === [] ? ['administrator'] : $roles, $href, 'see_tasks', ['task' => $name]);
    }

    /** @param list<string> $roles */
    private function roleNames(array $roles): ?string
    {
        $order = array_keys(AccessRoles::ROLES);
        usort($roles, fn ($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));

        return $roles === [] ? null : implode(app()->getLocale() === 'ar' ? '، ' : ', ', array_map(fn ($r) => __('admin.bundles.'.$r), $roles));
    }

    /** A checklist for this project, or (old records had no project) one of its beneficiary's without a project. */
    private function hasChecklist(int $projectId): bool
    {
        $person = DB::table('project_applicants')->where('project_id', $projectId)->whereNull('deleted_at')
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [ApplicationService::BENEFICIARY])->orderBy('id')->value('person_id');

        return DB::table('checklists')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('project_id', $projectId)->orWhere(fn ($q) => $q->whereNull('project_id')->where('person_id', $person ?? 0)))->exists();
    }
}
