<?php

namespace Tests\Feature;

use App\Livewire\FamilyPanel;
use App\Livewire\PeopleIndex;
use App\Livewire\PersonDetails;
use App\Livewire\PersonForm;
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

/** M4.3 user scenarios: search people, register a person with duplicate warnings, keep the family and the sub-tables. */
class PeopleScenarioTest extends TestCase
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

    private function as(string $username): WebAccount
    {
        $account = WebAccount::where('username', $username)->firstOrFail();
        $this->actingAs($account);
        app(CityContext::class)->restrictTo(DB::table('account_city_capabilities')->where('account_id', $account->id)->where('capability', 'projects.view')->whereNull('revoked_at')->pluck('city_id'));

        return $account;
    }

    private function damascusOnly(string $role): WebAccount
    {
        $account = app(AccountProvisioner::class)->createPlain('dam.'.$role, 'dam.'.$role.'@hcsyria.org', Hash::make('a-long-test-password'), 'test:dam');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now()]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('DAM'), $role, 'test:dam');

        return $this->as('dam.'.$role);
    }

    public function test_search_by_name_national_number_and_city(): void
    {
        $this->as('role.data-entry');
        $this->get('/ar/people')->assertOk()->assertSeeLivewire(PeopleIndex::class);
        $person = DB::table('people')->whereNotNull('national_number')->first();
        Livewire::test(PeopleIndex::class)->set('search', substr($person->national_number, -6))->assertSee($person->first_name);
        Livewire::test(PeopleIndex::class)->set('search', $person->first_name.' '.$person->last_name)->assertSee($person->national_number);
        $total = DB::table('people')->where('city_id', $this->city('HMS'))->count();
        Livewire::test(PeopleIndex::class)->call('toggleCity', $this->city('HMS'))->assertSee(number_format($total));
    }

    public function test_guest_reads_but_cannot_register_or_edit(): void
    {
        $this->as('role.guest');
        $person = (int) DB::table('people')->value('id');
        $this->get('/ar/people')->assertOk();
        $this->get('/ar/people/'.$person)->assertOk()->assertDontSee(route('people.edit', ['locale' => 'ar', 'person' => $person]));
        $this->get('/ar/people/create')->assertForbidden();
        $this->get('/ar/people/'.$person.'/edit')->assertForbidden();
        Livewire::test(PersonDetails::class, ['personId' => $person, 'kind' => 'educations'])->set('draft.education_level_id', (string) DB::table('education_levels')->value('id'))->call('add')->assertForbidden();
    }

    public function test_other_city_people_are_invisible(): void
    {
        $this->damascusOnly('data-entry');
        $aleppo = (int) DB::table('people')->where('city_id', $this->city('ALP'))->value('id');
        $this->get('/ar/people/'.$aleppo)->assertNotFound();
        $name = DB::table('people')->where('id', $aleppo)->value('first_name');
        Livewire::test(PeopleIndex::class)->assertDontSee(DB::table('cities')->where('code', 'ALP')->value('name_ar'));
    }

    public function test_registering_a_person_warns_about_the_same_national_number(): void
    {
        $this->damascusOnly('data-entry');
        $existing = DB::table('people')->where('city_id', $this->city('DAM'))->whereNotNull('national_number')->where('national_number', 'like', '0%')->first();
        $form = Livewire::test(PersonForm::class)->assertSet('city', $this->city('DAM'))
            ->set('person.first_name', 'سامتو')->set('person.last_name', 'تجريبيان')->set('person.father_name', 'جورج')->set('person.sex', 'male')
            // The desktop matches the number with or without its leading zero.
            ->set('person.national_number', substr($existing->national_number, 1))
            ->assertSee(__('people.duplicates_title', [], 'ar'))->assertSee($existing->first_name)
            ->call('save')->assertHasErrors('confirm');
        $this->assertDatabaseMissing('people', ['first_name' => 'سامتو', 'last_name' => 'تجريبيان']);

        $form->set('confirmed', true)->call('save')->assertHasNoErrors()->assertRedirect();
        $id = (int) DB::table('people')->where('first_name', 'سامتو')->where('last_name', 'تجريبيان')->value('id');
        $this->assertSame($this->city('DAM'), (int) DB::table('people')->where('id', $id)->value('city_id'));
        // The beneficiary heads a family of its own (temporary book number) as "مستفيد", provider of itself.
        $member = DB::table('family_members')->where('person_id', $id)->first();
        $this->assertSame($id, (int) $member->provider_person_id);
        $this->assertStringStartsWith('DAM-W', DB::table('families')->where('id', $member->family_id)->value('family_number'));
        $this->get('/ar/people/'.$id)->assertOk()->assertSee('سامتو جورج تجريبيان');
    }

    public function test_an_existing_family_book_number_joins_that_family_after_warning(): void
    {
        $this->damascusOnly('data-entry');
        $family = DB::table('families')->where('city_id', $this->city('DAM'))->first();
        $form = Livewire::test(PersonForm::class)->set('person.first_name', 'ريمتا')->set('person.last_name', 'اختباريان')->set('person.sex', 'female')
            ->set('familyNumber', $family->family_number)->assertSee(__('people.dup_family', [], 'ar'))->call('save')->assertHasErrors('confirm');
        $form->set('confirmed', true)->call('save')->assertHasNoErrors();
        $id = (int) DB::table('people')->where('first_name', 'ريمتا')->where('last_name', 'اختباريان')->value('id');
        $this->assertSame((int) $family->id, (int) DB::table('family_members')->where('person_id', $id)->value('family_id'));
    }

    public function test_editing_clears_military_fields_for_women_and_validates(): void
    {
        $this->as('role.data-entry');
        $person = DB::table('people')->where('sex', 'male')->first();
        Livewire::test(PersonForm::class, ['personId' => $person->id])
            ->set('person.birth_date', '2031-02-30')->call('save')->assertHasErrors('person.birth_date')
            ->set('person.birth_date', '1985-04-12')->set('person.military_service', 'منتهية')->set('person.mobile', '0944123456')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('people', ['id' => $person->id, 'mobile' => '0944123456', 'military_service' => 'منتهية']);
        Livewire::test(PersonForm::class, ['personId' => $person->id])->set('person.sex', 'female')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('people', ['id' => $person->id, 'sex' => 'female', 'military_service' => null]);
    }

    public function test_family_members_are_added_edited_and_removed(): void
    {
        $this->as('role.data-entry');
        $head = (int) DB::table('family_members')->whereColumn('person_id', '<>', 'provider_person_id')->value('provider_person_id');
        $city = (int) DB::table('people')->where('id', $head)->value('city_id');
        $before = DB::table('family_members')->where('provider_person_id', $head)->whereNull('deleted_at')->count();
        $relation = (string) DB::table('relations')->where('name_ar', '<>', 'مستفيد')->value('id');
        $panel = Livewire::test(FamilyPanel::class, ['personId' => $head])->set('adding', true)
            ->set('member.first_name', 'جاد')->set('member.last_name', 'حداد')->set('member.sex', 'male')->set('member.birth_date', '2012-05-01')->set('member.relation_id', $relation)
            ->call('add')->assertHasNoErrors()->assertSee('جاد');
        $memberId = (int) DB::table('family_members as m')->join('people as p', 'p.id', '=', 'm.person_id')->where('p.first_name', 'جاد')->value('m.id');
        $this->assertSame($before + 1, DB::table('family_members')->where('provider_person_id', $head)->whereNull('deleted_at')->count());
        $this->assertSame($city, (int) DB::table('family_members')->where('id', $memberId)->value('city_id'));

        $panel->call('startEdit', $memberId, ['relation_id' => (int) $relation, 'has_work' => null])->set('edit.has_work', '1')->set('edit.work_name', 'طالب ويعمل صيفاً')->call('saveEdit')->assertHasNoErrors();
        $this->assertDatabaseHas('family_members', ['id' => $memberId, 'has_work' => true, 'work_name' => 'طالب ويعمل صيفاً']);

        $panel->call('remove', $memberId);
        $this->assertNotNull(DB::table('family_members')->where('id', $memberId)->value('deleted_at'));
        // The person themself stays in their family.
        $own = (int) DB::table('family_members')->where('person_id', $head)->value('id');
        $panel->call('remove', $own)->assertHasErrors('member');
    }

    public function test_sub_tables_add_edit_and_delete_rows(): void
    {
        $this->as('role.data-entry');
        $person = (int) DB::table('people')->value('id');
        $occupation = (string) DB::table('occupations')->value('id');
        $grid = Livewire::test(PersonDetails::class, ['personId' => $person, 'kind' => 'work_experiences'])->set('adding', true)
            ->call('add')->assertHasErrors()
            ->set('draft.occupation_id', $occupation)->set('draft.salary', '350,000')->set('draft.begin_text', '2015')->call('add')->assertHasNoErrors();
        $row = DB::table('person_work_experiences')->where('person_id', $person)->first();
        $this->assertEquals(350000, (float) $row->salary);
        $grid->call('startEdit', $row->id)->set('edit.end_text', '2020')->call('saveEdit')->assertHasNoErrors();
        $this->assertDatabaseHas('person_work_experiences', ['id' => $row->id, 'end_text' => '2020']);
        $grid->call('remove', $row->id);
        $this->assertNotNull(DB::table('person_work_experiences')->where('id', $row->id)->value('deleted_at'));

        Livewire::test(PersonDetails::class, ['personId' => $person, 'kind' => 'goals'])->set('draft.goal_id', (string) DB::table('goals')->value('id'))->call('add')->assertHasNoErrors();
        $this->get('/ar/people/'.$person)->assertOk()->assertSee(DB::table('goals')->value('name_ar'));
    }
}
