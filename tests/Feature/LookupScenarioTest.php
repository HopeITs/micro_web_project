<?php

namespace Tests\Feature;

use App\Livewire\LookupEditor;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** M4.2 user scenarios: data entry and administrators maintain the lists; city lists per city, shared lists only by all-city editors. */
class LookupScenarioTest extends TestCase
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

        return $account;
    }

    private function damascusClerk(): WebAccount
    {
        $account = app(AccountProvisioner::class)->createPlain('dam.clerk', null, Hash::make('a-long-test-password'), 'test:dam-clerk');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now()]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('DAM'), 'data-entry', 'test:dam-clerk');
        $this->actingAs($account->fresh());

        return $account->fresh();
    }

    public function test_data_entry_sees_the_lists_and_guest_does_not(): void
    {
        $this->as('role.data-entry');
        $this->get('/ar')->assertSee(route('lookups.index', ['locale' => 'ar']));
        $this->get('/ar/lookups')->assertOk()->assertSee(__('lookups.names.streets', [], 'ar'))->assertSee(__('lookups.names.donors', [], 'ar'));
        $this->get('/ar/lookups/streets')->assertOk()->assertSeeLivewire(LookupEditor::class);
        $this->get('/en/lookups/occupations')->assertOk()->assertSee('Occupations and experience');

        $this->as('role.guest');
        $this->get('/ar')->assertDontSee(route('lookups.index', ['locale' => 'ar']));
        $this->get('/ar/lookups')->assertForbidden();
        $this->get('/ar/lookups/streets')->assertForbidden();
    }

    public function test_adding_a_street_belongs_to_the_chosen_city_and_duplicates_are_refused(): void
    {
        $this->as('role.data-entry');
        $hms = $this->city('HMS');
        Livewire::withQueryParams(['city' => $hms])->test(LookupEditor::class, ['list' => 'streets'])
            ->set('draft.name_ar', '  حي   الوعر ')->call('create')->assertHasNoErrors()->assertSee('حي الوعر')
            ->set('draft.name_ar', 'حي الوعر')->call('create')->assertHasErrors('draft.name_ar');
        $this->assertSame(1, DB::table('streets')->where('name_ar', 'حي الوعر')->where('city_id', $hms)->count());
        $this->assertFalse(DB::table('streets')->where('name_ar', 'حي الوعر')->where('city_id', '<>', $hms)->exists());
        // The same name is allowed in another city.
        Livewire::withQueryParams(['city' => $this->city('DAM')])->test(LookupEditor::class, ['list' => 'streets'])
            ->set('draft.name_ar', 'حي الوعر')->call('create')->assertHasNoErrors();
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'lookups.created')->exists());
    }

    public function test_used_value_is_hidden_and_unused_value_is_deleted(): void
    {
        $this->as('super.admin');
        $used = (int) DB::table('projects')->whereNotNull('street_id')->value('street_id');
        $city = (int) DB::table('streets')->where('id', $used)->value('city_id');
        $component = Livewire::withQueryParams(['city' => $city])->test(LookupEditor::class, ['list' => 'streets']);
        $component->call('remove', $used)->assertSee(__('lookups.hidden_used_msg', [], 'ar'));
        $this->assertDatabaseHas('streets', ['id' => $used, 'is_active' => false]);
        // Hidden values leave the list unless asked for, and can be shown again.
        $component->assertDontSee(DB::table('streets')->where('id', $used)->value('name_ar'));
        $component->call('toggle', $used, true);
        $this->assertDatabaseHas('streets', ['id' => $used, 'is_active' => true]);

        $component->set('draft.name_ar', 'شارع مؤقت')->call('create');
        $new = (int) DB::table('streets')->where('name_ar', 'شارع مؤقت')->value('id');
        $component->call('remove', $new)->assertSee(__('lookups.deleted_msg', [], 'ar'));
        $this->assertDatabaseMissing('streets', ['id' => $new]);
    }

    public function test_editing_a_value_with_a_parent_list(): void
    {
        $this->as('super.admin');
        $type = (int) DB::table('education_types')->orderBy('id')->value('id');
        $component = Livewire::test(LookupEditor::class, ['list' => 'education_specialties'])
            ->set('draft.name_ar', 'هندسة الطاقة')->call('create')->assertHasErrors('draft.education_type_id')
            ->set('draft.education_type_id', (string) $type)->call('create')->assertHasNoErrors();
        $id = (int) DB::table('education_specialties')->where('name_ar', 'هندسة الطاقة')->value('id');
        $component->call('startEdit', $id)->set('edit.name_ar', 'هندسة الطاقة المتجددة')->set('edit.name_en', 'Renewable energy')->call('saveEdit')->assertHasNoErrors();
        $this->assertDatabaseHas('education_specialties', ['id' => $id, 'name_ar' => 'هندسة الطاقة المتجددة', 'name_en' => 'Renewable energy', 'education_type_id' => $type]);
    }

    public function test_single_city_editor_changes_its_city_lists_but_not_shared_ones(): void
    {
        $this->damascusClerk();
        $this->get('/ar/lookups')->assertOk()->assertSee(__('lookups.read_only', [], 'ar'));
        Livewire::test(LookupEditor::class, ['list' => 'priests'])->assertSet('city', $this->city('DAM'))
            ->set('draft.name_ar', 'الأب يوحنا')->call('create')->assertHasNoErrors();
        $this->assertDatabaseHas('priests', ['name_ar' => 'الأب يوحنا', 'city_id' => $this->city('DAM')]);

        // Another city's list is refused.
        Livewire::withQueryParams(['city' => $this->city('ALP')])->test(LookupEditor::class, ['list' => 'priests'])->assertSet('city', $this->city('DAM'));
        $aleppoPriest = (int) DB::table('priests')->where('city_id', $this->city('ALP'))->value('id');
        Livewire::test(LookupEditor::class, ['list' => 'priests'])->call('remove', $aleppoPriest)->assertForbidden();

        // A shared list is read only for a one-city editor.
        Livewire::test(LookupEditor::class, ['list' => 'occupations'])->assertSee(__('lookups.read_only_hint', [], 'ar'))
            ->set('draft.name_ar', 'مهنة جديدة')->call('create')->assertForbidden();
        $this->assertDatabaseMissing('occupations', ['name_ar' => 'مهنة جديدة']);
    }

    public function test_export_gives_an_excel_file(): void
    {
        $this->as('role.data-entry');
        $response = $this->get('/ar/lookups/donors/export?format=csv');
        $response->assertOk();
        $this->assertStringContainsString(DB::table('donors')->value('name_ar'), $response->streamedContent());
        $this->get('/ar/lookups/streets/export?format=xlsx&city='.$this->city('LAT'))->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
