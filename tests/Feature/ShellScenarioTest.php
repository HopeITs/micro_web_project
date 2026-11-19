<?php

namespace Tests\Feature;

use App\Livewire\ProjectsIndex;
use App\Models\Project;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Support\CityContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** M2.6 user scenarios on the synthetic data: sign in, home, projects search and city limits. */
class ShellScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function cityId(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function damascusOnlyAccount(): WebAccount
    {
        $account = app(AccountProvisioner::class)->createPlain('test.damascus', null, Hash::make('a-long-test-password'), 'test:damascus-only');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now()]);
        app(AccessRoles::class)->assign($account->fresh(), $this->cityId('DAM'), 'data-entry', 'test:damascus-only');

        return $account->fresh();
    }

    public function test_data_entry_signs_in_and_sees_home_and_projects(): void
    {
        $this->post('/ar/login', ['username' => 'role.data-entry', 'password' => 'password'])->assertRedirect('/ar');
        $this->get('/ar')->assertOk()->assertSee('200')->assertSee('دمشق');
        $this->get('/ar/projects')->assertOk()->assertSeeLivewire(ProjectsIndex::class);
        $this->get('/en/projects')->assertOk()->assertSee('Projects');
    }

    public function test_wrong_password_is_refused_with_a_clear_message(): void
    {
        $this->from('/ar/login')->post('/ar/login', ['username' => 'role.data-entry', 'password' => 'wrong'])->assertRedirect('/ar/login');
        $this->get('/ar/login')->assertSee(__('hc.login_error', [], 'ar'));
        $this->get('/ar')->assertRedirect();
    }

    public function test_search_by_city_code_and_by_number(): void
    {
        app(CityContext::class)->restrictTo(DB::table('cities')->pluck('id'));
        Livewire::test(ProjectsIndex::class)->set('search', 'DAM-1001')
            ->assertSee('DAM-1001')->assertDontSee('HMS-1001');
        Livewire::test(ProjectsIndex::class)->set('search', '1001')
            ->assertSee('DAM-1001')->assertSee('HMS-1001')->assertSee('ALP-1001')->assertSee('LAT-1001')->assertSee('WAD-1001');
        Livewire::test(ProjectsIndex::class)->set('search', '1001')->call('toggleCity', $this->cityId('HMS'))
            ->assertSee('HMS-1001')->assertDontSee('DAM-1001');
    }

    public function test_a_damascus_only_user_never_sees_other_cities(): void
    {
        $account = $this->damascusOnlyAccount();
        $aleppoProject = Project::where('city_id', $this->cityId('ALP'))->value('id');
        $damascusProject = Project::where('city_id', $this->cityId('DAM'))->value('id');

        $this->actingAs($account);
        $this->get('/ar')->assertOk()->assertSee('40')->assertDontSee('حلب');
        $this->get("/ar/projects/{$damascusProject}")->assertOk()->assertSee('DAM-');
        $this->get("/ar/projects/{$aleppoProject}")->assertNotFound();

        app(CityContext::class)->restrictTo([$this->cityId('DAM')]);
        Livewire::test(ProjectsIndex::class)->set('search', '1001')
            ->assertSee('DAM-1001')->assertDontSee('ALP-1001')
            ->call('toggleCity', $this->cityId('ALP'))->assertDontSee('ALP-1001');
    }

    public function test_a_desktop_user_signs_in_with_the_desktop_password_once_then_gets_a_modern_hash(): void
    {
        $salt = random_bytes(16);
        $desktopHash = base64_encode($salt.hash_pbkdf2('sha256', 'desktop-pass-123', $salt, 100_000, 32, true));
        $userId = DB::table('legacy_staff_users')->insertGetId([
            'city_id' => $this->cityId('LAT'), 'legacy_user_id' => 7, 'username' => 'sample.user', 'password_hash' => $desktopHash,
            'legacy_role_id' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $account = app(AccountProvisioner::class)->provision('sample.user', $userId, [$userId], [$this->cityId('LAT') => ['projects.view']], 'test:legacy-login');

        $this->post('/ar/login', ['username' => 'sample.user', 'password' => 'wrong-pass'])->assertSessionHasErrors();
        $this->post('/ar/login', ['username' => 'sample.user', 'password' => 'desktop-pass-123'])->assertRedirect('/ar');
        $this->assertStringStartsWith('$argon2id$', (string) DB::table('web_accounts')->where('id', $account->id)->value('password_hash'));
        $this->get('/ar/projects')->assertOk();
    }

    public function test_guest_role_menu_hides_finance_and_administration(): void
    {
        $this->actingAs(WebAccount::where('username', 'role.guest')->first());
        $this->get('/ar')->assertOk()->assertSee(__('hc.nav_projects', [], 'ar'))
            ->assertDontSee(__('hc.nav_loans', [], 'ar'))->assertDontSee(__('hc.nav_accounts', [], 'ar'));
        $this->get('/ar/design')->assertOk(); // the route exists, the menu item is admin-only
    }
}
