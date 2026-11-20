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
        $account = app(AccountProvisioner::class)->createPlain('dam.clerk', null, Hash::make('a-long-test-password'), 'test:dam');
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
