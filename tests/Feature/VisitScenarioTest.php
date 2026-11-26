<?php

namespace Tests\Feature;

use App\Livewire\MeVisitForm;
use App\Livewire\ProjectVisits;
use App\Livewire\VisitForm;
use App\Livewire\VisitorStatistics;
use App\Livewire\VisitResults;
use App\Livewire\VisitSchedule;
use App\Models\WebAccount;
use App\Services\Field\MonitoringVisitService;
use App\Services\Field\VisitService;
use App\Services\Lifecycle\LifecycleService;
use App\Services\ProjectNumberAllocator;
use App\Services\Staff\ProjectNotices;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M4.12 user scenarios: data entry records the opening (task, notice, place), M&E visits for the beneficiary and the partner (result and
 * evaluation) and the closing (Finished, notices); monitoring records but cannot delete; the administrator deletes (task unticked, notice
 * back); rehabilitation and vehicle projects get their own forms; duplicates, bad dates and missing visitors are refused; Kobo visits are
 * read-only; the schedule, the results report and the visitor statistics; refused roles and another city.
 */
class VisitScenarioTest extends TestCase
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

    private function visitor(): int
    {
        return (int) WebAccount::where('username', 'role.monitoring')->value('id');
    }

    /** A financed project (loan of 2026-01-10) with its follow-up tasks and visit notices, of the chosen kind. */
    private function project(int $skip = 0, string $kind = 'other'): object
    {
        $project = DB::table('projects')->whereExists(fn ($q) => $q->selectRaw('1')->from('project_applicants as a')->whereColumn('a.project_id', 'projects.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('loans as l')->whereColumn('l.project_id', 'projects.id'))->orderBy('id')->skip($skip)->first();
        foreach (['project_state_history', 'project_tasks', 'web_notices', 'visits', 'me_visits'] as $table) {
            DB::table($table)->where('project_id', $project->id)->delete();
        }
        $sub = DB::table('project_subcategories')->where('name_ar', $kind === 'vehicle' ? 'Taxi' : 'Shop')->value('id');
        DB::table('projects')->where('id', $project->id)->update([
            'project_subcategory_id' => $sub, 'donor_id' => DB::table('donors')->orderBy('id')->value('id'),
            'project_type_id' => DB::table('project_types')->where('name_ar', $kind === 'rehab' ? 'ترميم' : 'مشاريع صغيرة')->value('id'),
            'fund_type_id' => DB::table('fund_types')->where('name_ar', $kind === 'rehab' ? 'منحة' : 'قرض')->value('id'),
        ]);
        $project = DB::table('projects')->where('id', $project->id)->first();
        $lifecycle = app(LifecycleService::class);
        $lifecycle->recordInitial($project, 'Accepted');
        $lifecycle->assignTasks($project, 'f');
        DB::table('loans')->insert(['city_id' => $project->city_id, 'project_id' => $project->id, 'currency' => 'USD', 'amount' => 1000, 'taken_on' => '2026-01-10', 'created_at' => now(), 'updated_at' => now()]);
        $lifecycle->recordInitial($project, 'Financed');
        app(ProjectNotices::class)->generateVisitSchedule($project, '2026-01-10', null);

        return $project;
    }

    private function state(int $projectId): ?string
    {
        return DB::table('project_state_history as h')->join('project_states as s', 's.id', '=', 'h.project_state_id')->where('h.project_id', $projectId)->where('h.is_current', true)->value('s.name_en');
    }

    private function task(int $projectId, string $name): int
    {
        return (int) DB::table('project_tasks as t')->join('task_definitions as d', 'd.id', '=', 't.task_definition_id')->where('t.project_id', $projectId)->where('d.name_ar', $name)->value('t.state');
    }

    private function notice(int $projectId, string $body): ?int
    {
        $seen = DB::table('web_notices')->where('project_id', $projectId)->whereNull('account_id')->where('body', $body)->value('seen');

        return $seen === null ? null : (int) $seen;
    }

    private function opening(object $project, array $extra = [])
    {
        $street = DB::table('streets')->where('city_id', $project->city_id)->orderByDesc('id')->value('id');
        $page = Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'O-op'])->set('date', '2026-02-01')->set('q.1.value', '1')->set('q.1.text', 'افتُتح في الموعد')
            ->set('q.4.value', '3')->set('office.1.value', '2')->set('street_id', (string) $street)->set('place_note', 'شارع جديد')->set('visitors.0', (string) $this->visitor());
        foreach ($extra as $key => $value) {
            $page->set($key, $value);
        }

        return $page->call('save');
    }

    /** Fills every M&E question with its first answer. */
    private function meForm(object $project, int $person, string $profit = '100000', ?string $number = null)
    {
        $page = Livewire::test(MeVisitForm::class, ['projectId' => $project->id, 'personId' => $person, 'visitId' => null, 'suggested' => $number]);
        foreach (app(MonitoringVisitService::class)->questionnaire('o') as $section) {
            foreach ($section->questions as $q) {
                $page->set('a.'.$q->id, (string) $q->answers->first()->id);
            }
        }

        return $page->set('date', '2026-07-15')->set('profit', $profit)->set('visitors.0.account', (string) $this->visitor())->set('visitors.0.mark', 'جيد');
    }

    private function beneficiary(object $project): int
    {
        return (int) DB::table('project_applicants')->where('project_id', $project->id)->where('role', 'مستفيد')->value('person_id');
    }

    public function test_data_entry_records_the_opening_with_task_notice_and_place(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $this->get('/ar/projects/'.$project->id)->assertOk()->assertSee(route('visits.hub', ['locale' => 'ar', 'project' => $project->id]));
        $this->get('/ar/applications/'.$project->id.'/visits')->assertOk()->assertSeeLivewire(ProjectVisits::class)->assertSee(__('visits.add.opening', [], 'ar'));
        $this->get('/en/applications/'.$project->id.'/visits/O-op')->assertOk()->assertSeeLivewire(VisitForm::class)->assertSee(__('visits.office.other.5', [], 'en'));
        $this->assertSame(0, $this->notice($project->id, 'Visit Opening-O'));

        $this->opening($project)->assertHasNoErrors()->assertSee(__('visits.saved'));
        $visit = DB::table('visits')->where('project_id', $project->id)->first();
        $this->assertSame('O-op', $visit->kind);
        $this->assertSame('role.data-entry', $visit->created_by_name);
        $this->assertSame(1, $this->task($project->id, 'Opening Visit'), 'The opening task is ticked.');
        $this->assertSame(1, $this->notice($project->id, 'Visit Opening-O'), 'The opening notice is seen.');
        $this->assertSame(0, $this->notice($project->id, 'Visit M1-O'));
        $row = DB::table('projects')->where('id', $project->id)->first();
        $this->assertSame('شارع جديد', $row->address_after_fund);
        $this->assertSame(1, DB::table('visit_visitors')->where('visit_id', $visit->id)->where('account_id', $this->visitor())->count());
        $this->assertSame(3, (int) DB::table('visit_answers')->where('visit_id', $visit->id)->where('form', 'visit')->where('question_number', 4)->value('answer_value'));
        $this->assertSame(2, (int) DB::table('visit_answers')->where('visit_id', $visit->id)->where('form', 'office_monitoring')->where('question_number', 1)->value('answer_value'));
        $event = DB::table('account_audit_events')->where('event_type', 'visits.saved')->latest('id')->first();
        $this->assertStringContainsString('"field":"address_after_fund"', (string) $event->details);
        $this->assertStringContainsString('"field":"task"', (string) $event->details);

        // Editing changes only what changed; the edit is recorded under the editor's name.
        Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'O-op'])->set('q.1.value', '0')->call('save')->assertHasNoErrors();
        $this->assertSame(0, (int) DB::table('visit_answers')->where('visit_id', $visit->id)->where('form', 'visit')->where('question_number', 1)->value('answer_value'));
        $this->assertSame('role.data-entry', DB::table('visits')->where('id', $visit->id)->value('edited_by_name'));

        // A second opening is refused (the project became a vehicle project meanwhile, so the V form is asked for).
        DB::table('projects')->where('id', $project->id)->update(['project_subcategory_id' => DB::table('project_subcategories')->where('name_ar', 'Taxi')->value('id')]);
        Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'V-op'])->assertNotFound();
        $this->expectException(ValidationException::class);
        app(VisitService::class)->save(WebAccount::where('username', 'role.data-entry')->first(), $project->id, 'V-op',
            ['date' => '2026-02-02', 'vehicle' => 'X1', 'visitors' => [$this->visitor()]], app(VisitService::class)->version(DB::table('projects')->find($project->id), app(VisitService::class)->visits($project->id)));
    }

    public function test_dates_and_visitors_are_checked(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $this->opening($project, ['date' => now()->addDay()->toDateString()])->assertHasErrors('date');
        $this->opening($project, ['date' => '2025-12-31'])->assertHasErrors('date');
        $this->opening($project, ['date' => ''])->assertHasErrors('date');
        $this->opening($project, ['visitors.0' => ''])->assertHasErrors('visitors');
        $this->opening($project, ['visitors.1' => (string) $this->visitor()])->assertHasErrors('visitors');
        // An account without visits.visitor cannot be chosen.
        $this->opening($project, ['visitors.0' => (string) WebAccount::where('username', 'role.guest')->value('id')])->assertHasErrors('visitors');
        $this->assertSame(0, DB::table('visits')->where('project_id', $project->id)->count());
    }

    public function test_me_visits_for_the_beneficiary_and_the_partner_with_result_and_evaluation(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $beneficiary = $this->beneficiary($project);
        $partner = DB::table('people')->where('city_id', $project->city_id)->where('id', '<>', $beneficiary)->orderBy('id')->value('id');
        DB::table('project_applicants')->insert(['city_id' => $project->city_id, 'project_id' => $project->id, 'person_id' => $partner, 'role' => 'شريك', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/ar/applications/'.$project->id.'/me-visits/new/'.$beneficiary)->assertOk()->assertSeeLivewire(MeVisitForm::class);

        // Required: every question and the profit.
        $this->meForm($project, $beneficiary)->set('profit', '')->call('save')->assertHasErrors('profit');
        $first = app(MonitoringVisitService::class)->questionnaire('o')->first()->questions->first();
        $this->meForm($project, $beneficiary)->set('a.'.$first->id, '')->call('save')->assertHasErrors('a.'.$first->id);
        $this->meForm($project, $beneficiary)->set('visitors.0.mark', '')->call('save')->assertHasErrors('visitors');

        $this->meForm($project, $beneficiary)->call('save')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(['id' => $partner, 'number' => '1'], array_intersect_key(session('partner'), ['id' => 0, 'number' => 0]), 'The partner is offered the same visit number.');
        $visit = DB::table('me_visits')->where('project_id', $project->id)->where('person_id', $beneficiary)->first();
        $marks = app(MonitoringVisitService::class)->questionnaire('o')->flatMap(fn ($s) => $s->questions)->sum(fn ($q) => $q->answers->first()->mark);
        $this->assertSame($marks + 4, (int) $visit->result, 'Result = answer marks + the profit range mark (70,001–150,000 → 4).');
        $this->assertSame('o', $visit->kind);
        $this->assertSame('1', (string) $visit->visit_number);
        $this->assertSame('جيد', DB::table('me_visit_visitors')->where('me_visit_id', $visit->id)->value('mark'));
        $this->assertSame(1, $this->task($project->id, 'M1'));
        $this->assertSame(1, $this->notice($project->id, 'Visit M1-O'));
        $this->get('/en/applications/'.$project->id.'/me-visits/'.$visit->id)->assertOk()->assertSee(__('visits.me.partner_go', [], 'en'));

        // The partner gets visit 1 too; the same number again for the beneficiary is refused; a negative profit is accepted.
        $this->meForm($project, $partner, '-500', '1')->assertSet('number', '1')->call('save')->assertHasNoErrors();
        $this->assertSame(1, DB::table('me_visits')->where('person_id', $partner)->where('visit_number', '1')->count());
        $this->meForm($project, $beneficiary)->set('number', '1')->call('save')->assertHasErrors('number');
        $hub = Livewire::test(ProjectVisits::class, ['projectId' => $project->id]);
        $hub->assertSee('M2')->assertSee('شريك');
        $event = DB::table('account_audit_events')->where('event_type', 'me_visits.saved')->latest('id')->first();
        $this->assertStringContainsString('"field":"result"', (string) $event->details);
    }

    /** Found on the transferred data (M5): some desktop monitoring visits have three visitors. */
    public function test_an_older_monitoring_visit_with_three_visitors_can_be_saved_keeping_all(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $beneficiary = $this->beneficiary($project);
        $this->meForm($project, $beneficiary)->call('save')->assertHasNoErrors();
        $visit = DB::table('me_visits')->where('project_id', $project->id)->first();
        $others = DB::table('web_accounts')->where('id', '<>', $this->visitor())->orderBy('id')->limit(2)->pluck('id');
        foreach ($others as $account) {
            DB::table('me_visit_visitors')->insert(['city_id' => $project->city_id, 'me_visit_id' => $visit->id, 'account_id' => $account, 'mark' => 'جيد', 'created_at' => now(), 'updated_at' => now()]);
        }
        $page = Livewire::test(MeVisitForm::class, ['projectId' => $project->id, 'personId' => $beneficiary, 'visitId' => $visit->id])
            ->assertSee('id="me-visitor-2"', false)->set('notes', 'مراجعة')->call('save')->assertHasNoErrors();
        $this->assertSame(3, DB::table('me_visit_visitors')->where('me_visit_id', $visit->id)->whereNull('deleted_at')->count());
        // A new visit still takes two.
        $this->meForm($project, $beneficiary, '100000', '2')->set('visitors', array_map(fn ($id) => ['account' => (string) $id, 'mark' => 'جيد'], [$this->visitor(), ...$others]))
            ->call('save')->assertHasErrors('visitors');
    }

    public function test_closing_finishes_a_financed_project_once_and_clears_its_notices(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $page = Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'O-cl'])->assertSee(__('visits.closing_effect'))
            ->set('date', '2026-10-01')->set('q.1.value', '0')->set('q.2.value', '3')->set('q.6.text', 'أمل كبير')->set('indicators', 'لا ملاحظات')
            ->set('office.1.value', '2')->set('visitors.0', (string) $this->visitor())->call('save')->assertHasNoErrors();
        $visit = DB::table('visits')->where('project_id', $project->id)->where('kind', 'O-cl')->first();
        $this->assertSame('لا ملاحظات', $visit->indicators, 'Question 8 is the indicators column.');
        $this->assertSame('أمل كبير', DB::table('visit_answers')->where('visit_id', $visit->id)->where('question_number', 6)->value('answer_text'), 'Question 7 is slot 6.');
        $this->assertSame('Finished', $this->state($project->id));
        $this->assertSame(1, $this->task($project->id, 'Clearance'));
        $this->assertSame(0, DB::table('web_notices')->where('project_id', $project->id)->where('kind', 'visit')->where('seen', false)->count(), 'Every notice is seen.');
        $this->assertGreaterThan(0, DB::table('web_notices')->where('project_id', $project->id)->where('kind', 'state')->count(), 'Staff get the state notice.');
        $this->assertStringContainsString('"field":"state"', (string) DB::table('account_audit_events')->where('event_type', 'visits.saved')->latest('id')->value('details'));
        // An edit does not record Finished again; a second closing is refused.
        $page->set('indicators', 'ملاحظة')->call('save')->assertHasNoErrors();
        $finished = DB::table('project_states')->where('name_en', 'Finished')->value('id');
        $this->assertSame(1, DB::table('project_state_history')->where('project_id', $project->id)->where('project_state_id', $finished)->count());
        Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'V-cl'])->assertNotFound();
        // M&E visits are still possible after Finished (funded states).
        $this->meForm($project, $this->beneficiary($project))->call('save')->assertHasNoErrors();
    }

    public function test_monitoring_records_but_cannot_delete_and_the_administrator_deletes(): void
    {
        $this->as('role.monitoring');
        $project = $this->project();
        $this->opening($project)->assertHasNoErrors();
        $this->meForm($project, $this->beneficiary($project))->call('save')->assertHasNoErrors();
        $me = DB::table('me_visits')->where('project_id', $project->id)->first();
        Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'O-op'])->assertDontSee(__('visits.delete'))->call('delete')->assertForbidden();
        Livewire::test(MeVisitForm::class, ['projectId' => $project->id, 'personId' => $me->person_id, 'visitId' => $me->id])->call('delete')->assertForbidden();
        $this->assertSame(1, DB::table('visits')->where('project_id', $project->id)->whereNull('deleted_at')->count());

        $this->as('role.administrator');
        Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'O-op'])->assertSee(__('visits.delete'))->call('delete')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(0, DB::table('visits')->where('project_id', $project->id)->whereNull('deleted_at')->count());
        $this->assertSame(1, DB::table('visits')->where('project_id', $project->id)->whereNotNull('deleted_at')->count(), 'Soft deleted.');
        $this->assertSame(0, DB::table('visit_answers as a')->join('visits as v', 'v.id', '=', 'a.visit_id')->where('v.project_id', $project->id)->whereNull('a.deleted_at')->count());
        $this->assertSame(0, $this->task($project->id, 'Opening Visit'), 'The task is unticked.');
        $this->assertSame(0, $this->notice($project->id, 'Visit Opening-O'), 'The notice is unseen again.');
        $this->assertStringContainsString('"field":"notices_unseen"', (string) DB::table('account_audit_events')->where('event_type', 'visits.deleted')->latest('id')->value('details'));
        Livewire::test(MeVisitForm::class, ['projectId' => $project->id, 'personId' => $me->person_id, 'visitId' => $me->id])->call('delete')->assertHasNoErrors();
        $this->assertSame(0, $this->task($project->id, 'M1'));
        $this->assertSame(0, $this->notice($project->id, 'Visit M1-O'));
        // The opening can be recorded again.
        $this->opening($project)->assertHasNoErrors();

        // A deleted closing visit leaves the project Finished.
        Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'O-cl'])->set('date', '2026-10-01')->set('visitors.0', (string) $this->visitor())->call('save')->assertHasNoErrors();
        Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'O-cl'])->assertSee(__('visits.finished_warning'))->call('delete')->assertHasNoErrors();
        $this->assertSame('Finished', $this->state($project->id));
        $this->assertSame(0, $this->task($project->id, 'Clearance'));
    }

    public function test_rehabilitation_projects_get_only_the_rehabilitation_closing(): void
    {
        $this->as('role.data-entry');
        $project = $this->project(0, 'rehab');
        $hub = Livewire::test(ProjectVisits::class, ['projectId' => $project->id])->assertSee(__('visits.rehab_note'))->assertSee(__('visits.add.rehab'));
        $hub->assertDontSee(__('visits.add.opening'));
        Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'O-op'])->assertNotFound();
        Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'O-cl'])->assertNotFound();
        $this->get('/ar/applications/'.$project->id.'/me-visits/new/'.$this->beneficiary($project))->assertNotFound();

        $page = Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'R-cl'])->set('date', '2026-03-01')->set('visitors.0', (string) $this->visitor());
        foreach (range(1, 12) as $n) {
            $page->set("q.$n.value", '2');
        }
        $page->call('save')->assertHasErrors('answers');
        $page->set('q.5.value', '-1')->set('q.13.value', '0')->set('q.14.value', '1')->set('q.15.value', '1')->set('q.15.text', 'شكراً')->call('save')->assertHasNoErrors();
        $visit = DB::table('visits')->where('project_id', $project->id)->where('kind', 'R-cl')->first();
        $this->assertSame(15, DB::table('visit_answers')->where('visit_id', $visit->id)->where('form', 'rehab')->count());
        $this->assertSame(-1, (int) DB::table('visit_answers')->where('visit_id', $visit->id)->where('question_number', 5)->value('answer_value'), 'N/A is stored -1.');
        $this->assertSame('Finished', $this->state($project->id));
        // Edits save properly (the desktop's rehabilitation update lost them).
        Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'R-cl'])->set('q.1.value', '0')->set('q.1.text', 'تعديل')->call('save')->assertHasNoErrors();
        $this->assertSame('تعديل', DB::table('visit_answers')->where('visit_id', $visit->id)->where('question_number', 1)->value('answer_text'));
    }

    public function test_vehicle_projects_get_the_vehicle_forms(): void
    {
        $this->as('role.data-entry');
        $project = $this->project(0, 'vehicle');
        Livewire::test(ProjectVisits::class, ['projectId' => $project->id])->assertSee(__('visits.vehicle_note'));
        Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'O-op'])->assertNotFound();
        $page = Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'V-op'])->set('date', '2026-02-01')->set('visitors.0', (string) $this->visitor())->set('q.3.value', '4');
        $page->call('save')->assertHasErrors('vehicle');
        $page->set('vehicle', 'دمشق 123456')->call('save')->assertHasNoErrors();
        $this->assertSame('دمشق 123456', DB::table('visits')->where('project_id', $project->id)->value('vehicle_number'));
        $this->assertSame(1, $this->notice($project->id, 'Visit Opening-V'));
        $this->meForm($project, $this->beneficiary($project))->call('save')->assertHasErrors();
        $this->assertSame(0, DB::table('me_visits')->where('project_id', $project->id)->count(), 'A vehicle M&E visit answers the vehicle questionnaire.');
    }

    public function test_a_kobo_visit_is_read_only(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $this->opening($project)->assertHasNoErrors();
        $visit = DB::table('visits')->where('project_id', $project->id)->first();
        DB::table('kobo_submissions')->insert(['city_id' => $project->city_id, 'root_uuid' => 'uuid-1', 'local_table' => 'visits', 'local_record_id' => $visit->id, 'created_at' => now(), 'updated_at' => now()]);
        Livewire::test(ProjectVisits::class, ['projectId' => $project->id])->assertSee(__('visits.kobo'));
        Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'O-op'])->assertSee(__('visits.kobo_read_only'))->assertDontSee(__('visits.save'))
            ->set('q.1.value', '0')->call('save')->assertHasErrors('visit');
        $this->as('role.administrator');
        Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'O-op'])->call('delete')->assertHasErrors('visit');
        $this->assertSame(1, DB::table('visits')->where('project_id', $project->id)->whereNull('deleted_at')->count());
    }

    public function test_the_schedule_filters_hides_notes_reschedules_and_exports(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $this->get('/ar/visits/schedule')->assertOk()->assertSeeLivewire(VisitSchedule::class);
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'visits.schedule')->exists());
        $mine = fn ($page) => $page->viewData('rows')->getCollection()->where('project_id', $project->id);
        $page = Livewire::test(VisitSchedule::class)->set('visit', 'Opening')->set('q', (string) $project->project_number)->set('cities', [(int) $project->city_id]);
        $this->assertSame(['Visit Opening-O'], $mine($page)->pluck('body')->values()->all());
        $this->assertNull($mine($page)->first()->status);
        $notice = $mine($page)->first();

        $this->opening($project)->assertHasNoErrors();
        $page = Livewire::test(VisitSchedule::class)->set('visit', 'Opening')->set('hidden', true)->set('q', (string) $project->project_number);
        $this->assertSame('done', $mine($page)->first()->status, 'The visit status is Done once the opening exists.');

        $m1 = DB::table('web_notices')->where('project_id', $project->id)->where('body', 'Visit M1-O')->first();
        Livewire::test(VisitSchedule::class)->call('setHidden', $m1->id, true)->assertHasNoErrors();
        $this->assertSame(1, $this->notice($project->id, 'Visit M1-O'));
        Livewire::test(VisitSchedule::class)->call('setHidden', $m1->id, false);
        $this->assertSame(0, $this->notice($project->id, 'Visit M1-O'));
        Livewire::test(VisitSchedule::class)->call('edit', $m1->id, 'note')->set('noteText', 'اتصلنا')->call('saveNote')->assertHasNoErrors();
        $this->assertSame('اتصلنا', DB::table('web_notices')->where('id', $m1->id)->value('note'));

        $m2 = DB::table('web_notices')->where('project_id', $project->id)->where('body', 'Visit M2-O')->first();
        Livewire::test(VisitSchedule::class)->call('edit', $m1->id, 'reschedule')->set('newDate', '2026-08-20')->call('saveReschedule')->assertHasErrors('reason');
        Livewire::test(VisitSchedule::class)->call('edit', $m1->id, 'reschedule')->set('newDate', '2026-08-20')->set('reason', 'سفر المستفيد')->set('shiftLater', true)->call('saveReschedule')->assertHasNoErrors();
        $moved = DB::table('web_notices')->where('id', $m1->id)->first();
        $this->assertSame('2026-08-20', substr((string) $moved->notice_date, 0, 10));
        $this->assertSame(substr((string) $m1->notice_date, 0, 10), substr((string) $moved->original_date, 0, 10));
        $days = (int) CarbonImmutable::parse(substr((string) $m1->notice_date, 0, 10))->diffInDays('2026-08-20', false);
        $this->assertSame(CarbonImmutable::parse(substr((string) $m2->notice_date, 0, 10))->addDays($days)->toDateString(), substr((string) DB::table('web_notices')->where('id', $m2->id)->value('notice_date'), 0, 10));
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'notifications.rescheduled')->exists());

        $this->get('/ar/visits/schedule/export?format=csv&visit=Opening')->assertOk();
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'reports.exported')->exists());
        // Guest cannot open the schedule; communication reads nothing of visits either.
        $this->as('role.guest');
        $this->get('/ar/visits/schedule')->assertForbidden();
    }

    public function test_results_and_visitor_statistics(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $this->opening($project)->assertHasNoErrors();
        $this->meForm($project, $this->beneficiary($project))->call('save')->assertHasNoErrors();

        $this->get('/ar/visits/results')->assertOk()->assertSeeLivewire(VisitResults::class);
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'visits.results')->exists());
        $first = app(MonitoringVisitService::class)->questionnaire('o')->first()->questions->first();
        $counts = Livewire::test(VisitResults::class)->set('visit', 'M1')->set('cities', [(int) $project->city_id])->viewData('counts');
        $row = $counts->firstWhere('question', $first->name);
        $this->assertSame(1, $row->answers[0]->count, 'The chosen answer is counted once.');
        $answers = Livewire::test(VisitResults::class)->set('view', 'answers')->set('visit', 'opening')->set('cities', [(int) $project->city_id]);
        $this->assertTrue($answers->viewData('rows')->getCollection()->contains(fn ($r) => $r[1] === ProjectNumberAllocator::display((string) DB::table('cities')->where('id', $project->city_id)->value('code'), (int) $project->project_number)));
        $this->get('/ar/visits/results/export?format=csv&view=answers&visit=M')->assertOk();

        // Visitor statistics: administrator only.
        $this->get('/ar/visits/statistics')->assertForbidden();
        $this->as('role.administrator');
        $this->get('/ar/visits/statistics')->assertOk()->assertSeeLivewire(VisitorStatistics::class);
        $stats = Livewire::test(VisitorStatistics::class)->set('from', '2026-01-01')->set('to', now()->toDateString())->set('cities', [(int) $project->city_id]);
        $row = $stats->viewData('rows')->firstWhere('name', 'role.monitoring');
        $this->assertSame(1, $row->stages['Opening']);
        $this->assertSame(1, $row->stages['M1']);
        $this->assertSame(2, $row->total);
        Livewire::test(VisitorStatistics::class)->set('from', '2026-05-01')->set('to', '2026-04-01')->assertHasErrors('from');
        $this->get('/ar/visits/statistics/export?format=csv&from=2026-01-01&to='.now()->toDateString())->assertOk();
    }

    public function test_financial_legal_and_guest_are_refused_and_another_city_is_not_found(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $this->opening($project)->assertHasNoErrors();
        foreach (['role.financial', 'role.legal', 'role.guest'] as $user) {
            $this->as($user);
            $this->get('/ar/applications/'.$project->id.'/visits')->assertForbidden();
            $this->get('/ar/applications/'.$project->id.'/visits/O-op')->assertForbidden();
            $this->get('/ar/visits/schedule')->assertForbidden();
            $this->get('/ar/visits/results')->assertForbidden();
            $this->get('/ar/projects/'.$project->id)->assertOk()->assertDontSee(route('visits.hub', ['locale' => 'ar', 'project' => $project->id]));
        }
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'access.denied')->exists());

        $account = $this->as('role.data-entry');
        DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', $project->city_id)->update(['revoked_at' => now()]);
        $this->get('/ar/applications/'.$project->id.'/visits')->assertNotFound();
        Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'O-op'])->assertNotFound();
    }

    public function test_a_stale_page_is_refused(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $stale = Livewire::test(VisitForm::class, ['projectId' => $project->id, 'kind' => 'O-op']);
        $this->opening($project)->assertHasNoErrors();
        $stale->set('date', '2026-02-03')->set('visitors.0', (string) $this->visitor())->call('save')->assertHasErrors('version');
        $this->assertSame(1, DB::table('visits')->where('project_id', $project->id)->count());
    }

    public function test_role_sync_grants_the_visitor_capability_to_linked_desktop_visitors(): void
    {
        $user = DB::table('legacy_staff_users')->where('is_visitor', true)->whereNull('disabled_at')->orderBy('id')->first();
        $account = WebAccount::where('username', 'role.guest')->firstOrFail();
        DB::table('account_legacy_links')->insert(['account_id' => $account->id, 'city_id' => $user->city_id, 'legacy_staff_user_id' => $user->id, 'decision_locator' => 'test:link', 'reviewed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        // A monitoring holder that lost the new grant gets it back from the sync; one revoked by hand would stay revoked.
        $monitoring = WebAccount::where('username', 'role.monitoring')->firstOrFail();
        DB::table('account_city_capabilities')->where('account_id', $monitoring->id)->where('capability', 'visits.visitor')->delete();
        $this->artisan('staff:sync-export-roles')->assertSuccessful();
        $this->assertTrue(DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', $user->city_id)->where('capability', 'visits.visitor')->whereNull('revoked_at')->exists());
        $this->assertSame(DB::table('cities')->count(), DB::table('account_city_capabilities')->where('account_id', $monitoring->id)->where('capability', 'visits.visitor')->count());
        $this->assertFalse(DB::table('account_city_capabilities')->where('account_id', WebAccount::where('username', 'role.data-entry')->value('id'))->where('capability', 'visits.visitor')->exists());
    }
}
