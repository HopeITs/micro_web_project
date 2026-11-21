<?php

namespace Tests\Feature;

use App\Livewire\NationalCheck;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M4.4 user scenarios: a data-entry employee checks a national number in every city and in DISC/F2F. DISC and F2F are simulated
 * with an in-memory SQLite connection whose attached schemas carry the same table and column names as the real databases.
 */
class NationalCheckScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER = '04012345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['database.connections.national_check' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'national_check.enabled' => true]);
        $db = DB::connection('national_check');
        foreach (['hcsyria_disc_a', 'hcsyria_disc_d', 'hcsyria_disc_h', 'hcsyria_f2f_ajudy'] as $schema) {
            $db->statement("ATTACH DATABASE ':memory:' AS $schema");
        }
        foreach (['hcsyria_disc_a', 'hcsyria_disc_d', 'hcsyria_disc_h'] as $schema) {
            $db->statement("CREATE TABLE $schema.student (ID INTEGER PRIMARY KEY, FirstName TEXT, FatherName TEXT, LastName TEXT, NationalNumber TEXT, Mobile TEXT)");
        }
        $db->statement('CREATE TABLE hcsyria_f2f_ajudy.person (id INTEGER PRIMARY KEY, first_name TEXT, father_name TEXT, last_name TEXT, national_Number TEXT)');
        $db->statement('CREATE TABLE hcsyria_f2f_ajudy.person_family (id INTEGER PRIMARY KEY, person_id INTEGER, family_id INTEGER)');
        $db->statement('CREATE TABLE hcsyria_f2f_ajudy.family_partner (id INTEGER PRIMARY KEY, family_id INTEGER, IsActive INTEGER)');
        // DISC Homs holds the number without its leading zero; F2F with it, in an active partnership.
        $db->table('hcsyria_disc_h.student')->insert(['ID' => 1, 'FirstName' => 'طالبة', 'FatherName' => 'تجريبي', 'LastName' => 'ديسك', 'NationalNumber' => '4012345678']);
        $db->table('hcsyria_f2f_ajudy.person')->insert(['id' => 7, 'first_name' => 'شريك', 'father_name' => 'تجريبي', 'last_name' => 'فاميلي', 'national_Number' => self::NUMBER]);
        $db->table('hcsyria_f2f_ajudy.person_family')->insert(['id' => 1, 'person_id' => 7, 'family_id' => 3]);
        $db->table('hcsyria_f2f_ajudy.family_partner')->insert([['id' => 1, 'family_id' => 3, 'IsActive' => 0], ['id' => 2, 'family_id' => 3, 'IsActive' => 1]]);
    }

    private function city(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function damascusClerk(): WebAccount
    {
        $account = app(AccountProvisioner::class)->createPlain('dam.clerk', 'dam.clerk@hcsyria.org', Hash::make('a-long-test-password'), 'test:dam');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now()]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('DAM'), 'data-entry', 'test:dam');
        $this->actingAs($account->fresh());

        return $account->fresh();
    }

    public function test_number_found_in_another_city_and_in_disc_and_f2f(): void
    {
        $this->damascusClerk();
        // An Aleppo beneficiary with a project carries the number with two leading zeros.
        $aleppo = DB::table('project_applicants as a')->join('people as p', 'p.id', '=', 'a.person_id')->where('p.city_id', $this->city('ALP'))->select('p.*')->first();
        DB::table('people')->where('id', $aleppo->id)->update(['national_number' => '0'.self::NUMBER]);

        $this->get('/ar/national-check')->assertOk()->assertSeeLivewire(NationalCheck::class);
        Livewire::test(NationalCheck::class)->set('number', '4012-345-678')->call('check')->assertHasNoErrors()
            ->assertSee(__('national.found_title', ['count' => 3], 'ar'))
            ->assertSee($aleppo->first_name)->assertSee(__('national.other_city', [], 'ar'))
            // Another city's record is named but not linked.
            ->assertDontSee(route('people.show', ['locale' => 'ar', 'person' => $aleppo->id]))
            ->assertSee('طالبة تجريبي ديسك')->assertSee('شريك تجريبي فاميلي')->assertSee(__('national.f2f_active', [], 'ar'));
        // The check is logged, the number is not.
        $event = DB::table('account_audit_events')->where('event_type', 'national.checked')->first();
        $this->assertNotNull($event);
        $this->assertNull($event->decision_locator);
    }

    public function test_unknown_number_and_invalid_input(): void
    {
        $this->damascusClerk();
        Livewire::test(NationalCheck::class)->set('number', '12')->call('check')->assertHasErrors('number')
            ->set('number', '99999999991')->call('check')->assertHasNoErrors()->assertSee(__('national.none_title', [], 'ar'));
    }

    public function test_unreachable_programme_is_reported_not_fatal(): void
    {
        $this->damascusClerk();
        DB::connection('national_check')->statement('DROP TABLE hcsyria_disc_a.student');
        Livewire::test(NationalCheck::class)->set('number', self::NUMBER)->call('check')->assertHasNoErrors()
            ->assertSee(__('national.unavailable', [], 'ar'))->assertSee('طالبة تجريبي ديسك');
    }

    public function test_check_from_a_person_page_and_permitted_link(): void
    {
        $this->damascusClerk();
        $person = DB::table('people')->where('city_id', $this->city('DAM'))->whereNotNull('national_number')->first();
        $this->get('/ar/people/'.$person->id)->assertSee(route('people.national', ['locale' => 'ar', 'person' => $person->id]));
        Livewire::withQueryParams(['person' => $person->id])->test(NationalCheck::class)
            ->assertSet('number', $person->national_number)->assertSee(route('people.show', ['locale' => 'ar', 'person' => $person->id]));
        // Another city's person cannot be loaded through the link.
        $aleppo = (int) DB::table('people')->where('city_id', $this->city('ALP'))->value('id');
        Livewire::withQueryParams(['person' => $aleppo])->test(NationalCheck::class)->assertNotFound();
    }

    public function test_role_without_people_access_and_rate_limit(): void
    {
        $this->actingAs(WebAccount::where('username', 'role.communication')->firstOrFail());
        $this->get('/ar/national-check')->assertForbidden();

        $this->damascusClerk();
        $component = Livewire::test(NationalCheck::class)->set('number', '99999999991');
        for ($i = 0; $i < 30; $i++) {
            $component->call('check');
        }
        $component->call('check')->assertHasErrors('number');
    }

    public function test_disabled_programmes_show_not_enabled(): void
    {
        config(['national_check.enabled' => false]);
        $this->damascusClerk();
        Livewire::test(NationalCheck::class)->set('number', self::NUMBER)->call('check')->assertSee(__('national.off', [], 'ar'));
    }
}
