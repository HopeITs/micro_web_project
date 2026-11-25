<?php

namespace Tests\Feature;

use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** M4.20 wave 1 user scenarios: the audio consent, Special Case and the experience test print filled from the project, by permission, audited. */
class ProjectPaperScenarioTest extends TestCase
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

    private function project(string $city): int
    {
        return (int) DB::table('projects')->where('city_id', $this->city($city))->where('application_stage', 'full')->whereNull('deleted_at')->orderBy('id')->value('id');
    }

    private function signIn(string $login): void
    {
        $this->post('/ar/login', ['login' => (array_key_exists($login, RoleAccountsSeeder::accounts()) ? RoleAccountsSeeder::email($login) : null) ?? $login, 'password' => 'password'])->assertRedirect();
    }

    public function test_the_audio_consent_prints_with_the_name_the_city_and_is_audited(): void
    {
        $id = $this->project('DAM');
        $this->signIn('super.admin');
        $person = DB::table('project_applicants as a')->join('people as p', 'p.id', '=', 'a.person_id')->where('a.project_id', $id)->orderBy('a.id')->first(['p.first_name']);
        $page = $this->get('/ar/applications/'.$id.'/papers/audio-consent')->assertOk()->assertSee('موافقة تسجيل صوتي')->assertSee('Hope Center')->assertSee('أنا الموقع أدناه')->assertSee('التوقيع');
        $page->assertSee((string) $person->first_name)->assertSee('دمشق');
        $event = DB::table('account_audit_events')->where('event_type', 'papers.printed')->where('subject_id', $id)->latest('id')->first();
        $this->assertSame('paper:F04', $event->decision_locator);
        $this->get('/en/applications/'.$id.'/papers/audio-consent')->assertOk()->assertSee('Audio recording consent');
        $this->get('/ar/applications/'.$id.'/papers/unknown')->assertNotFound();
    }

    public function test_special_case_prints_blank_or_filled_from_a_case(): void
    {
        $id = $this->project('DAM');
        $this->signIn('super.admin');
        $this->get('/ar/applications/'.$id.'/papers/special-case')->assertOk()->assertSee('Special Case')->assertSee('سبب المراجعة')->assertDontSee('مراجعة تجريبية');

        $type = DB::table('special_case_types')->where('name_ar', 'like', '%مقابلة%')->value('id') ?? DB::table('special_case_types')->value('id');
        $case = DB::table('special_cases')->insertGetId(['city_id' => $this->city('DAM'), 'project_id' => $id, 'special_case_type_id' => $type, 'case_date' => '2026-11-10',
            'reason' => 'مراجعة تجريبية', 'description' => 'تفاصيل التجربة', 'action' => 'إجراء التجربة', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/ar/applications/'.$id.'/papers/special-case?case='.$case)->assertOk()->assertSee('مراجعة تجريبية')->assertSee('تفاصيل التجربة')->assertSee('إجراء التجربة')->assertSee('2026-11-10');
        $this->get('/ar/applications/'.$id.'/papers/special-case?case=999999')->assertNotFound();
        $this->get('/ar/applications/'.$id.'/special-cases')->assertOk();
    }

    public function test_the_experience_test_fills_from_the_checklist_and_is_blank_without_it(): void
    {
        $id = $this->project('DAM');
        $this->signIn('super.admin');
        $this->get('/ar/applications/'.$id.'/papers/experience-test')->assertOk()->assertSee('نموذج فحص الخبرة')->assertSee('تقييم مراحل العمل')->assertSee('مستوى الخبرة');

        $person = (int) DB::table('project_applicants')->where('project_id', $id)->orderBy('id')->value('person_id');
        DB::table('checklists')->insert(['city_id' => $this->city('DAM'), 'person_id' => $person, 'project_id' => $id, 'has_professional_test' => 1, 'professional_test_date' => '2026-10-05',
            'professional_test_note' => 'الفاحص التجريبي', 'professional_test_result' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $html = $this->get('/ar/applications/'.$id.'/papers/experience-test')->assertOk()->assertSee('الفاحص التجريبي')->assertSee('2026/10/05')->getContent();
        $this->assertSame(1, substr_count($html, 'rb on'), 'good experience is ticked');
    }

    public function test_another_city_and_a_guest_are_refused(): void
    {
        $dam = $this->project('DAM');
        $hms = $this->project('HMS');
        $account = app(AccountProvisioner::class)->createPlain('paper.hms', 'paper.hms@hcsyria.org', Hash::make('password'), 'test:papers');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('HMS'), 'data-entry', 'test:papers');
        $this->signIn('paper.hms@hcsyria.org');
        $this->get('/ar/applications/'.$hms.'/papers/audio-consent')->assertOk();
        $this->assertContains($this->get('/ar/applications/'.$dam.'/papers/audio-consent')->status(), [403, 404]);
        $this->assertContains($this->get('/ar/applications/'.$dam.'/papers/special-case')->status(), [403, 404]);

        \Illuminate\Support\Facades\Auth::guard()->logout();
        $this->flushSession();
        $this->get('/ar/applications/'.$hms.'/papers/audio-consent')->assertRedirect();
    }
}
