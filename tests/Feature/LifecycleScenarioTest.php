<?php

namespace Tests\Feature;

use App\Livewire\ProjectLifecycle;
use App\Livewire\ProjectNextStep;
use App\Livewire\ProjectTasks;
use App\Models\WebAccount;
use App\Services\Lifecycle\LifecycleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M4.8 user scenarios: the administrator moves an application along its path (study, decision, acceptance with its donor, withdrawal),
 * staff see the state and the next step, and whoever is responsible for a task ticks it on the project page.
 */
class LifecycleScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function as(string $username): WebAccount
    {
        $account = WebAccount::where('username', $username)->firstOrFail();
        $this->actingAs($account);

        return $account;
    }

    private function state(string $nameEn): int
    {
        return (int) DB::table('project_states')->where('name_en', $nameEn)->value('id');
    }

    /** A project with a beneficiary, put in the given state with no loan, tasks or checklist. */
    private function project(string $stateEn): object
    {
        $project = DB::table('projects')->whereExists(fn ($q) => $q->selectRaw('1')->from('project_applicants as a')->whereColumn('a.project_id', 'projects.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('loans as l')->whereColumn('l.project_id', 'projects.id'))->orderBy('id')->first();
        DB::table('project_state_history')->where('project_id', $project->id)->delete();
        DB::table('project_tasks')->where('project_id', $project->id)->delete();
        DB::table('checklists')->where('project_id', $project->id)->delete();
        DB::table('projects')->where('id', $project->id)->update(['donor_id' => null]);
        app(LifecycleService::class)->recordInitial($project, $stateEn);

        return DB::table('projects')->where('id', $project->id)->first();
    }

    private function current(int $projectId): ?string
    {
        return DB::table('project_state_history as h')->join('project_states as s', 's.id', '=', 'h.project_state_id')
            ->where('h.project_id', $projectId)->where('h.is_current', true)->value('s.name_en');
    }

    public function test_administrator_studies_then_accepts_with_the_donor_and_follow_up_tasks_open(): void
    {
        $this->as('role.administrator');
        $project = $this->project('Waiting');
        $this->get('/ar/projects/'.$project->id)->assertOk()->assertSeeLivewire(ProjectLifecycle::class)->assertSeeLivewire(ProjectTasks::class)->assertSee(__('lifecycle.change', [], 'ar'));

        $panel = Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])
            ->set('change.state_id', (string) $this->state('Under Review'))->set('change.note', 'بدأت الدراسة')->call('changeState')->assertHasNoErrors()->assertRedirect();
        $this->assertSame('Under Review', $this->current($project->id));
        $this->assertSame(1, DB::table('project_state_history')->where('project_id', $project->id)->where('is_current', true)->count());
        // Every staff role of the city is told, guests are not.
        $guest = WebAccount::where('username', 'role.guest')->value('id');
        $this->assertGreaterThan(0, DB::table('web_notices')->where('project_id', $project->id)->where('kind', 'state')->count());
        $this->assertSame(0, DB::table('web_notices')->where('project_id', $project->id)->where('account_id', $guest)->count());
        $event = DB::table('account_audit_events')->where('event_type', 'lifecycle.transitioned')->latest('id')->first();
        $this->assertStringContainsString('قيد الدراسة', (string) $event->details);

        // Accepting asks for the donor.
        $accepted = (string) $this->state('Accepted');
        Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])
            ->set('change.state_id', $accepted)->assertSee(__('lifecycle.donor', [], 'ar'))->call('changeState')->assertHasErrors('change.donor_id');
        $this->assertSame('Under Review', $this->current($project->id));
        $donor = (int) DB::table('donors')->where('is_active', true)->orderBy('id')->value('id');
        Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])
            ->set('change.state_id', $accepted)->set('change.donor_id', (string) $donor)->call('changeState')->assertHasNoErrors();
        $this->assertSame('Accepted', $this->current($project->id));
        $this->assertSame($donor, (int) DB::table('projects')->where('id', $project->id)->value('donor_id'));
        $follow = DB::table('project_tasks as t')->join('task_definitions as d', 'd.id', '=', 't.task_definition_id')->where('t.project_id', $project->id)->where('d.type', 'f')->orderBy('d.sort_order')->get(['t.state', 'd.name_ar']);
        $this->assertSame(DB::table('task_definitions')->where('type', 'f')->where('is_active', true)->where('name_ar', '<>', '')->count(), $follow->count());
        $this->assertSame(1, (int) $follow->first()->state, '"Project accepted" opens done.');
        $this->assertSame(1, $follow->where('state', 1)->count());

        // Accepted → Financed is left to finance (the loan), and On Hold is never offered.
        $targets = Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])->viewData('panel')['targets']->pluck('name_en')->all();
        $this->assertNotContains('Financed', $targets);
        $this->assertNotContains('On Hold', $targets);
        $this->assertNotContains('Accepted', $targets);
        Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])->set('change.state_id', (string) $this->state('Financed'))->call('changeState')
            ->assertHasErrors(['change.state_id']);
    }

    public function test_preliminary_cannot_be_moved_to_waiting_by_hand(): void
    {
        $this->as('role.administrator');
        $project = $this->project('On Hold');
        Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])->set('change.state_id', (string) $this->state('Waiting'))->call('changeState')
            ->assertHasErrors(['change.state_id']);
        $this->assertSame('On Hold', $this->current($project->id));
    }

    public function test_withdrawal_removes_the_donor_and_marks_the_project_notices_seen(): void
    {
        $this->as('role.administrator');
        $project = $this->project('Accepted');
        DB::table('projects')->where('id', $project->id)->update(['donor_id' => DB::table('donors')->value('id')]);
        DB::table('web_notices')->insert(['city_id' => $project->city_id, 'project_id' => $project->id, 'kind' => 'visit', 'notice_date' => '2026-12-01', 'title' => 't', 'body' => 'Visit Opening-O', 'seen' => false, 'retired_by_kobo' => false, 'created_at' => now()]);
        $reason = (string) DB::table('state_reasons')->where('id', '>', 1)->value('id');
        Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])
            ->set('change.state_id', (string) $this->state('Withdrew'))->set('change.reason_id', $reason)->call('changeState')->assertHasNoErrors();
        $this->assertSame('Withdrew', $this->current($project->id));
        $this->assertNull(DB::table('projects')->where('id', $project->id)->value('donor_id'));
        // The earlier notices are cleared; the "state changed" notice is then sent, unseen, as the desktop did.
        $this->assertSame(0, DB::table('web_notices')->where('project_id', $project->id)->where('kind', '<>', 'state')->where('seen', false)->count());
        $this->assertGreaterThan(0, DB::table('web_notices')->where('project_id', $project->id)->where('kind', 'state')->where('seen', false)->count());
        $this->assertSame((int) $reason, (int) DB::table('project_state_history')->where('project_id', $project->id)->where('is_current', true)->value('state_reason_id'));
    }

    public function test_reason_and_note_of_the_current_state_are_edited_and_a_stale_screen_is_refused(): void
    {
        $this->as('role.administrator');
        $project = $this->project('Delayed');
        $reason = (string) DB::table('state_reasons')->orderBy('id', 'desc')->value('id');
        $stale = Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id]);
        Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])
            ->set('currentForm.reason_id', $reason)->set('currentForm.note', 'بانتظار أوراق')->call('saveCurrent')->assertHasNoErrors()->assertSee(__('lifecycle.reason_saved', [], 'ar'));
        $row = DB::table('project_state_history')->where('project_id', $project->id)->where('is_current', true)->first();
        $this->assertSame((int) $reason, (int) $row->state_reason_id);
        $this->assertSame('بانتظار أوراق', $row->note);
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'lifecycle.reason_updated')->exists());
        // The other screen still shows the old state: its change is refused.
        $stale->set('change.state_id', (string) $this->state('Rejected'))->call('changeState')->assertHasErrors('version');
        $this->assertSame('Delayed', $this->current($project->id));
    }

    public function test_data_entry_sees_the_state_but_cannot_change_it(): void
    {
        $this->as('role.administrator');
        $project = $this->project('Delayed');
        DB::table('project_state_history')->where('project_id', $project->id)->update(['note' => 'ملاحظة داخلية']);
        $this->as('role.data-entry');
        $this->get('/ar/projects/'.$project->id)->assertOk()->assertSee(__('lifecycle.title', [], 'ar'))->assertDontSee(__('lifecycle.change', [], 'ar'))->assertDontSee('ملاحظة داخلية');
        Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])->set('change.state_id', (string) $this->state('Rejected'))->call('changeState')->assertForbidden();
        $this->assertSame('Delayed', $this->current($project->id));
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'access.denied')->exists());
    }

    public function test_tasks_are_ticked_by_the_responsible_account_only(): void
    {
        $admin = $this->as('role.administrator');
        $project = $this->project('Waiting');
        app(LifecycleService::class)->assignTasks($project, 'a');
        $task = DB::table('project_tasks')->where('project_id', $project->id)->orderBy('id')->first();

        // Monitoring holds tasks.manage but is not responsible for the study task yet.
        $monitor = $this->as('role.monitoring');
        $card = Livewire::test(ProjectTasks::class, ['projectId' => $project->id])->assertSee(__('lifecycle.tasks.not_yours', [], 'ar'));
        $card->call('toggle', $task->id, true)->assertForbidden();
        $this->assertSame(0, (int) DB::table('project_tasks')->where('id', $task->id)->value('state'));

        DB::table('account_city_tasks')->insert(['account_id' => $monitor->id, 'city_id' => $project->city_id, 'task_definition_id' => $task->task_definition_id, 'assigned_at' => now()]);
        Livewire::test(ProjectTasks::class, ['projectId' => $project->id])->call('toggle', $task->id, true)->assertDispatched('project-progress');
        $this->assertSame(1, (int) DB::table('project_tasks')->where('id', $task->id)->value('state'));
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'tasks.toggled')->where('actor_account_id', $monitor->id)->exists());

        // The administrator (tasks.manage_all) may tick any of them; guests do not see tasks at all.
        $this->actingAs($admin);
        Livewire::test(ProjectTasks::class, ['projectId' => $project->id])->call('toggle', $task->id, false);
        $this->assertSame(0, (int) DB::table('project_tasks')->where('id', $task->id)->value('state'));
        $this->as('role.guest');
        Livewire::test(ProjectTasks::class, ['projectId' => $project->id])->assertDontSee(__('lifecycle.tasks.title', [], 'ar'));
    }

    public function test_data_entry_ticks_the_study_tasks_it_is_responsible_for(): void
    {
        $this->as('role.administrator');
        $project = $this->project('Waiting');
        app(LifecycleService::class)->assignTasks($project, 'a');
        [$mine, $other] = DB::table('project_tasks')->where('project_id', $project->id)->orderBy('id')->limit(2)->get()->all();

        $entry = $this->as('role.data-entry');
        DB::table('account_city_tasks')->insert(['account_id' => $entry->id, 'city_id' => $project->city_id, 'task_definition_id' => $mine->task_definition_id, 'assigned_at' => now()]);
        Livewire::test(ProjectTasks::class, ['projectId' => $project->id])->call('toggle', $mine->id, true)->assertDispatched('project-progress');
        $this->assertSame(1, (int) DB::table('project_tasks')->where('id', $mine->id)->value('state'));
        Livewire::test(ProjectTasks::class, ['projectId' => $project->id])->call('toggle', $other->id, true)->assertForbidden();
        $this->assertSame(0, (int) DB::table('project_tasks')->where('id', $other->id)->value('state'));
    }

    public function test_next_step_names_the_role_it_waits_on(): void
    {
        $this->as('role.data-entry');
        $project = $this->project('On Hold');
        Livewire::test(ProjectNextStep::class, ['projectId' => $project->id])->assertSee(__('lifecycle.next.complete_full', [], 'ar'))->assertSee(__('admin.bundles.data-entry', [], 'ar'))
            ->assertSee(route('applications.edit', ['locale' => 'ar', 'project' => $project->id]));

        $this->as('role.administrator');
        $waiting = $this->project('Waiting');
        Livewire::test(ProjectNextStep::class, ['projectId' => $waiting->id])->assertSee(__('lifecycle.next.checklist', [], 'ar'));

        $accepted = $this->project('Accepted');
        Livewire::test(ProjectNextStep::class, ['projectId' => $accepted->id])->assertSee(__('lifecycle.next.loan', [], 'ar'))->assertSee(__('admin.bundles.financial', [], 'ar'))
            ->assertDontSee('role.financial');

        $rejected = $this->project('Rejected');
        Livewire::test(ProjectNextStep::class, ['projectId' => $rejected->id])->assertDontSee(__('hc.next_step', [], 'ar'));
    }

    /** @return list<string> the states the administrator is offered */
    private function offered(int $projectId): array
    {
        $names = Livewire::test(ProjectLifecycle::class, ['projectId' => $projectId])->viewData('panel')['targets']->pluck('name_en')->all();
        sort($names);

        return $names;
    }

    public function test_only_the_next_states_on_the_path_are_offered(): void
    {
        $this->as('role.administrator');
        $project = $this->project('On Hold');
        $this->assertSame(['Rejected', 'Withdrew'], $this->offered($project->id));
        // Canceled and Closed only follow funding, even when posted directly.
        foreach (['Canceled', 'Closed', 'Finished', 'Accepted'] as $state) {
            Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])->set('change.state_id', (string) $this->state($state))->call('changeState')
                ->assertHasErrors(['change.state_id']);
        }
        $this->assertSame('On Hold', $this->current($project->id));

        $this->assertSame(['Delayed', 'Rejected', 'Under Review', 'Withdrew'], $this->offered($this->project('Waiting')->id));
        $this->assertSame(['Canceled', 'Closed', 'Finished'], $this->offered($this->project('Financed')->id));
        $this->assertSame(['Closed'], $this->offered($this->project('Finished')->id));
        $this->assertSame([], $this->offered($this->project('Canceled')->id));
    }

    public function test_a_rejected_full_application_can_be_reopened_but_a_preliminary_one_returns_through_its_form(): void
    {
        $this->as('role.administrator');
        $project = $this->project('Rejected');
        DB::table('projects')->where('id', $project->id)->update(['application_stage' => 'full']);
        $this->assertSame(['Waiting'], $this->offered($project->id));
        Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])->set('change.state_id', (string) $this->state('Waiting'))->call('changeState')->assertHasNoErrors();
        $this->assertSame('Waiting', $this->current($project->id));

        DB::table('projects')->where('id', $project->id)->update(['application_stage' => 'preliminary']);
        $withdrawn = $this->project('Withdrew');
        DB::table('projects')->where('id', $withdrawn->id)->update(['application_stage' => 'preliminary']);
        $this->assertSame([], $this->offered($withdrawn->id));
    }

    public function test_the_last_change_can_be_undone_the_same_day(): void
    {
        $this->as('role.administrator');
        $project = $this->project('Under Review');
        // A state recorded by the application form (not a manager's change) offers no undo.
        $this->assertNull(Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])->viewData('panel')['undo']);

        $donor = (int) DB::table('donors')->orderBy('id')->value('id');
        Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])
            ->set('change.state_id', (string) $this->state('Accepted'))->set('change.donor_id', (string) $donor)->call('changeState')->assertHasNoErrors();
        $this->assertGreaterThan(0, DB::table('web_notices')->where('project_id', $project->id)->where('kind', 'state')->count());

        Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])->assertSee(__('lifecycle.undo.button', ['state' => 'قيد الدراسة'], 'ar'))
            ->call('undoLast')->assertHasNoErrors()->assertRedirect();
        $this->assertSame('Under Review', $this->current($project->id));
        $this->assertNull(DB::table('projects')->where('id', $project->id)->value('donor_id'), 'The donor goes back to what it was.');
        $this->assertSame(0, DB::table('project_tasks')->where('project_id', $project->id)->whereNull('deleted_at')->count());
        $this->assertSame(0, DB::table('web_notices')->where('project_id', $project->id)->where('kind', 'state')->count());
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'lifecycle.undone')->where('subject_id', $project->id)->exists());
        // Nothing more to undo: the earlier state was not a manager's change.
        $this->assertNull(Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])->viewData('panel')['undo']);
    }

    public function test_acceptance_cannot_be_undone_once_follow_up_work_started(): void
    {
        $this->as('role.administrator');
        $project = $this->project('Delayed');
        Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])
            ->set('change.state_id', (string) $this->state('Accepted'))->set('change.donor_id', (string) DB::table('donors')->value('id'))->call('changeState');
        $second = DB::table('project_tasks as t')->join('task_definitions as d', 'd.id', '=', 't.task_definition_id')->where('t.project_id', $project->id)
            ->where('d.type', 'f')->orderBy('d.sort_order')->skip(1)->value('t.id');
        Livewire::test(ProjectTasks::class, ['projectId' => $project->id])->call('toggle', $second, true);
        Livewire::test(ProjectLifecycle::class, ['projectId' => $project->id])->assertSee(__('lifecycle.undo.tasks_started', [], 'ar'))
            ->call('undoLast')->assertHasErrors('undo');
        $this->assertSame('Accepted', $this->current($project->id));
    }

    public function test_full_application_opens_the_study_tasks_once(): void
    {
        $this->as('role.administrator');
        $project = $this->project('On Hold');
        app(LifecycleService::class)->enterFull($project);
        app(LifecycleService::class)->assignTasks($project, 'a');
        $this->assertSame('Waiting', $this->current($project->id));
        $this->assertSame(DB::table('task_definitions')->where('type', 'a')->where('is_active', true)->count(), DB::table('project_tasks')->where('project_id', $project->id)->count());
    }
}
