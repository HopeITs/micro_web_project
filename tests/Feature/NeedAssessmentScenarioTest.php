<?php

namespace Tests\Feature;

use App\Livewire\ChecklistForm;
use App\Models\WebAccount;
use App\Services\Assessments\NeedAssessment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decision 21 Nov 2026 (match the desktop): the checklist shows the computed need assessment (desktop view need_evaluation),
 * and the 10 manual project scores live in the application form ("تحليل المشروع").
 */
class NeedAssessmentScenarioTest extends TestCase
{
    use RefreshDatabase;

    private int $city;

    private int $family;

    private int $people = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('ar');
        $this->city = (int) DB::table('cities')->where('code', 'DAM')->value('id');
    }

    private function id(string $table, string $name): ?int
    {
        $id = DB::table($table)->where('name_ar', $name)->value('id');
        $this->assertNotNull($id, "$table: $name");

        return (int) $id;
    }

    private function person(array $values = []): int
    {
        return (int) DB::table('people')->insertGetId($values + ['city_id' => $this->city, 'first_name' => ['سامر', 'رنا', 'جود', 'فادي', 'نادر', 'لمى', 'هاني', 'ريم'][$this->people++ % 8], 'last_name' => 'قياسي', 'person_state_id' => $this->id('person_states', 'مقيم/ة'),
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function member(int $person, int $provider, string $relation, bool $works = false): void
    {
        DB::table('family_members')->insert(['city_id' => $this->city, 'family_id' => $this->family, 'person_id' => $person, 'provider_person_id' => $provider,
            'relation_id' => $this->id('relations', $relation), 'has_work' => $works, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function row(string $table, array $values): void
    {
        DB::table($table)->insert($values + ['city_id' => $this->city, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A project with its beneficiary and household. @return array{0: int, 1: int} project, person */
    private function application(array $person, array $applicant, ?string $place = null): array
    {
        $this->family = (int) DB::table('families')->insertGetId(['city_id' => $this->city, 'family_number' => 'NEED-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $id = $this->person($person);
        $project = (int) DB::table('projects')->insertGetId(['city_id' => $this->city, 'project_number' => 90000 + $id, 'application_stage' => 'full', 'name' => 'مشروع قياس', 'fund_type_id' => DB::table('fund_types')->value('id'), 'requested_amount' => 1000,
            'place_type_id' => $place ? $this->id('asset_types', $place) : null, 'created_at' => now(), 'updated_at' => now()]);
        $this->row('project_applicants', $applicant + ['project_id' => $project, 'person_id' => $id, 'role' => 'مستفيد']);
        $this->member($id, $id, 'مستفيد', false);

        return [$project, $id];
    }

    /** @return array<string, array{indicator: string, mark: ?int}> */
    private function rows(int $project, int $person): array
    {
        $need = app(NeedAssessment::class)->compute($project, $person);

        return collect($need['rows'])->keyBy('key')->map(fn ($r) => ['indicator' => $r['indicator'], 'mark' => $r['mark']])->all() + ['total' => $need['total']];
    }

    public function test_married_working_beneficiary_with_household(): void
    {
        [$project, $a] = $this->application(
            ['marital_status_id' => $this->id('marital_statuses', 'متزوج/ة'), 'household_size' => 5, 'home_ownership_id' => $this->id('asset_types', 'إيجار'), 'birth_date' => '1980-01-01'],
            ['has_loss' => true, 'has_work' => true, 'has_illness' => true, 'has_assets' => true], 'ملك');
        $spouse = $this->person(['birth_date' => '1982-05-05']);
        $child = $this->person(['birth_date' => now()->subYears(2)->format('Y-m-d')]);
        $father = $this->person(['birth_date' => '1940-01-01']);
        $away = $this->person(['birth_date' => '1999-01-01', 'person_state_id' => $this->id('person_states', 'مسافر/ة')]);
        $this->member($spouse, $a, 'زوج/ة', true);
        $this->member($child, $a, 'ابن/ة');
        $this->member($father, $a, 'أب');
        $this->member($away, $a, 'أخ', true); // not Active: neither a worker nor counted
        foreach (['عقار (البيت)', 'مركبة'] as $loss) {
            $this->row('person_losses', ['person_id' => $a, 'loss_type_id' => $this->id('loss_types', $loss)]);
        }
        $this->row('person_work_experiences', ['person_id' => $a, 'work_type_id' => $this->id('work_types', 'صانع'), 'status' => 'Yes']);
        $this->row('person_work_experiences', ['person_id' => $a, 'work_type_id' => $this->id('work_types', 'موظّف قطاع عام'), 'status' => 'no']);
        $this->row('person_illnesses', ['person_id' => $child, 'illness_id' => $this->id('illnesses', 'إعاقة جزئية')]);
        $this->row('person_illnesses', ['person_id' => $father, 'illness_id' => $this->id('illnesses', 'مرض مزمن')]);
        $kind = (int) DB::table('asset_kinds')->orderBy('id')->value('id');
        $kindName = DB::table('asset_kinds')->where('id', $kind)->value('name_ar');
        // Married: the spouse's owned asset counts; the father's does not, and a rented one never does.
        $this->row('person_assets', ['person_id' => $spouse, 'asset_kind_id' => $kind, 'asset_type_id' => $this->id('asset_types', 'ملك'), 'asset_benefit_id' => $this->id('asset_benefits', 'لا يوجد استفادة'), 'year_text' => '2021']);
        $this->row('person_assets', ['person_id' => $father, 'asset_kind_id' => $kind, 'asset_type_id' => $this->id('asset_types', 'ملك'), 'asset_benefit_id' => $this->id('asset_benefits', 'لا يوجد استفادة'), 'year_text' => '2001']);
        $this->row('person_assets', ['person_id' => $a, 'asset_kind_id' => $kind, 'asset_type_id' => $this->id('asset_types', 'إيجار'), 'asset_benefit_id' => $this->id('asset_benefits', 'لا يوجد استفادة'), 'year_text' => '2015']);
        foreach (['كهرباء دولة', 'بطارية وانفرتر'] as $power) {
            $this->row('person_power_sources', ['person_id' => $a, 'power_source_id' => $this->id('power_sources', $power)]);
        }

        $r = $this->rows($project, $a);
        $this->assertSame(['indicator' => 'عقار (البيت),مركبة', 'mark' => 12], $r['loss']);
        $this->assertSame(['indicator' => 'صانع', 'mark' => 14], $r['worktype'], 'desktop quirk: 14 when the beneficiary works');
        $this->assertSame(['indicator' => 'متزوج/ة', 'mark' => 6], $r['maritalstatus']);
        $this->assertSame(['indicator' => '5', 'mark' => 8], $r['num_at_home']);
        $this->assertSame(['indicator' => '2/5', 'mark' => 12], $r['workers']);
        $this->assertSame(['indicator' => '2', 'mark' => 4], $r['child_elder']);
        $this->assertSame(['indicator' => 'إعاقة جزئية', 'mark' => 7], $r['illness']);
        $this->assertSame(['indicator' => 'إيجار', 'mark' => 12], $r['home_type']);
        $this->assertSame(['indicator' => 'ملك', 'mark' => 0], $r['project_place']);
        $this->assertSame(['indicator' => $kindName.' - 2021 - لا يوجد استفادة - الزوج/ة', 'mark' => -15], $r['assets']);
        $this->assertSame(['indicator' => 'بطارية وانفرتر', 'mark' => 2], $r['power_source']);
        $this->assertSame(62, $r['total']);
    }

    public function test_military_single_beneficiary_and_missing_data(): void
    {
        [$project, $a] = $this->application(
            ['marital_status_id' => $this->id('marital_statuses', 'عازب/ة'), 'military_in_war' => true, 'military_period' => 5, 'birth_date' => '1995-03-03'],
            ['has_loss' => false, 'has_work' => false, 'has_illness' => true, 'has_assets' => true]);
        $father = $this->person(['birth_date' => '1950-01-01']);
        $this->member($father, $a, 'أب');
        // Not working, but two current jobs one of which is public: the desktop still gives 6.
        $this->row('person_work_experiences', ['person_id' => $a, 'work_type_id' => $this->id('work_types', 'موظّف قطاع عام'), 'status' => 'yes']);
        $this->row('person_work_experiences', ['person_id' => $a, 'work_type_id' => $this->id('work_types', 'صانع'), 'status' => 'yes']);
        $this->row('person_illnesses', ['person_id' => $a, 'illness_id' => $this->id('illnesses', 'إعاقة كليّة/عقليّة')]);
        $this->row('person_illnesses', ['person_id' => $father, 'illness_id' => $this->id('illnesses', 'أمراض خبيثة/مستعصية')]);
        // Single: the parents' owned asset counts (before 2020, rented out: -5 + -5).
        $this->row('person_assets', ['person_id' => $father, 'asset_kind_id' => DB::table('asset_kinds')->value('id'), 'asset_type_id' => $this->id('asset_types', 'ملك'),
            'asset_benefit_id' => $this->id('asset_benefits', 'عقار /مركبة مؤجرة'), 'year_text' => '2010']);

        $r = $this->rows($project, $a);
        $this->assertSame(['indicator' => 'عسكري خلال الحرب', 'mark' => 12], $r['loss']);
        $this->assertSame(['indicator' => 'لا يعمل', 'mark' => 6], $r['worktype']);
        $this->assertSame(['indicator' => 'عازب/ة', 'mark' => 3], $r['maritalstatus']);
        $this->assertSame(['indicator' => '', 'mark' => 0], $r['num_at_home']);
        $this->assertSame(['indicator' => '', 'mark' => null], $r['workers']);
        $this->assertSame(['indicator' => '1', 'mark' => 2], $r['child_elder']);
        $this->assertSame(['indicator' => 'إعاقة كليّة/عقليّة,أمراض خبيثة/مستعصية', 'mark' => 10], $r['illness']);
        $this->assertSame(['indicator' => 'غير محدد', 'mark' => 0], $r['home_type']);
        $this->assertSame(['indicator' => 'غير محدد', 'mark' => 0], $r['project_place']);
        $this->assertSame(-10, $r['assets']['mark']);
        $this->assertSame(['indicator' => 'بيانات غير مستكملة', 'mark' => 0], $r['power_source']);
        $this->assertSame(23, $r['total']);

        // Unanswered questions: incomplete data, 0.
        DB::table('project_applicants')->where('project_id', $project)->update(['has_loss' => null, 'has_work' => null, 'has_illness' => false, 'has_assets' => null]);
        DB::table('people')->where('id', $a)->update(['military_in_war' => false]);
        $r = $this->rows($project, $a);
        $this->assertSame(['indicator' => 'بيانات غير مستكملة', 'mark' => 0], $r['loss']);
        $this->assertSame(['indicator' => 'بيانات غير مستكملة', 'mark' => 0], $r['worktype']);
        $this->assertSame(['indicator' => 'لا يوجد', 'mark' => 0], $r['illness']);
        $this->assertSame(['indicator' => 'بيانات غير مستكملة', 'mark' => 0], $r['assets']);
        DB::table('project_applicants')->where('project_id', $project)->update(['has_loss' => false]);
        $this->assertSame(['indicator' => 'لا يوجد', 'mark' => 4], $this->rows($project, $a)['loss']);
    }

    public function test_checklist_shows_the_need_assessment_read_only_and_prints_it(): void
    {
        [$project, $a] = $this->application(['household_size' => 2, 'home_ownership_id' => $this->id('asset_types', 'إعارة')], ['has_work' => false, 'has_loss' => false]);
        $total = app(NeedAssessment::class)->compute($project, $a)['total'];
        $this->actingAs(WebAccount::where('username', 'role.data-entry')->firstOrFail());
        Livewire::test(ChecklistForm::class, ['projectId' => $project])->assertSee('قياس الحاجة')->assertSee('ملكية المنزل')->assertSee('إعارة')
            ->assertSee(__('checklists.need.hint'))->assertViewHas('need', fn ($need) => $need['total'] === $total)->assertDontSeeHtml('form.scores');
        $this->get('/ar/applications/'.$project.'/checklist/print')->assertOk()->assertSee('قياس الحاجة')->assertSee('عدد الافراد القاطنين في المنزل');
        $this->get('/en/applications/'.$project.'/checklist')->assertOk()->assertSee('Need assessment')->assertSee('Home ownership');
        // Nothing is stored.
        $this->assertSame(0, DB::table('project_scores')->where('project_id', $project)->count());
    }

    public function test_roles_who_see_and_edit_the_scores_and_need_assessment(): void
    {
        [$project] = $this->application([], ['has_work' => false]);
        $criterion = (int) DB::table('score_criteria')->orderBy('sort_order')->value('id');
        DB::table('project_scores')->insert(['city_id' => $this->city, 'project_id' => $project, 'score_criterion_id' => $criterion, 'value' => 1, 'notes' => 'ملاحظة الموظف', 'created_at' => now(), 'updated_at' => now()]);

        // Monitoring reads the application (and its scores) but cannot edit it or open the checklist.
        $this->actingAs(WebAccount::where('username', 'role.monitoring')->firstOrFail());
        $this->get('/ar/projects/'.$project)->assertOk()->assertSee('تحليل المشروع')->assertSee('ملاحظة الموظف');
        $this->get('/ar/applications/'.$project.'/edit')->assertForbidden();
        $this->get('/ar/applications/'.$project.'/checklist')->assertForbidden();

        // Guest: neither the application form nor the checklist.
        $this->actingAs(WebAccount::where('username', 'role.guest')->firstOrFail());
        $this->get('/ar/applications/'.$project.'/edit')->assertForbidden();
        $this->get('/ar/applications/'.$project.'/checklist')->assertForbidden();

        // Administrator edits both.
        $this->actingAs(WebAccount::where('username', 'super.admin')->firstOrFail());
        $this->get('/ar/applications/'.$project.'/edit')->assertOk();
        $this->get('/ar/applications/'.$project.'/checklist')->assertOk()->assertSee('قياس الحاجة');
    }
}
