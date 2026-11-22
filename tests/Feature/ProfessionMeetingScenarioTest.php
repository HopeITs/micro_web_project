<?php

namespace Tests\Feature;

use App\Livewire\ProfessionMeetingForm;
use App\Models\WebAccount;
use App\Services\Field\ProfessionMeetingService;
use App\Services\ProjectNumberAllocator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M4.15 user scenarios: data entry saves a draft with only the profession and the date, then completes it and saves it final (every
 * answer, totals equal to the attendance); a final meeting is locked and only the administrator reopens it; participants link to the
 * projects of the profession (eligibility check); monitoring reads but cannot write; the report counts final meetings only and lists the
 * participants' interests; a stale page and a delete are handled; a meeting takes attachments.
 */
class ProfessionMeetingScenarioTest extends TestCase
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

    /** A project that has a profession: its city, the profession and the display number. */
    private function project(): object
    {
        $project = DB::table('projects as p')->join('cities as c', 'c.id', '=', 'p.city_id')->whereNotNull('p.career_id')->whereNull('p.deleted_at')->orderBy('p.id')
            ->first(['p.id', 'p.city_id', 'p.career_id', 'p.project_number', 'c.code']);
        $this->assertNotNull($project, 'The synthetic data has projects with a profession.');
        $project->display = ProjectNumberAllocator::display((string) $project->code, (int) $project->project_number);

        return $project;
    }

    /** A complete, valid final meeting for `$attendance` people. */
    private function complete(object $project, int $attendance = 2): array
    {
        $service = app(ProfessionMeetingService::class);
        $s = $service->blank();
        $s['careerIds'] = [(int) $project->career_id];
        $s['place'] = 'قاعة الكنيسة';
        $s['invited'] = (string) ($attendance + 1);
        $s['attendance'] = (string) $attendance;
        foreach ($service->catalog()['questions'] as $q) {
            $first = $q->options->first();
            if ($q->answer_type === 'option_number') {
                $s['opt'][$first->id] = ['on' => false, 'n' => (string) $attendance, 'text' => $first->has_details || $first->is_other ? 'تفاصيل' : ''];
            } elseif ($q->answer_type === 'multi_option') {
                $s['opt'][$first->id] = ['on' => true, 'n' => '', 'text' => $first->has_details || $first->is_other ? 'تفاصيل' : ''];
            } elseif ($q->answer_type === 'text') {
                $s['tx'][$q->id] = 'إجابة';
            } elseif ($q->answer_type === 'brand_table') {
                $s['brands'] = "ماركة أ\nماركة ب";
            } elseif ($q->answer_type === 'participant_table' && (int) $q->number === 19) {
                $s['part'][$q->id] = [['project' => $project->display, 'name' => 'مشارك أول', 'speciality' => 'نجارة']];
            }
        }
        $s['comp'][1] = ['n' => (string) $attendance, 'reason' => 'كثرة المحلات'];
        $s['seasons'] = [['n' => (string) $attendance, 'from' => 'حزيران', 'to' => 'آب', 'reason' => 'الصيف']];
        $s['equipment'] = [['spec' => 'نجارة', 'items' => 'منشار']];
        $s['challenges'][0]['n'] = (string) $attendance;
        $s['challenges'][0]['solution'] = 'دعم مادي';
        $s['attendees'] = [];
        for ($i = 1; $i <= $attendance; $i++) {
            $s['attendees'][] = ['project' => $i === 1 ? $project->display : '', 'name' => 'حاضر '.$i, 'speciality' => ''];
        }
        foreach ($service->catalog()['sections'] as $section) {
            $s['minutes'][$section->id] = 'محضر '.$section->id;
        }

        return $s;
    }

    public function test_data_entry_saves_a_draft_then_finalises_and_it_locks(): void
    {
        $me = $this->as('role.data-entry');
        $project = $this->project();
        $this->get('/ar/profession-meetings')->assertOk()->assertSee(__('field.meeting.title', [], 'ar'));
        $this->get('/ar/profession-meetings/new?city='.$project->city_id)->assertOk()->assertSeeLivewire(ProfessionMeetingForm::class);

        // A draft needs only the profession and the date.
        Livewire::test(ProfessionMeetingForm::class, ['city' => $project->city_id])->set('state.careerIds', [])->call('saveDraft')->assertHasErrors('careerIds');
        $page = Livewire::test(ProfessionMeetingForm::class, ['city' => $project->city_id])->set('state.careerIds', [(int) $project->career_id])->call('saveDraft')->assertHasNoErrors();
        $meeting = DB::table('profession_meetings')->first();
        $this->assertSame('draft', $meeting->record_state);
        $this->assertSame((int) $project->career_id, (int) $meeting->career_id);
        $this->assertSame($me->id, (int) $meeting->program_registrar_account_id);
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'profession_meetings.saved')->where('subject_id', $meeting->id)->exists());

        // Final: an incomplete draft is refused with the reasons, nothing becomes final.
        $page = Livewire::test(ProfessionMeetingForm::class, ['meetingId' => $meeting->id])->call('saveFinal')->assertHasErrors();
        $this->assertSame('draft', DB::table('profession_meetings')->where('id', $meeting->id)->value('record_state'));

        // Totals that do not add up to the attendance are refused.
        $bad = $this->complete($project);
        $bad['challenges'][0]['n'] = '1';
        Livewire::test(ProfessionMeetingForm::class, ['meetingId' => $meeting->id])->set('state', $bad)->call('saveFinal')->assertHasErrors('challenge');

        // A complete meeting saves as final and links the participant to the project.
        Livewire::test(ProfessionMeetingForm::class, ['meetingId' => $meeting->id])->set('state', $this->complete($project))->call('saveFinal')->assertHasNoErrors();
        $final = DB::table('profession_meetings')->where('id', $meeting->id)->first();
        $this->assertSame('final', $final->record_state);
        $this->assertSame([3, 2, 1], [(int) $final->invited_count, (int) $final->attendance_count, (int) $final->absence_count]);
        $this->assertSame(2, DB::table('profession_meeting_attendees')->where('profession_meeting_id', $meeting->id)->count());
        $this->assertSame((int) $project->id, (int) DB::table('profession_meeting_participants')->where('profession_meeting_id', $meeting->id)->value('project_id'));
        $this->assertSame(2, DB::table('profession_meeting_brands')->where('profession_meeting_id', $meeting->id)->count());
        $this->assertSame(5, DB::table('profession_meeting_minutes')->where('profession_meeting_id', $meeting->id)->count());
        $this->assertGreaterThan(8, DB::table('profession_meeting_option_answers')->where('profession_meeting_id', $meeting->id)->count());

        // Locked: data entry cannot change it, cannot reopen it; the page says so.
        Livewire::test(ProfessionMeetingForm::class, ['meetingId' => $meeting->id])->assertSee(__('field.meeting.locked'))->assertDontSee(__('field.meeting.reopen'))
            ->call('saveDraft')->assertHasErrors('state');

        // The administrator reopens it as a draft; data entry can edit again.
        $this->as('role.administrator');
        Livewire::test(ProfessionMeetingForm::class, ['meetingId' => $meeting->id])->assertSee(__('field.meeting.reopen'))->call('reopen');
        $this->assertSame('draft', DB::table('profession_meetings')->where('id', $meeting->id)->value('record_state'));
    }

    public function test_eligibility_check_and_unknown_project_numbers(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $service = app(ProfessionMeetingService::class);
        $account = auth()->user();

        $list = $service->beneficiaries($account, (int) $project->city_id, [(int) $project->career_id]);
        $this->assertTrue($list->contains('project', $project->display));
        $this->assertSame(0, $service->beneficiaries($account, (int) $project->city_id, [])->count());

        Livewire::test(ProfessionMeetingForm::class, ['city' => $project->city_id])->set('state.careerIds', [(int) $project->career_id])->assertSee($project->display)
            ->call('addAttendee', $project->display, 'مستفيد')->assertSet('state.attendees.0.project', $project->display);

        $state = $this->complete($project);
        $state['attendees'][1]['project'] = 'ZZZ-9999';
        Livewire::test(ProfessionMeetingForm::class, ['city' => $project->city_id])->set('state', $state)->call('saveFinal')->assertHasErrors('project');
        $this->assertSame(0, DB::table('profession_meetings')->count());
    }

    public function test_monitoring_works_like_the_desktop_flag_and_others_are_refused(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        Livewire::test(ProfessionMeetingForm::class, ['city' => $project->city_id])->set('state', $this->complete($project))->call('saveFinal')->assertHasNoErrors();
        $id = (int) DB::table('profession_meetings')->value('id');

        // The monitoring duty (desktop IsME) holds profession_meetings.manage already; a final meeting is locked for it too.
        $this->as('role.monitoring');
        $this->get('/ar/profession-meetings')->assertOk();
        $this->get('/ar/profession-meetings/'.$id)->assertOk()->assertSee(__('field.meeting.locked', [], 'ar'));
        $this->get('/ar/profession-meetings/new?city='.$project->city_id)->assertOk();
        Livewire::test(ProfessionMeetingForm::class, ['meetingId' => $id])->call('saveDraft')->assertHasErrors('state')->assertDontSee(__('field.meeting.reopen'));
        $this->assertSame(0, DB::table('profession_meetings')->whereNotNull('deleted_at')->count());

        foreach (['role.financial', 'role.guest'] as $username) {
            $this->as($username);
            $this->get('/ar/profession-meetings')->assertForbidden();
            $this->get('/ar/profession-meetings/'.$id)->assertForbidden();
            $this->get('/ar/profession-meetings/new?city='.$project->city_id)->assertForbidden();
            $this->get('/ar/profession-meetings/report')->assertForbidden();
        }
    }

    public function test_the_report_counts_final_meetings_and_lists_interests(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        Livewire::test(ProfessionMeetingForm::class, ['city' => $project->city_id])->set('state', $this->complete($project, 3))->call('saveFinal')->assertHasNoErrors();
        Livewire::test(ProfessionMeetingForm::class, ['city' => $project->city_id])->set('state.careerIds', [(int) $project->career_id])->call('saveDraft')->assertHasNoErrors();

        $this->as('role.administrator');
        $page = $this->get('/ar/profession-meetings/report')->assertOk();
        $report = app(ProfessionMeetingService::class)->report(auth()->user(), []);
        $this->assertSame([1, 4, 3, 1], [(int) $report['totals']->meetings, (int) $report['totals']->invited, (int) $report['totals']->attending, (int) $report['totals']->absent]);
        $this->assertSame(1, $report['drafts']);
        $this->assertSame(19, (int) $report['interests'][0]->number);
        $this->assertSame('مشارك أول', $report['interests'][0]->people[0]->name);
        $this->assertSame($project->display, $report['interests'][0]->people[0]->project);
        $page->assertSee('مشارك أول');
        $this->get('/en/profession-meetings/report?year='.now()->year)->assertOk()->assertSee('Statistics and interests');
        $this->get('/ar/profession-meetings?state=draft')->assertOk();
    }

    public function test_stale_pages_deletes_and_attachments(): void
    {
        $me = $this->as('role.data-entry');
        $project = $this->project();
        Livewire::test(ProfessionMeetingForm::class, ['city' => $project->city_id])->set('state.careerIds', [(int) $project->career_id])->call('saveDraft')->assertHasNoErrors();
        $id = (int) DB::table('profession_meetings')->value('id');

        // Two editors: the second save is refused because the page is older than the stored meeting.
        $first = Livewire::test(ProfessionMeetingForm::class, ['meetingId' => $id]);
        $second = Livewire::test(ProfessionMeetingForm::class, ['meetingId' => $id]);
        DB::table('profession_meetings')->where('id', $id)->update(['updated_at' => now()->addMinute()]);
        $first->call('saveDraft')->assertStatus(409);
        $this->assertSame('draft', DB::table('profession_meetings')->where('id', $id)->value('record_state'));

        // The meeting page carries the attachments panel.
        $this->get('/ar/profession-meetings/'.$id)->assertOk()->assertSee(__('attachments.title', [], 'ar'));

        // Delete: soft, audited; the list and the page no longer show it.
        $fresh = Livewire::test(ProfessionMeetingForm::class, ['meetingId' => $id]);
        $fresh->call('delete')->assertHasNoErrors();
        $this->assertNotNull(DB::table('profession_meetings')->where('id', $id)->value('deleted_at'));
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'profession_meetings.deleted')->where('subject_id', $id)->exists());
        $this->get('/ar/profession-meetings/'.$id)->assertNotFound();
    }
}
