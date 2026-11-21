<?php

namespace Tests\Feature;

use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountAuditTrail;
use App\Services\AccountProvisioner;
use App\Services\People\PeopleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** M4.19 user scenarios: an administrator reads what happened in their cities, on the website and in the desktop program's history. */
class AuditLogScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Cache::forget('audit.desktop_tables');
    }

    private function city(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function signIn(string $username, string $password = 'password'): void
    {
        $this->post('/ar/login', ['login' => \Database\Seeders\RoleAccountsSeeder::email($username) ?? $username, 'password' => $password])->assertRedirect('/ar');
    }

    private function accountId(string $username): int
    {
        return (int) WebAccount::where('username', $username)->value('id');
    }

    /** An administrator of one city only (Homs). */
    private function homsAdministrator(): void
    {
        $account = app(AccountProvisioner::class)->createPlain('homs.admin', 'homs.admin@hcsyria.org', Hash::make('password'), 'test:audit');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('HMS'), 'administrator', 'test:audit');
    }

    private function person(string $code): object
    {
        return DB::table('people')->where('city_id', $this->city($code))->whereNotNull('first_name')->orderBy('id')->first();
    }

    private function desktopRow(string $code, int $legacy, string $text, string $table, string $user, string $at): void
    {
        DB::table('desktop_activity_log')->insert(['city_id' => $this->city($code), 'legacy_id' => $legacy, 'log_type' => $text, 'on_table' => $table, 'user_name' => $user, 'logged_at' => $at, 'seen' => false]);
    }

    public function test_administrator_sees_who_changed_which_person_with_a_link(): void
    {
        $person = $this->person('DAM');
        app(AccountAuditTrail::class)->record($this->accountId('role.data-entry'), 'people.updated', 'succeeded', $this->city('DAM'), null, 'person_entity', (int) $person->id);

        $this->signIn('super.admin');
        $this->get('/ar')->assertSee(route('admin.audit', ['locale' => 'ar']));
        $this->get('/ar/admin/audit')->assertOk()
            ->assertSee('سجل الأحداث')->assertSee('role.data-entry')->assertSee('تعديل · شخص')
            ->assertSee($person->first_name)->assertSee(route('people.show', ['locale' => 'ar', 'person' => $person->id]))
            ->assertSee('دخول');
        $this->get('/en/admin/audit')->assertOk()->assertSee('Edited · person')->assertSee('Signed in');
        $this->assertDatabaseHas('account_audit_events', ['actor_account_id' => $this->accountId('super.admin'), 'event_type' => 'logs.listed']);
    }

    public function test_one_city_administrator_sees_only_that_city(): void
    {
        $this->homsAdministrator();
        $dam = $this->person('DAM');
        $hms = $this->person('HMS');
        $trail = app(AccountAuditTrail::class);
        $trail->record($this->accountId('role.data-entry'), 'people.updated', 'succeeded', $this->city('DAM'), null, 'person_entity', (int) $dam->id);
        $trail->record($this->accountId('role.data-entry'), 'people.updated', 'succeeded', $this->city('HMS'), null, 'person_entity', (int) $hms->id);
        $this->desktopRow('DAM', 1, 'Update Person damascus-only', 'Person', 'Rami', '2026-11-18 10:00:00');
        $this->desktopRow('HMS', 1, 'Update Person homs-only', 'Person', 'Simon', '2026-11-18 11:00:00');

        $this->signIn('homs.admin');
        $this->get('/ar/admin/audit')->assertOk()->assertSee($hms->first_name)->assertDontSee(route('people.show', ['locale' => 'ar', 'person' => $dam->id]));
        // Asking for another city's events falls back to the permitted ones.
        $this->get('/ar/admin/audit?city='.$this->city('DAM'))->assertOk()->assertDontSee(route('people.show', ['locale' => 'ar', 'person' => $dam->id]));
        $this->get('/ar/admin/audit/desktop')->assertOk()->assertSee('homs-only')->assertDontSee('damascus-only');
        $csv = $this->get('/ar/admin/audit/desktop/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('homs-only', $csv);
        $this->assertStringNotContainsString('damascus-only', $csv);
    }

    public function test_other_roles_cannot_open_the_audit_log(): void
    {
        $this->signIn('role.data-entry');
        $this->get('/ar')->assertDontSee(route('admin.audit', ['locale' => 'ar']));
        $this->get('/ar/admin/audit')->assertForbidden();
        $this->get('/ar/admin/audit/desktop')->assertForbidden();
        $this->get('/ar/admin/audit/export?format=csv')->assertForbidden();
        $this->assertDatabaseHas('account_audit_events', ['actor_account_id' => $this->accountId('role.data-entry'), 'event_type' => 'access.denied']);
    }

    public function test_filters_by_kind_area_account_and_syria_date(): void
    {
        $person = $this->person('DAM');
        $trail = app(AccountAuditTrail::class);
        $trail->record($this->accountId('role.financial'), 'people.view', 'succeeded', $this->city('DAM'), null, 'person_entity', (int) $person->id);
        $trail->record($this->accountId('role.data-entry'), 'people.updated', 'succeeded', $this->city('DAM'), null, 'person_entity', (int) $person->id);
        $trail->record($this->accountId('role.guest'), 'access.denied', 'denied', $this->city('DAM'));
        // 22:30 UTC on 19 Nov is 01:30 on 20 Nov in Syria.
        DB::table('account_audit_events')->where('event_type', 'access.denied')->update(['occurred_at' => '2026-11-19 22:30:00']);

        $this->signIn('super.admin');
        $this->get('/ar/admin/audit?kind=change')->assertOk()->assertSee('role.data-entry')->assertDontSee('role.financial')->assertDontSee('role.guest');
        $this->get('/ar/admin/audit?kind=view&area=people')->assertOk()->assertSee('role.financial')->assertDontSee('role.data-entry');
        $this->get('/ar/admin/audit?kind=denied')->assertOk()->assertSee('role.guest')->assertSee('2026-11-20 01:30:00')->assertSee('مرفوض');
        $this->get('/ar/admin/audit?kind=denied&from=2026-11-20&to=2026-11-20')->assertOk()->assertSee('role.guest');
        $this->get('/ar/admin/audit?kind=denied&to=2026-11-19')->assertOk()->assertDontSee('role.guest');
        $this->get('/ar/admin/audit?actor=financial')->assertOk()->assertSee('role.financial')->assertDontSee('role.data-entry');
        $this->get('/ar/admin/audit?kind=everything')->assertSessionHasErrors('kind');
    }

    public function test_desktop_history_is_searchable_newest_first(): void
    {
        $this->desktopRow('DAM', 10, 'Update (c)-50 to Project:3881', 'CheckList', 'Simon', '2026-11-20 10:51:19');
        $this->desktopRow('DAM', 11, 'Insert payment 100%', 'Payment', 'Gabi', '2026-11-20 12:00:00');
        $this->desktopRow('ALP', 12, 'Delete image', 'image', 'Rita', '2026-01-08 09:00:00');

        $this->signIn('super.admin');
        $this->get('/ar/admin/audit/desktop')->assertOk()->assertSeeInOrder(['Insert payment', 'Update (c)-50', 'Delete image'])->assertSee('CheckList');
        $this->get('/ar/admin/audit/desktop?table=CheckList')->assertOk()->assertSee('3881')->assertDontSee('Insert payment');
        $this->get('/ar/admin/audit/desktop?user=gab')->assertOk()->assertSee('Insert payment')->assertDontSee('3881');
        $this->get('/ar/admin/audit/desktop?q=3881')->assertOk()->assertSee('Simon')->assertDontSee('Gabi');
        // A percent sign is searched literally, not as a wildcard.
        $this->get('/ar/admin/audit/desktop?q=%25')->assertOk()->assertSee('Insert payment')->assertDontSee('3881');
        $this->get('/ar/admin/audit/desktop?from=2026-11-01&city='.$this->city('DAM'))->assertOk()->assertDontSee('Delete image');
    }

    public function test_exports_carry_every_matching_row_and_are_recorded(): void
    {
        $person = $this->person('DAM');
        app(AccountAuditTrail::class)->record($this->accountId('role.data-entry'), 'people.updated', 'succeeded', $this->city('DAM'), null, 'person_entity', (int) $person->id);
        $this->desktopRow('DAM', 10, 'Update Person', 'Person', 'Simon', '2026-11-20 10:51:19');

        $this->signIn('super.admin');
        $csv = $this->get('/en/admin/audit/export?format=csv&kind=change')->assertOk()->streamedContent();
        $this->assertStringContainsString('Edited · person', $csv);
        $this->assertStringContainsString($person->first_name, $csv);
        $this->assertStringContainsString('people.updated', $csv);
        $this->get('/ar/admin/audit/export?format=xlsx')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $desktop = $this->get('/en/admin/audit/desktop/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Update Person', $desktop);
        $this->assertSame(3, DB::table('account_audit_events')->where('actor_account_id', $this->accountId('super.admin'))->where('event_type', 'reports.exported')->count());
    }

    public function test_change_details_follow_the_v13_rules(): void
    {
        $details = AccountAuditTrail::changes(
            ['mobile' => '0933111222', 'password_hash' => 'old', 'national_number' => '0101', 'note' => 'x', 'updated_at' => '2026-01-01', 'is_active' => 1],
            ['mobile' => '0944333444', 'password_hash' => 'new', 'national_number' => '0202', 'note' => str_repeat('ب', 250), 'updated_at' => '2026-11-21', 'is_active' => true],
        );
        $this->assertSame(['mobile', 'national_number', 'note'], array_column($details, 'field'));
        $this->assertSame(['field' => 'mobile', 'before' => '0933111222', 'after' => '0944333444'], $details[0]);
        $this->assertSame(['field' => 'national_number', 'hidden' => true], $details[1]);
        $this->assertTrue($details[2]['cut']);
        $this->assertSame(AccountAuditTrail::MAX_VALUE + 1, mb_strlen($details[2]['after']));
        $this->assertNull(AccountAuditTrail::changes(['mobile' => '1'], ['mobile' => '1']));

        $many = [];
        for ($i = 0; $i < 80; $i++) {
            $many[] = ['field' => 'f'.$i, 'before' => 'a', 'after' => 'b'];
        }
        $this->assertCount(AccountAuditTrail::MAX_FIELDS, AccountAuditTrail::sanitize($many));

        $this->expectException(\InvalidArgumentException::class);
        app(AccountAuditTrail::class)->record($this->accountId('super.admin'), 'people.view', 'succeeded', $this->city('DAM'), null, 'person_entity', (int) $this->person('DAM')->id, details: [['field' => 'mobile', 'before' => '1', 'after' => '2']]);
    }

    public function test_administrator_sees_what_changed_on_a_person_and_an_email(): void
    {
        $person = $this->person('DAM');
        $input = (array) $person;
        $input['mobile'] = '0944000111';
        $input['national_number'] = '09999999999';
        // The form posts dates as Y-m-d; the test database returns them with a time.
        $input['birth_date'] = $person->birth_date ? substr((string) $person->birth_date, 0, 10) : null;
        app(PeopleService::class)->update(WebAccount::find($this->accountId('role.data-entry')), (int) $person->id, $input, true);
        $event = DB::table('account_audit_events')->where('event_type', 'people.updated')->latest('id')->first();
        $fields = collect(json_decode($event->details, true))->keyBy('field');
        $this->assertSame('0944000111', $fields['mobile']['after']);
        $this->assertSame(['field' => 'national_number', 'hidden' => true], $fields['national_number']);
        $this->assertStringNotContainsString('09999999999', $event->details);

        $this->signIn('super.admin');
        $guest = $this->accountId('role.guest');
        $this->post('/ar/admin/accounts/'.$guest.'/email', ['email' => 'guest.new@hcsyria.org'])->assertRedirect();
        $this->get('/ar/admin/audit?kind=')->assertOk()
            ->assertSee('تغيّر حقل واحد')->assertSee(__('people.fields.mobile', [], 'ar'))->assertSee('0944000111')
            ->assertSee('تغيّر (القيمة لا تُعرض)')->assertDontSee('09999999999')
            ->assertSee('البريد الإلكتروني')->assertSee('guest.new@hcsyria.org');
        $csv = $this->get('/en/admin/audit/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('→ 0944000111', $csv);
        $this->assertStringContainsString('E-mail: role.guest@hcsyria.org → guest.new@hcsyria.org', $csv);
        $this->assertStringNotContainsString('09999999999', $csv);
    }

    public function test_the_log_is_read_only(): void
    {
        $this->desktopRow('DAM', 10, 'Update Person', 'Person', 'Simon', '2026-11-20 10:51:19');
        $this->signIn('super.admin');
        $this->post('/ar/admin/audit')->assertStatus(405);
        $this->post('/ar/admin/audit/desktop')->assertStatus(405);
        // No form on the page posts to the audit log (the layout's own sign-out form is the only POST).
        $html = $this->get('/ar/admin/audit/desktop')->assertOk()->getContent();
        preg_match_all('/<form\b[^>]*>/i', $html, $forms);
        foreach ($forms[0] as $form) {
            $this->assertFalse(stripos($form, 'post') !== false && str_contains($form, 'admin/audit'), $form);
        }
        $this->assertSame(1, DB::table('desktop_activity_log')->count());
    }
}
