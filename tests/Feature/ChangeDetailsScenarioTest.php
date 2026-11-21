<?php

namespace Tests\Feature;

use App\Livewire\ApplicationForm;
use App\Livewire\ChecklistForm;
use App\Livewire\PersonDetails;
use App\Livewire\ProjectAnnex;
use App\Models\WebAccount;
use App\Services\Applications\ProjectAnnexService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Audit log v1.3 in the M4.3–M4.7 modules: each save records what changed (before → after), not just that something changed. */
class ChangeDetailsScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(WebAccount::where('username', 'role.data-entry')->firstOrFail());
    }

    /** @return array<string, array{before: mixed, after: mixed}> the details of the latest event of a type, by field */
    private function latest(string $event): array
    {
        $json = DB::table('account_audit_events')->where('event_type', $event)->orderByDesc('id')->value('details');
        $this->assertNotNull($json, "no details on $event");

        return collect(json_decode($json, true))->keyBy('field')->map(fn ($i) => ['before' => $i['before'] ?? null, 'after' => $i['after'] ?? null])->all();
    }

    private function project(): object
    {
        return DB::table('projects')->whereExists(fn ($q) => $q->selectRaw('1')->from('project_applicants as a')->whereColumn('a.project_id', 'projects.id'))->orderBy('id')->first();
    }

    public function test_application_edit_records_changed_fields_and_row_counts(): void
    {
        $project = $this->project();
        Livewire::test(ApplicationForm::class, ['projectId' => $project->id])->set('form.project.name', 'وصف بعد التعديل')
            ->set('form.applicant.has_education', '1')->call('addRow', 'educations')->set('form.grids.educations.0.education_level_id', (string) DB::table('education_levels')->value('id'))
            ->call('save')->assertHasNoErrors();
        $details = $this->latest('applications.updated');
        $this->assertSame(['before' => $project->name, 'after' => 'وصف بعد التعديل'], $details['name']);
        $this->assertSame('1', (string) $details['applicant.has_education']['after']);
        $this->assertSame('+1 ~0 -0', $details['rows.educations']['after']);
    }

    public function test_annex_records_answers_by_name(): void
    {
        $project = $this->project();
        DB::table('projects')->where('id', $project->id)->update(['project_type_id' => DB::table('project_types')->where('name_ar', 'like', '%زراع%')->value('id')]);
        $answers = [];
        foreach (app(ProjectAnnexService::class)->questions('agriculture') as $q) {
            $answers[$q->id] = $q->multiple ? [(string) $q->answers->first()->id] : (string) $q->answers->first()->id;
        }
        Livewire::test(ProjectAnnex::class, ['projectId' => $project->id])->set('answers', $answers)->call('save')->assertHasNoErrors();
        $first = (int) DB::table('ag_questions')->orderBy('sort_order')->value('id');
        $this->assertSame(['before' => null, 'after' => 'نعم'], $this->latest('applications.updated')['answer.q'.$first]);
    }

    public function test_checklist_records_header_fields(): void
    {
        $project = $this->project();
        Livewire::test(ChecklistForm::class, ['projectId' => $project->id])->set('form.header.notes', 'ملاحظة أولى')->call('save')->assertHasNoErrors();
        $details = $this->latest('assessments.saved');
        $this->assertSame('ملاحظة أولى', $details['notes']['after']);
        $this->assertArrayNotHasKey('score.c'.DB::table('score_criteria')->orderBy('sort_order')->value('id'), $details);
    }

    public function test_application_form_records_scores_by_name(): void
    {
        $project = $this->project();
        $criterion = (int) DB::table('score_criteria')->orderBy('sort_order')->value('id');
        Livewire::test(ApplicationForm::class, ['projectId' => $project->id])->set('form.scores.'.$criterion.'.value', '1')->set('form.scores.'.$criterion.'.notes', 'يكفي')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(['before' => null, 'after' => 'نعم · يكفي'], $this->latest('applications.updated')['score.c'.$criterion]);
    }

    public function test_person_sub_table_edit_is_prefixed_with_its_table(): void
    {
        $person = (int) DB::table('people')->value('id');
        Livewire::test(PersonDetails::class, ['personId' => $person, 'kind' => 'courses'])->set('adding', true)->set('draft.name', 'دورة خياطة')->call('add')->assertHasNoErrors();
        $this->assertSame(['before' => null, 'after' => 'دورة خياطة'], $this->latest('people.updated')['courses.name']);
    }
}
