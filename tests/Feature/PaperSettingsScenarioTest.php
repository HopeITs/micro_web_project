<?php

namespace Tests\Feature;

use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Papers\PaperCity;
use App\Services\Papers\PaperSettings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** M4.20 user scenarios: the people who keep the papers set each city's footer details and its signatories; others are refused; changes are audited. */
class PaperSettingsScenarioTest extends TestCase
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

    private function signIn(string $login): void
    {
        $this->post('/ar/login', ['login' => (array_key_exists($login, RoleAccountsSeeder::accounts()) ? RoleAccountsSeeder::email($login) : null) ?? $login, 'password' => 'password'])->assertRedirect();
    }

    private function account(string $name, string $city, string $role): string
    {
        $account = app(AccountProvisioner::class)->createPlain($name, $name.'@hcsyria.org', Hash::make('password'), 'test:papers');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city($city), $role, 'test:papers');

        return $name.'@hcsyria.org';
    }

    public function test_the_footer_comes_from_the_city_settings_and_falls_back_to_the_built_in_text(): void
    {
        $this->assertSame('Syria - Aleppo Villat Street', PaperCity::footer('ALP')['address']);
        $this->signIn('super.admin');
        $alp = $this->city('ALP');
        $this->post('/ar/papers/settings', ['city' => $alp, 'address_en' => 'Syria - Aleppo New Street', 'phone_1' => '+963 900 000 000', 'office_days' => 'الأحد - الخميس'])->assertRedirect();
        $this->assertSame(['address' => 'Syria - Aleppo New Street', 'mobile' => '+963 900 000 000'], PaperCity::footer('ALP'));
        $this->assertSame('Syria - Damascus Bab Sharqi, Kasabeh Alley', PaperCity::footer('DAM')['address'], 'another city keeps its built-in text');
        $page = $this->get('/ar/papers/settings?city='.$alp)->assertOk()->assertSee('Syria - Aleppo New Street')->assertSee('الأحد - الخميس');
        $page->assertSee('إعدادات أوراق المدينة');
        $event = DB::table('account_audit_events')->where('event_type', 'papers.settings_saved')->latest('id')->first();
        $this->assertSame($alp, (int) $event->city_id);
        $this->assertNotNull($event->details);
    }

    public function test_signatories_are_added_edited_removed_and_the_one_valid_today_is_found(): void
    {
        $this->signIn('super.admin');
        $dam = $this->city('DAM');
        $this->post('/ar/papers/settings/signatory', ['city' => $dam, 'role' => 'legal_representative', 'full_name' => 'سامر الأول', 'father_name' => 'أحمد', 'valid_from' => '2020-01-01', 'valid_to' => '2024-12-31'])->assertRedirect();
        $this->post('/ar/papers/settings/signatory', ['city' => $dam, 'role' => 'legal_representative', 'full_name' => 'سامر الثاني', 'national_number' => '01020304050', 'valid_from' => '2025-01-01'])->assertRedirect();
        $this->assertSame('سامر الثاني', PaperSettings::signatory($dam, 'legal_representative')->full_name);
        $this->assertSame('سامر الأول', PaperSettings::signatory($dam, 'legal_representative', '2023-06-01')->full_name);
        $this->assertNull(PaperSettings::signatory($dam, 'director'));

        $first = (int) DB::table('city_paper_signatories')->where('full_name', 'سامر الأول')->value('id');
        $this->get('/ar/papers/settings?city='.$dam.'&signatory='.$first)->assertOk()->assertSee('سامر الأول');
        $this->post('/ar/papers/settings/signatory', ['city' => $dam, 'id' => $first, 'role' => 'director', 'full_name' => 'سامر المدير'])->assertRedirect();
        $this->assertSame('director', DB::table('city_paper_signatories')->where('id', $first)->value('role'));

        $this->post('/ar/papers/settings/signatory', ['city' => $dam, 'role' => 'director', 'full_name' => ''])->assertSessionHasErrors('full_name');
        $this->post('/ar/papers/settings/signatory', ['city' => $dam, 'role' => 'director', 'full_name' => 'س', 'valid_from' => '2025-05-01', 'valid_to' => '2025-01-01'])->assertSessionHasErrors('valid_to');
        $this->post('/ar/papers/settings/signatory', ['city' => $dam, 'role' => 'boss', 'full_name' => 'س'])->assertSessionHasErrors('role');

        $this->delete('/ar/papers/settings/signatory', ['city' => $dam, 'id' => $first])->assertRedirect();
        $this->assertNotNull(DB::table('city_paper_signatories')->where('id', $first)->value('deleted_at'));
        $this->assertSame(4, DB::table('account_audit_events')->whereIn('event_type', ['papers.signatory_saved', 'papers.signatory_removed'])->count());
    }

    public function test_only_papers_managers_of_the_city_may_change_it(): void
    {
        $email = $this->account('paper.dam', 'DAM', 'data-entry');
        $this->signIn($email);
        $this->get('/ar/papers/settings')->assertForbidden();
        $this->post('/ar/papers/settings', ['city' => $this->city('DAM'), 'phone_1' => '1'])->assertForbidden();

        $media = $this->account('media.dam', 'DAM', 'media');
        \Illuminate\Support\Facades\Auth::guard()->logout();
        $this->flushSession();
        $this->signIn($media);
        $this->get('/ar/papers/settings')->assertOk();
        $this->post('/ar/papers/settings', ['city' => $this->city('HMS'), 'phone_1' => '1'])->assertForbidden();
        $this->assertSame(0, DB::table('city_paper_settings')->where('city_id', $this->city('HMS'))->count());
    }
}
