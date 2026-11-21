<?php

declare(strict_types=1);

namespace App\Services\Lifecycle;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Staff\NoticeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Project states and their side effects (M4.8, desktop AdminPanel_From state column and TasksOfProjects), on the unified schema
 * (`project_state_history`, `project_states`, `state_reasons`, `project_tasks`, `task_definitions`).
 *
 * Owner decisions (21 Nov 2026): only the administrator changes a state (`lifecycle.manage`; Accepted also needs `lifecycle.accept`),
 * as in the desktop (roles 1 and 8); accepting needs the donor in the same step (the desktop only warned afterwards).
 * Desktop rules kept: On Hold is only recorded by a preliminary application, Waiting only follows the full application, an
 * Accepted project becomes Financed only when finance records its loan; Accepted opens the "f" tasks once; Withdrew removes the
 * donor; Withdrew, Canceled, Finished and Closed mark every notice of the project seen; every change except Rejected and Waiting
 * sends a state notice to the city's staff roles.
 */
final class LifecycleService
{
    public const ON_HOLD = 'On Hold';

    public const WAITING = 'Waiting';

    public const UNDER_REVIEW = 'Under Review';

    public const DELAYED = 'Delayed';

    public const REJECTED = 'Rejected';

    public const ACCEPTED = 'Accepted';

    public const FINANCED = 'Financed';

    public const WITHDREW = 'Withdrew';

    public const CANCELED = 'Canceled';

    public const FINISHED = 'Finished';

    public const CLOSED = 'Closed';

    /** States whose arrival marks every notice of the project seen. */
    private const CLEARING = [self::WITHDREW, self::CANCELED, self::FINISHED, self::CLOSED];

    /** States that send no state notice. */
    private const SILENT = [self::REJECTED, self::WAITING];

    /**
     * The application path (owner decision, 21 Nov 2026): from each state, the states a manager may choose. Waiting after the preliminary
     * form and Financed come from the full form and from finance, never from this list; Canceled, Finished and Closed only follow funding;
     * a rejected or withdrawn application may be reopened to Waiting.
     */
    private const NEXT = [
        self::ON_HOLD => [self::WITHDREW, self::REJECTED],
        self::WAITING => [self::UNDER_REVIEW, self::DELAYED, self::REJECTED, self::WITHDREW],
        self::UNDER_REVIEW => [self::ACCEPTED, self::DELAYED, self::REJECTED, self::WITHDREW, self::WAITING],
        self::DELAYED => [self::WAITING, self::UNDER_REVIEW, self::ACCEPTED, self::REJECTED, self::WITHDREW],
        self::ACCEPTED => [self::WITHDREW, self::DELAYED],
        self::FINANCED => [self::CANCELED, self::CLOSED, self::FINISHED],
        self::FINISHED => [self::CLOSED],
        self::REJECTED => [self::WAITING],
        self::WITHDREW => [self::WAITING],
        self::CANCELED => [],
        self::CLOSED => [],
    ];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit, private NoticeService $notices) {}

    // ---------------------------------------------------------------- reading

    /** The project row, readable with `applications.view` in its city (others: 404, as CityScope). */
    public function project(WebAccount $account, int $projectId): object
    {
        $readable = $this->access->cities($account, 'applications.view')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $project = DB::table('projects')->where('id', $projectId)->whereNull('deleted_at')->whereIn('city_id', $readable ?: [0])->first();
        abort_if($project === null, 404);

        return $project;
    }

    public function canManage(WebAccount $account, int $city): bool
    {
        return $this->access->check($account, $city, 'lifecycle.manage');
    }

    /** Newest first, with the state and reason names. */
    public function history(int $projectId): Collection
    {
        return DB::table('project_state_history as h')->join('project_states as s', 's.id', '=', 'h.project_state_id')
            ->leftJoin('state_reasons as r', 'r.id', '=', 'h.state_reason_id')
            ->where('h.project_id', $projectId)->whereNull('h.deleted_at')
            ->orderByDesc('h.changed_on')->orderByDesc('h.id')
            ->get(['h.id', 'h.project_state_id', 'h.state_reason_id', 'h.note', 'h.changed_on', 'h.is_current', 's.name_ar as state_ar', 's.name_en as state_en',
                'r.name_ar as reason_ar', 'r.name_en as reason_en']);
    }

    /** The row flagged current, else the newest (old records without a flag). */
    public function current(Collection $history): ?object
    {
        return $history->first(fn ($row) => (bool) $row->is_current) ?? $history->first();
    }

    public function currentState(int $projectId): ?object
    {
        return $this->current($this->history($projectId));
    }

    /** A fingerprint of what the screen showed: a save over a state changed meanwhile is refused. */
    public function version(object $project, Collection $history): string
    {
        return hash('sha256', json_encode([$project->donor_id, $history->map(fn ($r) => [$r->id, $r->project_state_id, $r->state_reason_id, $r->note, (string) $r->changed_on, (bool) $r->is_current])->all()], JSON_THROW_ON_ERROR));
    }

    /** Read model of the project page's state card. Reasons and notes are shown to the managers only, as before. */
    public function panel(WebAccount $account, int $projectId): array
    {
        $project = $this->project($account, $projectId);
        $city = (int) $project->city_id;
        $history = $this->history($projectId);
        $current = $this->current($history);
        $canManage = $this->canManage($account, $city);
        $canAccept = $canManage && $this->access->check($account, $city, 'lifecycle.accept');

        // Reasons and notes stay with the managers: the masked rows are the only ones that reach the page (and the Livewire snapshot).
        $shown = $canManage ? $history : $history->map(fn ($row) => (object) (['state_reason_id' => null, 'reason_ar' => null, 'reason_en' => null, 'note' => null] + (array) $row));

        return [
            'project' => $project,
            'history' => $shown,
            'current' => $current === null ? null : $shown->firstWhere('id', $current->id),
            'version' => $this->version($project, $history),
            'canManage' => $canManage,
            'targets' => $canManage ? $this->targets($project, $current, $canAccept) : collect(),
            'undo' => $canManage ? $this->undoable($project, $history) : null,
            'reasons' => $canManage ? DB::table('state_reasons')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']) : collect(),
            'donors' => $canManage ? $this->donors($project) : collect(),
        ];
    }

    /** Active donors, plus the project's own one even when hidden since. */
    private function donors(object $project): Collection
    {
        return DB::table('donors')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $project->donor_id ?? 0))
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']);
    }

    /** States the manager may choose now. */
    private function targets(object $project, ?object $current, bool $canAccept): Collection
    {
        return DB::table('project_states')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en'])
            ->filter(fn ($state) => $this->refusal($project, $current, $state->name_en, $canAccept) === null)->values();
    }

    /** The states a manager may move a project to from its current one (a project without a state is treated as Waiting). */
    public static function allowedFrom(?string $from): array
    {
        return self::NEXT[$from ?? self::WAITING] ?? [];
    }

    /** Why a move is refused (a lifecycle.refusals key), or null when allowed. */
    public function refusal(object $project, ?object $current, ?string $target, bool $canAccept): ?string
    {
        $from = $current?->state_en;
        $reopen = $target === self::WAITING && in_array($from, [self::REJECTED, self::WITHDREW], true);

        return match (true) {
            $target === self::ON_HOLD => 'on_hold',
            $current !== null && $from === $target => 'same_state',
            $target === self::WAITING && $from === self::ON_HOLD => 'waiting_from_preliminary',
            $target === self::FINANCED && $from === self::ACCEPTED => 'financed_from_accepted',
            $target === self::FINANCED => 'financed_by_finance',
            // A preliminary application comes back through its full form, which records Waiting itself.
            $reopen && $project->application_stage !== 'full' => 'reopen_preliminary',
            ! in_array($target, self::allowedFrom($from), true) => 'not_allowed',
            $target === self::ACCEPTED && ! $canAccept => 'accept_capability',
            default => null,
        };
    }

    // ---------------------------------------------------------------- changing

    /** The administrator's state change. Accepted needs `$donorId`. */
    public function transition(WebAccount $account, int $projectId, int $stateId, ?int $reasonId, ?string $note, ?int $donorId, string $version): void
    {
        $this->authorizeManage($account, $projectId);
        DB::transaction(function () use ($account, $projectId, $stateId, $reasonId, $note, $donorId, $version) {
            $project = $this->locked($projectId);
            $city = (int) $project->city_id;
            $history = $this->history($projectId);
            $this->checkVersion($project, $history, $version);
            $state = DB::table('project_states')->where('id', $stateId)->first();
            if ($state === null) {
                throw ValidationException::withMessages(['state_id' => __('lifecycle.errors.state')]);
            }
            $current = $this->current($history);
            $refusal = $this->refusal($project, $current, $state->name_en, $this->access->check($account, $city, 'lifecycle.accept'));
            if ($refusal !== null) {
                throw ValidationException::withMessages(['state_id' => __('lifecycle.refusals.'.$refusal, ['from' => $current?->state_ar, 'to' => $state->name_ar])]);
            }
            $previousDonor = $project->donor_id;
            $opened = 0;
            $reason = $this->reason($reasonId);
            $details = [['field' => 'state', 'before' => $current?->state_ar, 'after' => $state->name_ar]];
            if ($reason !== null) {
                $details[] = ['field' => 'state_reason', 'before' => null, 'after' => $reason->name_ar];
            }
            if (($note ?? '') !== '') {
                $details[] = ['field' => 'state_note', 'before' => null, 'after' => $note];
            }

            if ($state->name_en === self::ACCEPTED) {
                $donor = $donorId === null ? null : $this->donors($project)->firstWhere('id', $donorId);
                if ($donor === null) {
                    throw ValidationException::withMessages(['donor_id' => __('lifecycle.errors.donor')]);
                }
                if ((int) $project->donor_id !== (int) $donor->id) {
                    $details[] = ['field' => 'donor', 'before' => $this->donorName($project->donor_id), 'after' => $donor->name_ar];
                    DB::table('projects')->where('id', $projectId)->update(['donor_id' => $donor->id, 'updated_at' => now()]);
                }
            }
            $row = $this->enter($project, (int) $state->id, $reason?->id, $note);
            if ($state->name_en === self::ACCEPTED) {
                $opened = $this->assignTasks($project, 'f');
                if ($opened > 0) {
                    $details[] = ['field' => 'tasks_opened', 'before' => null, 'after' => $opened];
                }
            }
            if ($state->name_en === self::WITHDREW && $project->donor_id !== null) {
                // Desktop: "the donor will be removed from the project".
                $details[] = ['field' => 'donor', 'before' => $this->donorName($project->donor_id), 'after' => null];
                DB::table('projects')->where('id', $projectId)->update(['donor_id' => null, 'donor_group_id' => null, 'updated_at' => now()]);
            }
            if (in_array($state->name_en, self::CLEARING, true)) {
                $this->notices->ofProject($project)->update(['seen' => 1]);
            }
            if (! in_array($state->name_en, self::SILENT, true)) {
                $body = 'تم تبديل حالة المشروع إلى: '.$state->name_ar;
                $this->notices->broadcast($city, 0, $project, 'state', NoticeService::STATE, now()->toDateString(), $this->beneficiaryName($projectId), $body, $account->id);
            }
            // What an undo needs, kept with the event: the history row it added, the donor before, whether it opened the follow-up tasks.
            $decision = 'lifecycle:row/'.$row.':donor/'.($previousDonor ?? 'none').':tasks/'.($opened > 0 ? 1 : 0);
            $this->audit->record($account->id, 'lifecycle.transitioned', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId, decision: $decision, details: $details);
        });
    }

    /**
     * Whether the last state change can be undone (owner decision, 21 Nov 2026): only a manager's own change, made today, that is still
     * the state in force; the follow-up tasks it opened must not have been started. Returns null when there is nothing to undo, else the
     * state it goes back to and, when blocked, why.
     *
     * @return ?array{back_ar: ?string, back_en: ?string, blocked: ?string, decision: string}
     */
    public function undoable(object $project, Collection $history): ?array
    {
        $current = $this->current($history);
        $previous = $current === null ? null : $history->first(fn ($row) => $row->id !== $current->id);
        if ($previous === null || substr((string) $current->changed_on, 0, 10) !== now()->toDateString()) {
            return null;
        }
        $event = DB::table('account_audit_events')->where('event_type', 'lifecycle.transitioned')->where('outcome', 'succeeded')
            ->where('subject_type', 'application_entity')->where('subject_id', $project->id)->orderByDesc('id')->first(['decision_locator']);
        $decision = (string) ($event->decision_locator ?? '');
        if (! str_starts_with($decision, 'lifecycle:row/'.$current->id.':')) {
            return null;
        }
        $blocked = null;
        if (str_ends_with($decision, ':tasks/1')) {
            $first = DB::table('task_definitions')->where('type', 'f')->orderBy('sort_order')->orderBy('id')->value('id');
            $started = DB::table('project_tasks as t')->join('task_definitions as d', 'd.id', '=', 't.task_definition_id')->where('t.project_id', $project->id)
                ->whereNull('t.deleted_at')->where('d.type', 'f')->where('t.task_definition_id', '<>', $first ?? 0)->where('t.state', 1)->exists();
            $blocked = $started ? 'tasks_started' : null;
        }

        return ['back_ar' => $previous->state_ar, 'back_en' => $previous->state_en, 'blocked' => $blocked, 'decision' => $decision];
    }

    /** Undoes the last state change: the previous state is in force again, with the donor it had; the tasks and notices it made go. */
    public function undo(WebAccount $account, int $projectId, string $version): void
    {
        $this->authorizeManage($account, $projectId);
        DB::transaction(function () use ($account, $projectId, $version) {
            $project = $this->locked($projectId);
            $history = $this->history($projectId);
            $this->checkVersion($project, $history, $version);
            $undo = $this->undoable($project, $history);
            if ($undo === null || $undo['blocked'] !== null) {
                throw ValidationException::withMessages(['undo' => __('lifecycle.undo.'.($undo['blocked'] ?? 'not_possible'))]);
            }
            $current = $this->current($history);
            $previous = $history->first(fn ($row) => $row->id !== $current->id);
            $now = now();
            DB::table('project_state_history')->where('id', $current->id)->update(['is_current' => false, 'deleted_at' => $now, 'updated_at' => $now]);
            DB::table('project_state_history')->where('id', $previous->id)->update(['is_current' => true, 'updated_at' => $now]);
            $details = [['field' => 'state', 'before' => $current->state_ar, 'after' => $previous->state_ar]];

            preg_match('/:donor\/(\d+|none):/', $undo['decision'], $m);
            $donor = ($m[1] ?? 'none') === 'none' ? null : (int) $m[1];
            if (($m[1] ?? null) !== null && $donor !== ($project->donor_id === null ? null : (int) $project->donor_id)) {
                $details[] = ['field' => 'donor', 'before' => $this->donorName($project->donor_id), 'after' => $this->donorName($donor)];
                DB::table('projects')->where('id', $projectId)->update(['donor_id' => $donor, 'updated_at' => $now]);
            }
            if (str_ends_with($undo['decision'], ':tasks/1')) {
                $removed = DB::table('project_tasks')->where('project_id', $projectId)->whereNull('deleted_at')
                    ->whereIn('task_definition_id', DB::table('task_definitions')->where('type', 'f')->select('id'))->update(['deleted_at' => $now, 'updated_at' => $now]);
                $details[] = ['field' => 'tasks_opened', 'before' => $removed, 'after' => 0];
            }
            // The "state changed" notices of the change are withdrawn if nobody read them yet.
            $this->notices->ofProject($project)->where('kind', NoticeService::STATE)->where('seen', false)
                ->where('body', 'تم تبديل حالة المشروع إلى: '.$current->state_ar)->where('notice_date', $now->toDateString())->delete();
            $this->audit->record($account->id, 'lifecycle.undone', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId, details: $details);
        });
    }

    /** Reason and note of the state row in force (the desktop's two columns beside the state). */
    public function updateCurrent(WebAccount $account, int $projectId, ?int $reasonId, ?string $note, string $version): void
    {
        $this->authorizeManage($account, $projectId);
        DB::transaction(function () use ($account, $projectId, $reasonId, $note, $version) {
            $project = $this->locked($projectId);
            $history = $this->history($projectId);
            $this->checkVersion($project, $history, $version);
            $current = $this->current($history);
            if ($current === null) {
                throw ValidationException::withMessages(['state_id' => __('lifecycle.none')]);
            }
            $reason = $this->reason($reasonId);
            $details = AccountAuditTrail::changes(['state_reason' => $current->reason_ar, 'state_note' => $current->note], ['state_reason' => $reason?->name_ar, 'state_note' => $note]);
            if ($details === null) {
                return;
            }
            DB::table('project_state_history')->where('id', $current->id)->update(['state_reason_id' => $reason?->id, 'note' => $note, 'updated_at' => now()]);
            $this->audit->record($account->id, 'lifecycle.reason_updated', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId, details: $details);
        });
    }

    /** Refuses (and records the refusal) before any transaction, so the audit row is not rolled back. */
    private function authorizeManage(WebAccount $account, int $projectId): void
    {
        $project = $this->project($account, $projectId);
        if (! $this->canManage($account, (int) $project->city_id)) {
            $this->audit->record($account->id, 'access.denied', 'denied', (int) $project->city_id);
            abort(403);
        }
    }

    private function locked(int $projectId): object
    {
        return DB::table('projects')->where('id', $projectId)->lockForUpdate()->first();
    }

    private function checkVersion(object $project, Collection $history, string $version): void
    {
        if (! hash_equals($this->version($project, $history), $version)) {
            throw ValidationException::withMessages(['version' => __('lifecycle.errors.stale')]);
        }
    }

    private function reason(?int $reasonId): ?object
    {
        if ($reasonId === null) {
            return null;
        }
        $reason = DB::table('state_reasons')->where('id', $reasonId)->first(['id', 'name_ar', 'name_en']);
        if ($reason === null) {
            throw ValidationException::withMessages(['reason_id' => __('lifecycle.errors.reason')]);
        }

        return $reason;
    }

    private function donorName(?int $donorId): ?string
    {
        return $donorId === null ? null : DB::table('donors')->where('id', $donorId)->value('name_ar');
    }

    /** "first last ابن/ة father" of the beneficiary (the desktop's notice title), or ''. */
    public function beneficiaryName(int $projectId): string
    {
        $person = DB::table('project_applicants as a')->join('people as p', 'p.id', '=', 'a.person_id')->where('a.project_id', $projectId)->whereNull('a.deleted_at')
            ->orderByRaw("CASE WHEN a.role = 'مستفيد' THEN 0 ELSE 1 END")->orderBy('a.id')->first(['p.first_name', 'p.last_name', 'p.father_name']);

        return $person === null ? '' : trim(trim(($person->first_name ?? '').' '.($person->last_name ?? '')).' ابن/ة '.($person->father_name ?? ''));
    }

    /** Ends the current state and records the new one; returns the new row's id. The caller holds the project lock. */
    private function enter(object $project, int $stateId, ?int $reasonId, ?string $note): int
    {
        $now = now();
        DB::table('project_state_history')->where('project_id', $project->id)->where('is_current', true)->update(['is_current' => false, 'updated_at' => $now]);

        return (int) DB::table('project_state_history')->insertGetId([
            'city_id' => $project->city_id, 'project_id' => $project->id, 'project_state_id' => $stateId, 'state_reason_id' => $reasonId, 'note' => $note,
            'changed_on' => $now->format('Y-m-d'), 'is_current' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * Opens every active task of the type once ("a": the study, on the full application; "f": the follow-up, on acceptance). A retry or a
     * second acceptance adds nothing. "Project accepted" (the first "f" task) is opened done, as the desktop's T_ID 1. Returns how many.
     */
    public function assignTasks(object $project, string $type): int
    {
        $have = DB::table('project_tasks')->where('project_id', $project->id)->whereNull('deleted_at')->pluck('task_definition_id')->map(fn ($id) => (int) $id)->all();
        $first = DB::table('task_definitions')->where('type', 'f')->orderBy('sort_order')->orderBy('id')->value('id');
        $added = 0;
        $now = now();
        foreach (DB::table('task_definitions')->where('type', $type)->where('is_active', true)->where('name_ar', '<>', '')->orderBy('sort_order')->orderBy('id')->pluck('id') as $task) {
            if (in_array((int) $task, $have, true)) {
                continue;
            }
            DB::table('project_tasks')->insert(['city_id' => $project->city_id, 'project_id' => $project->id, 'task_definition_id' => $task,
                'state' => (int) $task === (int) $first ? 1 : 0, 'task_date' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $added++;
        }

        return $added;
    }

    // ---------------------------------------------------------------- moves made by other modules (callers authorize and hold the lock)

    /** First state of a new application (On Hold, or Waiting for one saved full). */
    public function recordInitial(object $project, string $nameEn): void
    {
        $state = DB::table('project_states')->where('name_en', $nameEn)->value('id');
        if ($state !== null) {
            $this->enter($project, (int) $state, null, null);
        }
    }

    /** Preliminary → full: Waiting and the study ("a") tasks. */
    public function enterFull(object $project): void
    {
        $this->recordInitial($project, self::WAITING);
        $this->assignTasks($project, 'a');
    }

    /** A move driven by finance or visits (Financed on the first loan, Finished on the last payment); false when already there. */
    public function enterNamed(object $project, string $nameEn): bool
    {
        if ($this->currentState((int) $project->id)?->state_en === $nameEn) {
            return false;
        }
        $this->recordInitial($project, $nameEn);

        return true;
    }
}
