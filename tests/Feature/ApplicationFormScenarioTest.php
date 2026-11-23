<?php

namespace Tests\Feature;

use App\Livewire\ApplicationForm;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Applications\ApplicationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** M4.5 user scenarios: a data-entry employee registers preliminary and full applications with the beneficiary and the internal grids. */
class ApplicationFormScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); // drafts are autosaved; never into the real private disk
        $this->seed(DatabaseSeeder::class);
    }

    private function city(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function id(string $table, ?string $name = null): string
    {
        return (string) DB::table($table)->when($name, fn ($q) => $q->where('name_ar', $name))->where('is_active', true)->orderBy('id')->value('id');
    }

    private function damascusClerk(): WebAccount
    {
        $account = app(AccountProvisioner::class)->createPlain('dam.clerk', 'dam.clerk@hcsyria.org', Hash::make('a-long-test-password'), 'test:dam');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now()]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('DAM'), 'data-entry', 'test:dam');
        $this->actingAs($account->fresh());

        return $account->fresh();
    }

    private function newPreliminary(): Testable
    {
        return Livewire::test(ApplicationForm::class)
            ->set('form.person.first_name', 'منيرفا')->set('form.person.last_name', 'اختبارية')->set('form.person.father_name', 'سليم')->set('form.person.sex', 'female')
            ->set('form.project.name', 'مشغل خياطة منزلي');
    }

    /** Every section a full application needs. */
    private function fillFull(Testable $form): Testable
    {
        $dam = $this->city('DAM');
        $form->call('setStage', 'full');
        foreach ([
            'mother_name' => 'هدى', 'national_number' => '01999888777', 'birth_date' => '1988-03-04', 'intermediary' => 'كنيسة الحي', 'home_address' => 'باب توما',
            'marital_status_id' => $this->id('marital_statuses'), 'parish_id' => $this->id('parishes'), 'home_ownership_id' => $this->id('asset_types'), 'mobile' => '0933000111',
            'street_id' => (string) DB::table('streets')->where('city_id', $dam)->value('id'), 'priest_id' => (string) DB::table('priests')->where('city_id', $dam)->value('id'),
        ] as $f => $v) {
            $form->set('form.person.'.$f, $v);
        }
        foreach (['loss_story' => 'خسرت الورشة', 'goal' => 'دخل ثابت', 'goal_how' => 'بآلة خياطة', 'work_years' => '5'] as $f => $v) {
            $form->set('form.applicant.'.$f, $v);
        }
        foreach (['has_work', 'has_education', 'has_courses', 'has_loss', 'has_illness', 'has_assets', 'has_aid_sources', 'has_debts', 'has_help'] as $f) {
            $form->set('form.applicant.'.$f, '0');
        }
        $form->set('form.applicant.lives_with_family', '1');
        $form->set('form.project.fund_type_id', $this->id('fund_types'))->set('form.project.requested_amount', '3,000,000')->set('form.project.place_type_id', $this->id('asset_types'));
        foreach (range(1, 5) as $n) {
            $form->set('form.project.agreement_'.$n, '1');
        }
        $form->call('addRow', 'materials')->set('form.grids.materials.0.name', 'آلة خياطة')->set('form.grids.materials.0.quantity', '1')->set('form.grids.materials.0.price', '3000000');
        $form->call('addRow', 'power_sources')->set('form.grids.power_sources.0.power_source_id', $this->id('power_sources'))
            ->set('form.grids.power_sources.0.note', 'أمبير')->set('form.grids.power_sources.0.responsibility', 'العائلة');
        $form->set('form.family_number', 'DAM-FB-77');

        return $form;
    }

    public function test_preliminary_application_with_a_new_beneficiary(): void
    {
        $this->damascusClerk();
        $this->get('/ar')->assertSee(route('applications.create', ['locale' => 'ar']));
        $this->get('/ar/applications/create')->assertOk()->assertSeeLivewire(ApplicationForm::class);
        $form = $this->newPreliminary()->assertSet('form.city', $this->city('DAM'))->assertSet('form.number', '1041');
        $form->call('save')->assertHasNoErrors()->assertRedirect();
        $project = DB::table('projects')->where('city_id', $this->city('DAM'))->where('project_number', 1041)->first();
        $this->assertSame('preliminary', $project->application_stage);
        $this->assertSame('مشغل خياطة منزلي', $project->name);
        $state = DB::table('project_state_history as h')->join('project_states as s', 's.id', '=', 'h.project_state_id')->where('h.project_id', $project->id)->where('h.is_current', true)->value('s.name_en');
        $this->assertSame('On Hold', $state);
        $applicant = DB::table('project_applicants')->where('project_id', $project->id)->first();
        $this->assertSame('مستفيد', $applicant->role);
        $this->assertSame('منيرفا', DB::table('people')->where('id', $applicant->person_id)->value('first_name'));
        $this->assertTrue(DB::table('family_members')->where('person_id', $applicant->person_id)->exists());
        $this->get('/ar/projects/'.$project->id)->assertOk()->assertSee('DAM-1041')->assertSee(__('applications.created', [], 'ar'));
    }

    public function test_description_is_required_and_nothing_is_written_on_errors(): void
    {
        $this->damascusClerk();
        $before = DB::table('people')->count();
        $this->newPreliminary()->set('form.project.name', '')->set('form.grids', array_merge(app(ApplicationService::class)->blank(auth()->user(), $this->city('DAM'))['grids'], []))
            ->call('save')->assertHasErrors('form.project.name');
        $this->assertSame($before, DB::table('people')->count());
        $this->assertFalse(DB::table('projects')->where('project_number', 1041)->where('city_id', $this->city('DAM'))->exists());
    }

    public function test_project_number_taken_in_the_city_is_refused_and_a_free_one_is_kept(): void
    {
        $this->damascusClerk();
        $this->newPreliminary()->set('form.number', '1005')->call('save')->assertHasErrors('number');
        $this->newPreliminary()->set('form.person.first_name', 'منيرفاب')->set('form.number', '2500')->call('save')->assertHasNoErrors();
        $this->assertTrue(DB::table('projects')->where('city_id', $this->city('DAM'))->where('project_number', 2500)->exists());
        // The sequence continues after the typed number.
        Livewire::test(ApplicationForm::class)->assertSet('form.number', '2501');
    }

    public function test_full_application_is_refused_until_ready_then_waits_with_tasks(): void
    {
        $this->damascusClerk();
        $form = $this->newPreliminary()->call('setStage', 'full')->set('form.project.fund_type_id', $this->id('fund_types'))->set('form.project.requested_amount', '1000');
        $form->call('save')->assertSet('readinessIssues', fn ($issues) => count($issues) > 10);
        $this->assertFalse(DB::table('projects')->where('name', 'مشغل خياطة منزلي')->exists());

        $this->fillFull($form)->call('checkReadiness')->assertSet('readinessIssues', [])->call('save')->assertHasNoErrors()->assertRedirect();
        $project = DB::table('projects')->where('name', 'مشغل خياطة منزلي')->first();
        $this->assertSame('full', $project->application_stage);
        $this->assertEquals(3000000, (float) $project->requested_amount);
        $state = DB::table('project_state_history as h')->join('project_states as s', 's.id', '=', 'h.project_state_id')->where('h.project_id', $project->id)->where('h.is_current', true)->value('s.name_en');
        $this->assertSame('Waiting', $state);
        $this->assertSame(DB::table('task_definitions')->where('type', 'a')->where('is_active', true)->count(), DB::table('project_tasks')->where('project_id', $project->id)->count());
        $this->assertSame(1, DB::table('project_materials')->where('project_id', $project->id)->count());
        $person = (int) DB::table('project_applicants')->where('project_id', $project->id)->value('person_id');
        $this->assertSame(1, DB::table('person_power_sources')->where('person_id', $person)->count());
    }

    public function test_registered_beneficiary_and_grid_edits_on_an_existing_application(): void
    {
        $this->damascusClerk();
        $person = DB::table('people')->where('city_id', $this->city('DAM'))->whereExists(fn ($q) => $q->selectRaw('1')->from('family_members as m')->whereColumn('m.provider_person_id', 'people.id')->whereColumn('m.person_id', '<>', 'people.id'))->first();
        $people = DB::table('people')->count();
        $form = Livewire::test(ApplicationForm::class, ['personId' => $person->id])->assertSet('form.person_id', $person->id)
            ->assertSet('form.person.first_name', $person->first_name)->set('form.project.name', 'محل عصائر');
        $this->assertNotEmpty($form->get('form.grids.family'));
        $form->call('addRow', 'educations')->set('form.grids.educations.0.education_level_id', $this->id('education_levels'))->call('save')->assertHasNoErrors();
        $this->assertSame($people, DB::table('people')->count(), 'no new person for a registered beneficiary');
        $project = (int) DB::table('projects')->where('name', 'محل عصائر')->value('id');
        $this->assertSame(1, DB::table('person_educations')->where('person_id', $person->id)->count());

        // Editing: the grid rows come back, a removed row is soft deleted, a changed member is saved.
        $edit = Livewire::test(ApplicationForm::class, ['projectId' => $project])->assertSet('form.project.name', 'محل عصائر');
        $member = $edit->get('form.grids.family.0');
        $edit->set('form.grids.family.0.first_name', 'اسم-معدل')->call('removeRow', 'educations', 0)->call('save')->assertHasNoErrors();
        $this->assertSame('اسم-معدل', DB::table('people')->where('id', DB::table('family_members')->where('id', $member['id'])->value('person_id'))->value('first_name'));
        $this->assertNotNull(DB::table('person_educations')->where('person_id', $person->id)->value('deleted_at'));
    }

    public function test_preliminary_converts_to_full_and_never_back(): void
    {
        $this->damascusClerk();
        $this->newPreliminary()->call('save');
        $project = (int) DB::table('projects')->where('name', 'مشغل خياطة منزلي')->value('id');
        $form = $this->fillFull(Livewire::test(ApplicationForm::class, ['projectId' => $project]))->call('save')->assertHasNoErrors();
        $this->assertSame('full', DB::table('projects')->where('id', $project)->value('application_stage'));
        Livewire::test(ApplicationForm::class, ['projectId' => $project])->call('setStage', 'preliminary')->assertSet('form.stage', 'full');
    }

    public function test_duplicate_beneficiary_needs_confirmation(): void
    {
        $this->damascusClerk();
        $existing = DB::table('people')->where('city_id', $this->city('DAM'))->whereNotNull('national_number')->first();
        $form = $this->newPreliminary()->set('form.person.national_number', $existing->national_number)->assertSee(__('people.duplicates_title', [], 'ar'))
            ->call('save')->assertHasErrors('confirm');
        $form->set('confirmed', true)->call('save')->assertHasNoErrors();
    }

    public function test_drafts_are_kept_resumed_private_and_removed_after_saving(): void
    {
        Storage::fake('local');
        $clerk = $this->damascusClerk();
        $form = $this->newPreliminary()->call('autosave')->assertSet('dirty', false);
        $draftId = $form->get('draftId');
        $files = Storage::disk('local')->allFiles('application-drafts/'.$clerk->id);
        $this->assertCount(1, $files);
        // Encrypted at rest: the typed name is not readable in the file.
        $this->assertStringNotContainsString('منيرفا', Storage::disk('local')->get($files[0]));

        $this->get('/ar/applications/create')->assertSee(__('applications.drafts.title', [], 'ar'))->assertSee('منيرفا');
        Livewire::withQueryParams([])->test(ApplicationForm::class, ['draft' => $draftId])->assertSet('form.person.first_name', 'منيرفا')
            ->assertSet('form.project.name', 'مشغل خياطة منزلي')->call('save')->assertHasNoErrors();
        $this->assertSame([], Storage::disk('local')->allFiles('application-drafts/'.$clerk->id), 'the draft is removed once the application is saved');

        // Another account cannot open it.
        $this->newPreliminary()->call('autosave');
        $this->actingAs(WebAccount::where('username', 'role.data-entry')->firstOrFail());
        $this->get('/ar/applications/create')->assertDontSee('منيرفا');
    }

    public function test_an_edit_keeps_one_draft_that_can_be_restored(): void
    {
        Storage::fake('local');
        $this->damascusClerk();
        $this->newPreliminary()->call('save');
        $project = (int) DB::table('projects')->where('name', 'مشغل خياطة منزلي')->value('id');
        Livewire::test(ApplicationForm::class, ['projectId' => $project])->set('form.project.name', 'وصف لم يُحفظ')->call('autosave');
        Livewire::test(ApplicationForm::class, ['projectId' => $project])->assertSet('form.project.name', 'مشغل خياطة منزلي')
            ->assertSee(__('applications.drafts.pending_title', [], 'ar'))->call('restoreDraft')->assertSet('form.project.name', 'وصف لم يُحفظ');
    }

    public function test_dependent_lists_follow_their_parent(): void
    {
        $this->damascusClerk();
        $field = (int) DB::table('careers')->whereNotNull('project_subcategory_id')->value('project_subcategory_id');
        $inField = DB::table('careers')->where('project_subcategory_id', $field)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $other = (int) DB::table('careers')->where('project_subcategory_id', '<>', $field)->value('id');
        $form = $this->newPreliminary()->call('setStage', 'full')->set('form.project.project_subcategory_id', (string) $field);
        $this->assertEqualsCanonicalizing($inField, $form->viewData('options')['career_id']->pluck('id')->all(), 'careers narrowed to the field');

        // A career of another field is refused; changing the field clears a career that no longer belongs.
        $form->set('form.project.career_id', (string) $other)->call('save')->assertHasErrors('form.project.career_id');
        $form->set('form.project.career_id', (string) $inField[0])->set('form.project.project_subcategory_id', (string) DB::table('project_subcategories')->where('id', '<>', $field)->value('id'))
            ->assertSet('form.project.career_id', '');
        // Choosing a career first fills its field.
        $form->set('form.project.project_subcategory_id', '')->set('form.project.career_id', (string) $other)
            ->assertSet('form.project.project_subcategory_id', (string) DB::table('careers')->where('id', $other)->value('project_subcategory_id'));

        $type = (int) DB::table('project_subtypes')->value('project_type_id');
        $subtype = (int) DB::table('project_subtypes')->where('project_type_id', $type)->value('id');
        $form->set('form.project.project_type_id', (string) $type)->set('form.project.project_subtype_id', (string) $subtype)
            ->set('form.project.project_type_id', (string) DB::table('project_types')->where('id', '<>', $type)->value('id'))->assertSet('form.project.project_subtype_id', '');
        // Long lists come with their group for searching (specialty → education type).
        $this->assertTrue($form->viewData('gridOptions')['educations']['education_specialty_id']->contains(fn ($o) => $o->group !== null));
    }

    public function test_yes_no_questions_open_their_tables_and_no_takes_rows_out_with_undo(): void
    {
        $this->damascusClerk();
        $form = $this->newPreliminary()->call('setStage', 'full')->set('form.project.fund_type_id', $this->id('fund_types'))->set('form.project.requested_amount', '1000')->call('addRow', 'educations')->assertCount('form.grids.educations', 0);
        $form->set('form.applicant.has_education', '1')->call('addRow', 'educations')->assertCount('form.grids.educations', 1)
            ->set('form.grids.educations.0.education_level_id', $this->id('education_levels'))
            ->set('form.applicant.has_education', '0')->assertCount('form.grids.educations', 0)->assertSee(__('applications.gate.undo', [], 'ar'))
            ->call('undoSwitch', 'has_education')->assertSet('form.applicant.has_education', '1')->assertCount('form.grids.educations', 1);

        // "Works now: No" still allows previous jobs, as previous jobs.
        $form = $this->newPreliminary()->set('form.applicant.has_work', '0')->call('addRow', 'work_experiences')->assertSet('form.grids.work_experiences.0.status', 'no');
        $form->set('form.grids.work_experiences.0.occupation_id', $this->id('occupations'))->call('save')->assertHasNoErrors();
        $person = (int) DB::table('project_applicants')->latest('id')->value('person_id');
        $this->assertSame(1, DB::table('person_work_experiences')->where('person_id', $person)->where('status', 'no')->count());
    }

    public function test_a_no_answer_cannot_be_saved_with_its_rows(): void
    {
        $this->damascusClerk();
        $state = app(ApplicationService::class)->blank(auth()->user(), $this->city('DAM'));
        $state['person']['first_name'] = 'سامر';
        $state['person']['last_name'] = 'تجربة';
        $state['project']['name'] = 'وصف';
        $state['applicant']['has_loss'] = '0';
        $state['grids']['losses'] = [['id' => null, 'loss_type_id' => $this->id('loss_types'), 'year_text' => '2013', 'note' => '']];
        $state['applicant']['has_debts'] = '0';
        $state['applicant']['debts_amount'] = '500';
        try {
            app(ApplicationService::class)->save(auth()->user(), $state, null, true);
            $this->fail('saved a "No" answer together with its rows');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('grids.losses.0.loss_type_id', $e->errors());
            $this->assertArrayHasKey('applicant.debts_amount', $e->errors());
        }
    }

    public function test_row_columns_open_with_their_yes_no_column(): void
    {
        $this->damascusClerk();
        $form = $this->newPreliminary()->set('form.applicant.has_illness', '1')->call('addRow', 'illnesses')
            ->set('form.grids.illnesses.0.illness_id', $this->id('illnesses'))->set('form.grids.illnesses.0.has_medical_aid', '0')
            ->set('form.grids.illnesses.0.donor_text', 'جهة')->call('save')->assertHasNoErrors();
        $person = (int) DB::table('project_applicants')->latest('id')->value('person_id');
        $this->assertNull(DB::table('person_illnesses')->where('person_id', $person)->value('donor_text'), 'medical-aid details are cleared when the answer is No');
    }

    public function test_roles_and_cities(): void
    {
        $this->actingAs(WebAccount::where('username', 'role.financial')->firstOrFail());
        $this->get('/ar/applications/create')->assertForbidden();
        $project = (int) DB::table('projects')->where('city_id', $this->city('DAM'))->value('id');
        $this->get('/ar/applications/'.$project.'/edit')->assertForbidden();

        $this->damascusClerk();
        $aleppo = (int) DB::table('projects')->where('city_id', $this->city('ALP'))->value('id');
        $this->get('/ar/applications/'.$aleppo.'/edit')->assertNotFound();
        $this->get('/ar/projects/'.$project)->assertSee(route('applications.edit', ['locale' => 'ar', 'project' => $project]));
        $this->get('/ar/applications/'.$project.'/edit')->assertOk()->assertSeeLivewire(ApplicationForm::class);
    }
}
