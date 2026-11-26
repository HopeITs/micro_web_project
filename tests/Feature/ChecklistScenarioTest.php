<?php

namespace Tests\Feature;

use App\Livewire\ChecklistForm;
use App\Models\WebAccount;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** M4.7 user scenarios: data entry fills the checklist of an application (with its computed need assessment) and prints it; other roles read it. */
class ChecklistScenarioTest extends TestCase
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

    private function project(): object
    {
        return DB::table('projects')->whereExists(fn ($q) => $q->selectRaw('1')->from('project_applicants as a')->whereColumn('a.project_id', 'projects.id'))->orderBy('id')->first();
    }

    private function member(int $city, string $type): string
    {
        return (string) DB::table('checklist_members as m')->join('checklist_positions as p', 'p.id', '=', 'm.checklist_position_id')->where('m.city_id', $city)->where('p.type', $type)->value('m.id');
    }

    public function test_checklist_with_visits_members_need_assessment_and_print(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $city = (int) $project->city_id;
        $visitor = $this->member($city, 't');
        $this->get('/ar/projects/'.$project->id)->assertSee(route('checklists.show', ['locale' => 'ar', 'project' => $project->id]));
        $this->get('/ar/applications/'.$project->id.'/checklist')->assertOk()->assertSeeLivewire(ChecklistForm::class)
            ->assertSee('قياس الحاجة')->assertSee('الخسارات التي تعرّض لها المستفيد')->assertSee('المجموع:')->assertDontSee('تقييم المشروع');
        $scores = DB::table('project_scores')->where('project_id', $project->id)->whereNull('deleted_at')->count();

        $form = Livewire::test(ChecklistForm::class, ['projectId' => $project->id])
            // A home visit needs its first visitor.
            ->set('form.header.has_home_visit', '1')->set('form.header.home_visit_date', '2026-11-10')->call('save')->assertHasErrors('form.header.home_visitor_1_id')
            ->set('form.header.home_visitor_1_id', $visitor)->set('form.header.home_visit_note', 'زيارة جيدة')
            ->call('addMember', 't')->set('form.members.t.0.checklist_member_id', $visitor)->set('form.members.t.0.checklist_opinion_id', (string) DB::table('checklist_opinions')->where('name_ar', 'مع')->value('id'))
            ->set('form.header.team_date', '2026-11-12')
            // The 10 manual scores moved to the application form: a stray scores state is ignored here.
            ->set('form.scores', [DB::table('score_criteria')->orderBy('sort_order')->value('id') => ['value' => '1', 'notes' => '']])
            ->call('save')->assertHasNoErrors()->assertSee(__('checklists.saved', [], 'ar'));
        $person = (int) DB::table('project_applicants')->where('project_id', $project->id)->value('person_id');
        $checklist = DB::table('checklists')->where('person_id', $person)->first();
        $this->assertTrue((bool) $checklist->has_home_visit);
        $this->assertSame((int) $visitor, (int) $checklist->home_visitor_1_id);
        $this->assertSame($project->id, (int) $checklist->project_id);
        $this->assertSame(1, DB::table('checklist_member_opinions')->where('person_id', $person)->where('location', 't')->count());
        $this->assertSame($scores, DB::table('project_scores')->where('project_id', $project->id)->whereNull('deleted_at')->count(), 'the checklist no longer writes project scores');

        // "No" clears what the question opened, as the desktop emptied it.
        $form->set('form.header.has_home_visit', '0')->call('save')->assertHasNoErrors();
        $this->assertNull(DB::table('checklists')->where('id', $checklist->id)->value('home_visit_note'));

        $this->get('/ar/applications/'.$project->id.'/checklist/print')->assertOk()->assertSee('Check List')->assertSee(DB::table('checklist_members')->where('id', $visitor)->value('name_ar'))
            ->assertSee('قياس الحاجة')->assertSee('هل يوجد مصدر للطاقة');
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'assessments.printed')->exists());
    }

    public function test_visitors_come_from_the_team_list_and_rejection_message_records_the_sender(): void
    {
        $account = $this->as('role.data-entry');
        $project = $this->project();
        $committee = $this->member((int) $project->city_id, 'c');
        Livewire::test(ChecklistForm::class, ['projectId' => $project->id])
            ->set('form.header.has_project_visit', '1')->set('form.header.project_visitor_1_id', $committee)->call('save')->assertHasErrors('form.header.project_visitor_1_id')
            ->set('form.header.has_project_visit', '0')
            ->set('form.header.rejection_message', '1')->assertSet('form.header.rejection_message_sender_account_id', (string) $account->id)->call('save')->assertHasNoErrors();
        $person = (int) DB::table('project_applicants')->where('project_id', $project->id)->value('person_id');
        $this->assertSame($account->id, (int) DB::table('checklists')->where('person_id', $person)->value('rejection_message_sender_account_id'));
    }

    /** Owner answer, 25 Nov 2026: a project with several beneficiaries has one checklist for each of them. */
    public function test_every_beneficiary_of_a_project_has_their_own_checklist(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $city = (int) $project->city_id;
        $owner = (int) DB::table('project_applicants')->where('project_id', $project->id)->orderBy('id')->value('person_id');
        $others = DB::table('people')->where('city_id', $city)->whereNotIn('id', DB::table('project_applicants')->where('project_id', $project->id)->pluck('person_id'))->orderBy('id')->limit(2)->pluck('id');
        [$partner, $stranger] = [(int) $others[0], (int) $others[1]];
        DB::table('project_applicants')->insert(['city_id' => $city, 'project_id' => $project->id, 'person_id' => $partner, 'role' => 'شريك', 'created_at' => now(), 'updated_at' => now()]);
        $page = '/ar/applications/'.$project->id.'/checklist';

        Livewire::test(ChecklistForm::class, ['projectId' => $project->id])->assertSet('personId', $owner)->set('form.header.notes', 'ملاحظة صاحب المشروع')->call('save')->assertHasNoErrors();
        $this->get($page)->assertOk()->assertSee(__('checklists.applicants', [], 'ar'))->assertSee('person='.$partner, false);

        $member = $this->member($city, 't');
        Livewire::test(ChecklistForm::class, ['projectId' => $project->id, 'personId' => $partner])
            ->assertSet('form.exists', false)->assertSet('form.header.notes', '')
            ->set('form.header.notes', 'ملاحظة الشريك')->call('addMember', 't')->set('form.members.t.0.checklist_member_id', $member)
            // A tampered person in the form state is ignored: the page saves for the person it was opened for.
            ->set('form.person_id', $stranger)->call('save')->assertHasNoErrors()->assertSet('personId', $partner);

        $this->assertSame('ملاحظة صاحب المشروع', DB::table('checklists')->where('person_id', $owner)->whereNull('deleted_at')->value('notes'));
        $this->assertSame('ملاحظة الشريك', DB::table('checklists')->where('person_id', $partner)->whereNull('deleted_at')->value('notes'));
        $this->assertSame(1, DB::table('checklist_member_opinions')->where('person_id', $partner)->whereNull('deleted_at')->count());
        $this->assertFalse(DB::table('checklists')->where('person_id', $stranger)->exists());

        $this->get($page.'?person='.$partner)->assertOk();
        $this->get($page.'/print?person='.$partner)->assertOk()->assertSee('ملاحظة الشريك')->assertDontSee('ملاحظة صاحب المشروع');
        $this->get($page.'?person='.$stranger)->assertNotFound();
        $this->get($page.'/print?person='.$stranger)->assertNotFound();
        $this->get('/ar/checklists')->assertOk()->assertSee('person='.$partner, false);

        Livewire::test(ChecklistForm::class, ['projectId' => $project->id, 'personId' => $partner])->call('remove');
        $this->assertFalse(DB::table('checklists')->where('person_id', $partner)->whereNull('deleted_at')->exists());
        $this->assertTrue(DB::table('checklists')->where('person_id', $owner)->whereNull('deleted_at')->exists(), 'the owner keeps their checklist');
    }

    /** Owner decision, 26 Nov 2026: a desktop record with a "Yes" but no detail stays savable; a new "Yes" still asks for it. */
    public function test_old_gaps_stay_savable_and_new_visits_still_need_their_visitor(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        Livewire::test(ChecklistForm::class, ['projectId' => $project->id])->set('form.header.notes', 'أولى')->call('save')->assertHasNoErrors();
        $person = (int) DB::table('project_applicants')->where('project_id', $project->id)->orderBy('id')->value('person_id');
        DB::table('checklists')->where('person_id', $person)->update(['has_home_visit' => 1, 'home_visitor_1_id' => null, 'rejection_message' => 1, 'rejection_message_sender_account_id' => null]);
        $committeeMember = $this->member((int) $project->city_id, 'c');
        DB::table('checklist_member_opinions')->insert(['city_id' => $project->city_id, 'person_id' => $person, 'checklist_member_id' => $committeeMember, 'location' => 't', 'created_at' => now(), 'updated_at' => now()]);

        Livewire::test(ChecklistForm::class, ['projectId' => $project->id])->set('form.header.notes', 'تعديل')->call('save')->assertHasNoErrors();
        $this->assertSame('تعديل', DB::table('checklists')->where('person_id', $person)->value('notes'));
        $this->assertNull(DB::table('checklists')->where('person_id', $person)->value('home_visitor_1_id'));

        // A newly recorded project visit needs its visitor, and a newly added member must belong to the list.
        Livewire::test(ChecklistForm::class, ['projectId' => $project->id])->set('form.header.has_project_visit', '1')->call('save')->assertHasErrors('form.header.project_visitor_1_id');
        Livewire::test(ChecklistForm::class, ['projectId' => $project->id])->call('addMember', 't')->set('form.members.t.1.checklist_member_id', $committeeMember)->call('save')
            ->assertHasErrors('form.members.t.1.checklist_member_id');
    }

    public function test_readers_cannot_save_print_or_delete(): void
    {
        $this->as('role.guest');
        $project = $this->project();
        $this->get('/ar/applications/'.$project->id.'/checklist')->assertForbidden();

        $this->as('role.monitoring');
        $this->get('/ar/applications/'.$project->id.'/checklist')->assertForbidden();

        // Financial has applications.view but not assessments: refused like the desktop.
        $this->as('role.financial');
        $this->get('/ar/applications/'.$project->id.'/checklist')->assertForbidden();
        $this->get('/ar/applications/'.$project->id.'/checklist/print')->assertForbidden();
    }

    public function test_delete_removes_checklist_and_opinions_but_keeps_the_application_scores(): void
    {
        $this->as('super.admin');
        $project = $this->project();
        $criterion = (int) DB::table('score_criteria')->orderBy('sort_order')->value('id');
        DB::table('project_scores')->insert(['city_id' => $project->city_id, 'project_id' => $project->id, 'score_criterion_id' => $criterion, 'value' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $form = Livewire::test(ChecklistForm::class, ['projectId' => $project->id])->set('form.header.notes', 'ملاحظة')->call('save')->assertHasNoErrors();
        $form->call('remove')->assertSee(__('checklists.deleted', [], 'ar'))->assertSet('form.exists', false);
        $this->assertSame(0, DB::table('checklists')->where('project_id', $project->id)->whereNull('deleted_at')->count());
        $this->assertSame(1, DB::table('project_scores')->where('project_id', $project->id)->whereNull('deleted_at')->count());
    }
}
