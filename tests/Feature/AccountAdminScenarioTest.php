<?php

namespace Tests\Feature;

use App\Models\WebAccount;
use App\Services\AccessRoles;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** M4.1 user scenarios: an administrator manages accounts, roles, desktop identities and task responsibilities city by city. */
class AccountAdminScenarioTest extends TestCase
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

    private function signIn(string $username, string $password = 'password'): void
    {
        $this->post('/ar/login', ['login' => (array_key_exists($username, RoleAccountsSeeder::accounts()) ? RoleAccountsSeeder::email($username) : null) ?? $username, 'password' => $password])->assertRedirect('/ar');
    }

    private function desktopUser(string $username, string $code): int
    {
        return (int) DB::table('legacy_staff_users')->where('username', $username)->where('city_id', $this->city($code))->value('id');
    }

    private function accountId(string $username): int
    {
        return (int) WebAccount::where('username', $username)->value('id');
    }

    public function test_administrator_lists_and_filters_accounts(): void
    {
        $this->signIn('super.admin');
        $this->get('/ar')->assertSee(route('admin.accounts.index', ['locale' => 'ar']));
        $this->get('/ar/admin/accounts')->assertOk()->assertSee('role.financial')->assertSee('role.guest');
        $this->get('/ar/admin/accounts?role=financial')->assertOk()->assertSee('role.financial')->assertDontSee('role.guest');
        $this->get('/ar/admin/accounts?q=legal')->assertOk()->assertSee('role.legal')->assertDontSee('role.media');
        $this->get('/en/admin/accounts')->assertOk()->assertSee('Staff accounts');
    }

    public function test_other_roles_cannot_open_account_administration(): void
    {
        $this->signIn('role.data-entry');
        $this->get('/ar')->assertDontSee(route('admin.accounts.index', ['locale' => 'ar']));
        $this->get('/ar/admin/accounts')->assertForbidden();
        $this->get('/ar/admin/tasks')->assertForbidden();
        $this->post('/ar/admin/accounts/plain', ['username' => 'x', 'email' => 'x@hcsyria.org', 'roles' => [$this->city('DAM') => 'administrator']])->assertForbidden();
        $this->assertDatabaseMissing('web_accounts', ['username' => 'x']);
    }

    public function test_new_staff_member_gets_a_one_time_password_and_only_their_city(): void
    {
        $this->signIn('super.admin');
        $response = $this->post('/ar/admin/accounts/plain', ['username' => 'new.clerk', 'email' => 'New.Clerk@HCSyria.org', 'roles' => [$this->city('DAM') => 'data-entry']]);
        $id = $this->accountId('new.clerk');
        $response->assertRedirect('/ar/admin/accounts/'.$id)->assertSessionHas('one_time_password');
        $password = session('one_time_password');
        $this->get('/ar/admin/accounts/'.$id)->assertOk()->assertSee($password)->assertSee('new.clerk@hcsyria.org');
        $this->assertSame(['data-entry'], app(AccessRoles::class)->roles(WebAccount::find($id), $this->city('DAM')));
        $this->assertSame([], app(AccessRoles::class)->roles(WebAccount::find($id), $this->city('HMS')));

        $this->post('/ar/logout');
        $this->post('/ar/login', ['login' => 'new.clerk@hcsyria.org', 'password' => $password]);
        $this->get('/ar/projects')->assertRedirect('/ar/account/password');
        $this->get('/ar/account/password')->assertOk()->assertSee(__('account.first_login', [], 'ar'));
        $this->post('/ar/account/password', ['password' => 'a-new-long-password-1', 'password_confirmation' => 'a-new-long-password-1'])->assertRedirect();
        $this->get('/ar/projects')->assertOk();
        $this->get('/ar/admin/accounts')->assertForbidden();
    }

    public function test_duplicate_desktop_users_become_one_account_after_review(): void
    {
        $this->signIn('super.admin');
        $this->get('/ar/admin/accounts/new')->assertOk()->assertSee('rami.haddad')->assertSee(__('admin.duplicates', ['count' => 2], 'ar'))
            ->assertDontSee('old.user')->assertDontSee('left.staff');
        $dam = $this->desktopUser('rami.haddad', 'DAM');
        $hms = $this->desktopUser('rami.haddad', 'HMS');

        // Without the review confirmation nothing is created.
        $this->post('/ar/admin/accounts', ['identities' => [$dam, $hms], 'credential' => $dam])->assertSessionHasErrors('confirm');
        $this->assertDatabaseMissing('web_accounts', ['username' => 'rami.haddad']);

        $this->post('/ar/admin/accounts', ['identities' => [$dam, $hms], 'credential' => $dam, 'confirm' => '1', 'roles' => [$this->city('DAM') => 'data-entry', $this->city('HMS') => 'monitoring']]);
        $id = $this->accountId('rami.haddad');
        $this->assertGreaterThan(0, $id);
        $this->assertSame(2, DB::table('account_legacy_links')->where('account_id', $id)->whereNull('revoked_at')->count());
        $this->assertSame(['monitoring'], app(AccessRoles::class)->roles(WebAccount::find($id), $this->city('HMS')));
        $this->get('/ar/admin/accounts/new')->assertDontSee('rami.haddad');

        // The credential link cannot be revoked; the other one can.
        $credentialLink = (int) DB::table('account_legacy_links')->where('account_id', $id)->where('legacy_staff_user_id', $dam)->value('id');
        $otherLink = (int) DB::table('account_legacy_links')->where('account_id', $id)->where('legacy_staff_user_id', $hms)->value('id');
        $this->post("/ar/admin/accounts/{$id}/links/{$credentialLink}", ['revoked' => '1'])->assertSessionHasErrors('link');
        $this->post("/ar/admin/accounts/{$id}/links/{$otherLink}", ['revoked' => '1'])->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('account_legacy_links')->where('id', $otherLink)->value('revoked_at'));

        // The person signs in with their desktop password.
        $this->post('/ar/logout');
        // The new account has no institution e-mail yet, so it signs in by user name and must add its e-mail first.
        $this->signIn('rami.haddad');
        $this->get('/ar/projects')->assertRedirect('/ar/account/email');
    }

    public function test_unusable_desktop_password_cannot_be_the_credential(): void
    {
        $this->signIn('super.admin');
        $broken = $this->desktopUser('broken.hash', 'ALP');
        $this->post('/ar/admin/accounts', ['identities' => [$broken], 'credential' => $broken, 'confirm' => '1'])->assertSessionHasErrors('credential');
        $this->assertDatabaseMissing('web_accounts', ['username' => 'broken.hash']);
    }

    public function test_roles_and_capabilities_change_per_city_and_disable_stops_login(): void
    {
        $this->signIn('super.admin');
        $guest = $this->accountId('role.guest');
        $dam = $this->city('DAM');
        $this->post("/ar/admin/accounts/{$guest}/role", ['city' => $dam, 'role' => 'financial'])->assertSessionHasNoErrors();
        $this->assertTrue(DB::table('account_city_capabilities')->where(['account_id' => $guest, 'city_id' => $dam, 'capability' => 'finance.manage'])->whereNull('revoked_at')->exists());
        $this->post("/ar/admin/accounts/{$guest}/role/revoke", ['city' => $dam, 'role' => 'financial'])->assertSessionHasNoErrors();
        $this->assertFalse(DB::table('account_city_capabilities')->where(['account_id' => $guest, 'city_id' => $dam, 'capability' => 'finance.manage'])->whereNull('revoked_at')->exists());
        // The guest role's own read capability survives the financial role's removal.
        $this->assertTrue(DB::table('account_city_capabilities')->where(['account_id' => $guest, 'city_id' => $dam, 'capability' => 'projects.view'])->whereNull('revoked_at')->exists());

        $this->post("/ar/admin/accounts/{$guest}/capabilities", ['city' => $dam, 'capabilities' => ['projects.view']])->assertSessionHasNoErrors();
        $this->assertFalse(DB::table('account_city_capabilities')->where(['account_id' => $guest, 'city_id' => $dam, 'capability' => 'people.view'])->whereNull('revoked_at')->exists());

        $this->post("/ar/admin/accounts/{$guest}/disable", ['disabled' => '1'])->assertSessionHasNoErrors();
        $this->post('/ar/logout');
        $this->from('/ar/login')->post('/ar/login', ['login' => 'role.guest@hcsyria.org', 'password' => 'password'])->assertRedirect('/ar/login');
        $this->assertGuest();
    }

    public function test_administrator_cannot_change_their_own_account(): void
    {
        $this->signIn('super.admin');
        $self = $this->accountId('super.admin');
        $this->get("/ar/admin/accounts/{$self}")->assertOk()->assertSee(__('admin.self', [], 'ar'));
        $this->post("/ar/admin/accounts/{$self}/role/revoke", ['city' => $this->city('DAM'), 'role' => 'administrator'])->assertForbidden();
        $this->post("/ar/admin/accounts/{$self}/disable", ['disabled' => '1'])->assertForbidden();
        $this->assertNull(WebAccount::find($self)->disabled_at);
    }

    public function test_city_administrator_only_sees_and_changes_their_city(): void
    {
        $this->signIn('super.admin');
        $this->post('/ar/admin/accounts/plain', ['username' => 'lat.admin', 'email' => 'lat.admin@hcsyria.org', 'roles' => [$this->city('LAT') => 'administrator']]);
        $password = session('one_time_password');
        $this->post('/ar/logout');
        $this->post('/ar/login', ['login' => 'lat.admin@hcsyria.org', 'password' => $password]);
        $this->post('/ar/account/password', ['password' => 'lat-admin-long-password', 'password_confirmation' => 'lat-admin-long-password']);

        $this->get('/ar/admin/accounts/new')->assertOk()->assertSee('maya.saleh')->assertDontSee('rami.haddad');
        $guest = $this->accountId('role.guest');
        $this->post("/ar/admin/accounts/{$guest}/role", ['city' => $this->city('DAM'), 'role' => 'financial'])->assertForbidden();
        $this->post("/ar/admin/accounts/{$guest}/role", ['city' => $this->city('LAT'), 'role' => 'financial'])->assertSessionHasNoErrors();
        // The guest is active in other cities too, so a single-city administrator cannot disable it.
        $this->post("/ar/admin/accounts/{$guest}/disable", ['disabled' => '1'])->assertForbidden();
    }

    public function test_task_responsibilities_are_saved_per_city(): void
    {
        $this->signIn('super.admin');
        $dam = $this->city('DAM');
        $task = (int) DB::table('task_definitions')->where('name_ar', '<>', '')->orderBy('id')->value('id');
        $financial = $this->accountId('role.financial');
        $guest = $this->accountId('role.guest');
        $this->get('/ar/admin/tasks?city='.$dam)->assertOk()->assertSee('role.financial')->assertDontSee('role.guest');

        $this->post('/ar/admin/tasks', ['city' => $dam, 'assign' => [$task => [$financial]]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(DB::table('account_city_tasks')->where(['city_id' => $dam, 'task_definition_id' => $task, 'account_id' => $financial])->whereNull('revoked_at')->exists());
        $this->assertFalse(DB::table('account_city_tasks')->where(['city_id' => $this->city('HMS'), 'account_id' => $financial])->exists());

        // A guest cannot manage tasks, so it cannot be made responsible.
        $this->post('/ar/admin/tasks', ['city' => $dam, 'assign' => [$task => [$guest]]])->assertSessionHasErrors('tasks');

        $this->post('/ar/admin/tasks', ['city' => $dam, 'assign' => []])->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('account_city_tasks')->where(['city_id' => $dam, 'task_definition_id' => $task, 'account_id' => $financial])->value('revoked_at'));
    }

    public function test_staff_directory_shows_desktop_users_and_links(): void
    {
        $this->signIn('super.admin');
        $this->get('/ar/admin/staff?city='.$this->city('DAM'))->assertOk()->assertSee('rami.haddad')->assertSee('old.user')->assertSee(__('admin.unlinked', [], 'ar'))->assertDontSee('maya.saleh');
    }
}
