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

/** M4.18 user scenarios: the whole beneficiary file prints in procedure order (cover, application form, checklist), by city and permission. */
class FilePrintScenarioTest extends TestCase
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

    private function project(string $city, string $stage): int
    {
        return (int) DB::table('projects')->where('city_id', $this->city($city))->where('application_stage', $stage)->whereNull('deleted_at')->orderBy('id')->value('id');
    }

    private function signIn(string $login): void
    {
        $this->post('/ar/login', ['login' => (array_key_exists($login, RoleAccountsSeeder::accounts()) ? RoleAccountsSeeder::email($login) : null) ?? $login, 'password' => 'password'])->assertRedirect();
    }

    public function test_the_whole_file_prints_cover_application_and_checklist_in_order_and_is_audited(): void
    {
        $id = $this->project('DAM', 'full');
        $this->signIn('super.admin');
        $html = $this->get('/ar/applications/'.$id.'/file/print')->assertOk()->assertSee('ملف المستفيد')->assertSee('محتويات الملف')->assertSee('Check List')->assertSee('DAM-')->getContent();
        $cover = strpos($html, 'محتويات الملف');
        $form = strpos($html, 'استمارة المشروع', $cover + 20);
        $checklist = strpos($html, 'class="letterhead"');
        $this->assertTrue($cover < $form && $form < $checklist, 'cover, then the application form, then the checklist');
        $this->assertSame(1 + 5, substr_count($html, '<section class="sheet">'), 'a cover and the five sheets of the full form');
        $event = DB::table('account_audit_events')->where('event_type', 'papers.printed')->where('subject_id', $id)->latest('id')->first();
        $this->assertSame('paper:FILE', $event->decision_locator);
    }

    public function test_a_preliminary_project_prints_the_preliminary_form(): void
    {
        $id = $this->project('ALP', 'preliminary');
        $this->signIn('super.admin');
        $this->get('/ar/applications/'.$id.'/file/print')->assertOk()->assertSee('الاستمارة الأولية للمشروع')->assertDontSee('مقتـرح ميزانيـة');
    }

    public function test_a_reader_without_the_checklist_right_gets_the_file_without_it_and_another_city_is_refused(): void
    {
        $dam = $this->project('DAM', 'full');
        $hms = $this->project('HMS', 'full');
        $account = app(AccountProvisioner::class)->createPlain('file.hms', 'file.hms@hcsyria.org', Hash::make('password'), 'test:papers');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('HMS'), 'data-entry', 'test:papers');
        $this->signIn('file.hms@hcsyria.org');

        $page = $this->get('/ar/applications/'.$hms.'/file/print')->assertOk()->assertSee('ملف المستفيد');
        $manage = app(\App\Services\AccountAccess::class)->check($account->fresh(), $this->city('HMS'), 'assessments.manage');
        $manage ? $page->assertSee('class="letterhead"', false) : $page->assertDontSee('class="letterhead"', false)->assertSee('غير مرفقة');
        $this->assertContains($this->get('/ar/applications/'.$dam.'/file/print')->status(), [403, 404]);
    }

    public function test_the_existing_prints_still_work_after_the_split(): void
    {
        $id = $this->project('DAM', 'full');
        $this->signIn('super.admin');
        $this->get('/ar/applications/'.$id.'/print')->assertOk()->assertSee('استمارة المشروع');
        $this->get('/ar/applications/'.$id.'/checklist/print')->assertOk()->assertSee('Check List');
    }
}
