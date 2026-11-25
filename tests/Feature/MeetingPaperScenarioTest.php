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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * M4.18 user scenarios: the minutes and the survey of a final profession meeting print filled from the meeting (answers, tables, attendees,
 * minutes text); a draft does not print; a reader without profession_meetings.view in the city is refused; each print is audited.
 */
class MeetingPaperScenarioTest extends TestCase
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

    /** @return array{0: int, 1: object} a final meeting (id) and its project */
    private function finalMeeting(): array
    {
        $this->as('role.data-entry');
        $project = $this->project();
        Livewire::test(ProfessionMeetingForm::class, ['city' => $project->city_id])->set('state', $this->complete($project))->call('saveFinal')->assertHasNoErrors();

        return [(int) DB::table('profession_meetings')->value('id'), $project];
    }

    public function test_the_minutes_and_the_survey_print_filled_from_a_final_meeting_and_are_audited(): void
    {
        [$id, $project] = $this->finalMeeting();
        $sections = app(ProfessionMeetingService::class)->catalog()['sections'];

        $minutes = $this->get("/ar/profession-meetings/$id/print/minutes")->assertOk();
        $minutes->assertSee(__('paper_meeting.minutes_title', [], 'ar'))->assertSee('قاعة الكنيسة')->assertSee('حاضر 1')->assertSee($project->display);
        foreach ($sections as $section) {
            $minutes->assertSee($section->label)->assertSee('محضر '.$section->id);
        }

        $survey = $this->get("/ar/profession-meetings/$id/print/survey")->assertOk();
        $survey->assertSee(__('paper_meeting.survey_title', [], 'ar'))->assertSee('ماركة أ')->assertSee('منشار')->assertSee('دعم مادي')->assertSee('مشارك أول')->assertSee('كثرة المحلات');
        foreach ($sections as $section) {
            $survey->assertSee($section->label);
        }

        $events = DB::table('account_audit_events')->where('event_type', 'papers.printed')->where('subject_id', $id)->pluck('decision_locator')->all();
        $this->assertEqualsCanonicalizing(['paper:MEETING_MINUTES', 'paper:MEETING_SURVEY'], $events);
        $this->get("/ar/profession-meetings/$id/print/other")->assertNotFound();

        // The form offers both prints once final.
        Livewire::test(ProfessionMeetingForm::class, ['meetingId' => $id])->assertSee(__('paper_meeting.print_minutes', [], 'ar'))->assertSee(__('paper_meeting.print_survey', [], 'ar'));
    }

    public function test_a_draft_does_not_print_and_a_reader_without_the_view_right_is_refused(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        Livewire::test(ProfessionMeetingForm::class, ['city' => $project->city_id])->set('state.careerIds', [(int) $project->career_id])->call('saveDraft')->assertHasNoErrors();
        $draft = (int) DB::table('profession_meetings')->value('id');
        $this->get("/ar/profession-meetings/$draft/print/minutes")->assertStatus(409);
        Livewire::test(ProfessionMeetingForm::class, ['meetingId' => $draft])->assertDontSee(__('paper_meeting.print_minutes', [], 'ar'));

        [$id] = $this->finalMeeting();
        $this->as('role.guest');
        $this->get("/ar/profession-meetings/$id/print/minutes")->assertForbidden();
        $this->assertSame(1, DB::table('account_audit_events')->where('event_type', 'access.denied')->where('outcome', 'denied')->count() >= 1 ? 1 : 0, 'The refusal is audited.');
    }

    /** The text of word/document.xml inside a downloaded .docx. */
    private function docx(Response $response): string
    {
        $file = tempnam(sys_get_temp_dir(), 'dx');
        file_put_contents($file, $response instanceof StreamedResponse ? '' : $response->getContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file) === true, 'The download is a zip (.docx).');
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($file);

        return $xml;
    }

    public function test_the_minutes_and_the_survey_download_as_word_filled_from_a_final_meeting(): void
    {
        [$id, $project] = $this->finalMeeting();

        $minutes = $this->get("/ar/profession-meetings/$id/word/minutes")->assertOk();
        $this->assertStringContainsString('wordprocessingml.document', (string) $minutes->headers->get('Content-Type'));
        $xml = $this->docx($minutes->baseResponse);
        foreach (['قاعة الكنيسة', 'حاضر 1', $project->display, 'محضر'] as $needle) {
            $this->assertStringContainsString($needle, $xml);
        }
        $this->assertStringContainsString('<w:bidi/>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'document.xml is well-formed XML');

        $survey = $this->docx($this->get("/ar/profession-meetings/$id/word/survey")->assertOk()->baseResponse);
        foreach (['ماركة أ', 'منشار', 'دعم مادي', 'مشارك أول', 'كثرة المحلات'] as $needle) {
            $this->assertStringContainsString($needle, $survey);
        }

        $events = DB::table('account_audit_events')->where('event_type', 'papers.printed')->where('subject_id', $id)->pluck('decision_locator')->all();
        $this->assertEqualsCanonicalizing(['paper:MEETING_MINUTES:word', 'paper:MEETING_SURVEY:word'], $events);
        $this->get("/ar/profession-meetings/$id/word/other")->assertNotFound();
        Livewire::test(ProfessionMeetingForm::class, ['meetingId' => $id])->assertSee(__('paper_meeting.word_minutes', [], 'ar'));
    }

    public function test_a_draft_and_an_unauthorised_reader_get_no_word_file(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        Livewire::test(ProfessionMeetingForm::class, ['city' => $project->city_id])->set('state.careerIds', [(int) $project->career_id])->call('saveDraft')->assertHasNoErrors();
        $draft = (int) DB::table('profession_meetings')->value('id');
        $this->get("/ar/profession-meetings/$draft/word/minutes")->assertStatus(409);

        [$id] = $this->finalMeeting();
        $this->as('role.guest');
        $this->get("/ar/profession-meetings/$id/word/survey")->assertForbidden();
    }
}
