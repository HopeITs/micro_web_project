<?php

namespace Tests\Feature;

use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\PaperFixtures;
use Tests\TestCase;

/** M4.20 user scenarios: staff print the application form (full and preliminary) exactly as the paper, filled from the saved application. */
class ApplicationPrintScenarioTest extends TestCase
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

    private function signIn(string $login, string $password = 'password'): void
    {
        $this->post('/ar/login', ['login' => (array_key_exists($login, RoleAccountsSeeder::accounts()) ? RoleAccountsSeeder::email($login) : null) ?? $login, 'password' => $password])->assertRedirect('/ar');
    }

    private function project(string $city, string $stage): int
    {
        return (int) DB::table('projects')->where('city_id', $this->city($city))->where('application_stage', $stage)->whereNull('deleted_at')->orderBy('id')->value('id');
    }

    private function oneCityAccount(string $code): string
    {
        $email = 'print.'.strtolower($code).'@hcsyria.org';
        $account = app(AccountProvisioner::class)->createPlain('print.'.strtolower($code), $email, Hash::make('password'), 'test:papers');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city($code), 'data-entry', 'test:papers');

        return $email;
    }

    private function sheets(string $html): int
    {
        return substr_count($html, '<section class="sheet">');
    }

    public function test_the_full_application_prints_on_five_sheets_with_every_value(): void
    {
        $id = $this->project('DAM', 'full');
        PaperFixtures::richApplication($id);
        $this->signIn('super.admin');

        $page = $this->get('/ar/applications/'.$id.'/print')->assertOk();
        $html = $page->getContent();
        $this->assertSame(5, $this->sheets($html));
        $page->assertSee('استمارة المشروع')->assertSee('DAM-')
            ->assertSee('سليمان')->assertSee('ريما')->assertSee('01020304050')->assertSee('1986')->assertSee('Jood Test')
            ->assertSee('ورشة خياطة صغيرة')->assertSee('12,500,000')->assertSee('قصة المستفيد')
            ->assertSee('ماكينة خياطة صناعية')->assertSee('طاولة قص')->assertSee('دورة خياطة متقدمة')->assertSee('ضغط شرياني')
            ->assertSee('ميساء')->assertSee('خياطة منزلية')->assertSee('2,000,000')->assertSee('دين لأحد الأقارب')
            ->assertSee('Bab Sharqi')->assertSee('+963 989 162 100')->assertSee('هل لديك مانع من القيام بزيارات المتابعة');
        // Lookups are names, and the family counts (not form fields) are read too.
        $page->assertSee('مستفيد')->assertSee('<span class="v">5</span>', false)->assertSee('<span class="v">4</span>', false);
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'class="rb on"'), 'the three goals and the yes/no answers are ticked');
    }

    public function test_the_preliminary_application_prints_on_two_sheets_without_the_full_sections(): void
    {
        $id = $this->project('ALP', 'preliminary');
        $this->signIn('super.admin');

        $page = $this->get('/ar/applications/'.$id.'/print')->assertOk();
        $this->assertSame(2, $this->sheets($page->getContent()));
        $page->assertSee('الاستمارة الأولية للمشروع')->assertSee('هذه الاستمارة أوليّة وليست تسجيل معتمد')->assertSee('ALP-')
            ->assertSee('Villat Street')->assertDontSee('مقتـرح ميزانيـة')->assertDontSee('قصة المستفيد')->assertDontSee('موافقات');
    }

    public function test_the_english_page_prints_english_labels(): void
    {
        $id = $this->project('HMS', 'full');
        $this->signIn('super.admin');
        $this->get('/en/applications/'.$id.'/print')->assertOk()->assertSee('Project application')->assertSee('Applicant\'s personal information')->assertSee('Hamidieh');
    }

    public function test_printing_is_recorded_and_limited_to_the_cities_the_account_may_read(): void
    {
        $dam = $this->project('DAM', 'full');
        $hms = $this->project('HMS', 'full');
        $email = $this->oneCityAccount('HMS');

        $this->signIn($email);
        $this->get('/ar/applications/'.$hms.'/print')->assertOk();
        $this->get('/ar/applications/'.$dam.'/print')->assertNotFound();
        $this->assertDatabaseHas('account_audit_events', ['event_type' => 'papers.printed', 'subject_type' => 'application_entity', 'subject_id' => $hms, 'city_id' => $this->city('HMS'), 'decision_locator' => 'paper:F03']);
        $this->assertDatabaseMissing('account_audit_events', ['event_type' => 'papers.printed', 'subject_id' => $dam]);
    }

    public function test_the_preliminary_print_is_recorded_as_the_preliminary_paper(): void
    {
        $id = $this->project('ALP', 'preliminary');
        $this->signIn('super.admin');
        $this->get('/ar/applications/'.$id.'/print')->assertOk();
        $this->assertDatabaseHas('account_audit_events', ['event_type' => 'papers.printed', 'subject_id' => $id, 'decision_locator' => 'paper:F02']);
    }

    public function test_only_the_print_may_be_framed_by_this_site_for_the_review_page(): void
    {
        $id = $this->project('DAM', 'full');
        $this->signIn('super.admin');
        $print = $this->get('/ar/applications/'.$id.'/print')->assertOk();
        $this->assertSame('SAMEORIGIN', $print->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $print->headers->get('Content-Security-Policy'));
        $other = $this->get('/ar/projects/'.$id)->assertOk();
        $this->assertSame('DENY', $other->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $other->headers->get('Content-Security-Policy'));
        $this->assertSame('DENY', $this->get('/ar/papers')->headers->get('X-Frame-Options'));
    }

    public function test_a_guest_cannot_print_and_the_project_page_links_the_print(): void
    {
        $id = $this->project('DAM', 'full');
        $this->get('/ar/applications/'.$id.'/print')->assertRedirect();
        $this->signIn('super.admin');
        $this->get('/ar/projects/'.$id)->assertOk()->assertSee(route('applications.print', ['locale' => 'ar', 'project' => $id]));
        $this->get('/ar/applications/999999/print')->assertNotFound();
    }
}
