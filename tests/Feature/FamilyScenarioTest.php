<?php

namespace Tests\Feature;

use App\Livewire\FamiliesIndex;
use App\Livewire\FamilyPanel;
use App\Livewire\PeopleIndex;
use App\Livewire\PersonForm;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Applications\ApplicationFields;
use App\Services\People\FamilyService;
use App\Services\People\Households;
use App\Services\People\PeopleService;
use App\Support\CityContext;
use App\Support\Datasets\PeopleDataset;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Url;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Families as the desktop knows them: households by their beneficiary (several may share one book number), each member's role and
 * details, live counts, search, attention flags and member changes. Every family here is invented by the test.
 */
class FamilyScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('ar');
    }

    private function city(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function id(string $table, string $nameAr): int
    {
        return (int) DB::table($table)->where('name_ar', $nameAr)->value('id');
    }

    private function as(string $username): WebAccount
    {
        $account = WebAccount::where('username', $username)->firstOrFail();
        $this->actingAs($account);
        app(CityContext::class)->restrictTo(DB::table('account_city_capabilities')->where('account_id', $account->id)->where('capability', 'projects.view')->whereNull('revoked_at')->pluck('city_id'));

        return $account;
    }

    private function damascusOnly(string $role = 'data-entry'): WebAccount
    {
        $account = app(AccountProvisioner::class)->createPlain('fam.'.$role, 'fam.'.$role.'@hcsyria.org', Hash::make('a-long-test-password'), 'test:fam');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now()]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('DAM'), $role, 'test:fam');

        return $this->as('fam.'.$role);
    }

    private function family(string $number, string $city = 'DAM'): int
    {
        return (int) DB::table('families')->insertGetId(['city_id' => $this->city($city), 'family_number' => $number, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * A person with a family row. $head null = the beneficiary themself.
     *
     * @param  array<string, mixed>  $person
     * @param  array<string, mixed>  $row
     * @return array{0: int, 1: int} [person id, family row id]
     */
    private function member(int $family, ?int $head, string $relation, array $person = [], array $row = []): array
    {
        $city = (int) DB::table('families')->where('id', $family)->value('city_id');
        $now = now();
        $id = (int) DB::table('people')->insertGetId($person + [
            'city_id' => $city, 'first_name' => 'فردتا', 'last_name' => 'اختبارجي', 'father_name' => 'والدو', 'sex' => 'male', 'birth_date' => '1990-01-01',
            'national_number' => '0'.random_int(1000000000, 9999999999), 'person_state_id' => $this->id('person_states', 'مقيم/ة'), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $rowId = (int) DB::table('family_members')->insertGetId($row + [
            'city_id' => $city, 'family_id' => $family, 'person_id' => $id, 'relation_id' => $this->id('relations', $relation),
            'provider_person_id' => $head ?? $id, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [$id, $rowId];
    }

    /** Two households on one book: a father with a wife, a working son and a small daughter; and a second beneficiary alone. */
    private function sharedBook(): array
    {
        $family = $this->family('DAM-77001');
        [$head, $headRow] = $this->member($family, null, 'مستفيد', ['first_name' => 'مرهفو', 'father_name' => 'جدو', 'last_name' => 'التجريبي', 'family_size' => 4, 'household_size' => 4]);
        [$wife] = $this->member($family, $head, 'زوج/ة', ['first_name' => 'وردتا', 'last_name' => 'التجريبي', 'sex' => 'female', 'birth_date' => '1992-04-05', 'mobile' => '0944000111']);
        [$son, $sonRow] = $this->member($family, $head, 'ابن/ة', ['first_name' => 'نجمو', 'father_name' => 'مرهفو', 'last_name' => 'التجريبي', 'birth_date' => '2004-02-03', 'national_number' => '04512345678'], ['has_work' => true, 'work_name' => 'نجار']);
        [$daughter] = $this->member($family, $head, 'ابن/ة', ['first_name' => 'زهرتا', 'father_name' => 'مرهفو', 'last_name' => 'التجريبي', 'sex' => 'female', 'birth_date' => now()->subYears(3)->format('Y-m-d'),
            'national_number' => null, 'person_state_id' => $this->id('person_states', 'مسافر/ة')]);
        [$other] = $this->member($family, null, 'مستفيد', ['first_name' => 'سندبادو', 'last_name' => 'الثاني', 'family_size' => 1, 'household_size' => 1]);
        DB::table('person_work_experiences')->insert(['city_id' => $this->city('DAM'), 'person_id' => $son, 'status' => 'yes', 'description' => 'ورشة تجريبية', 'salary' => 250000, 'created_at' => now(), 'updated_at' => now()]);

        return compact('family', 'head', 'headRow', 'wife', 'son', 'sonRow', 'daughter', 'other');
    }

    private function panel(array $book): Testable
    {
        return Livewire::test(FamilyPanel::class, ['familyId' => $book['family'], 'headId' => $book['head'], 'compact' => false]);
    }

    private function counts(int $person): array
    {
        $row = DB::table('people')->where('id', $person)->first();

        return [(int) $row->family_size, (int) $row->household_size];
    }

    public function test_households_sharing_a_book_are_listed_apart_with_live_counts(): void
    {
        $book = $this->sharedBook();
        $this->damascusOnly();
        $this->get('/ar/families')->assertOk()->assertSeeLivewire(FamiliesIndex::class);
        $this->assertDatabaseHas('account_audit_events', ['event_type' => 'people.list']);

        $list = Livewire::test(FamiliesIndex::class)->set('search', 'DAM-77001');
        $rows = collect($list->viewData('households')->items())->keyBy('head_id');
        $this->assertCount(2, $rows);
        $first = $rows[$book['head']];
        $this->assertSame([4, 3, 1, 1], [$first->members, $first->residents, $first->working, $first->children]);
        $this->assertSame([1, 1], [$rows[$book['other']]->members, $rows[$book['other']]->residents]);
        $this->assertTrue($first->shared);
        // Stored: 4 and 4; three are at home. The daughter has no national number.
        $this->assertTrue($first->counts_differ);
        $this->assertSame(1, $first->incomplete);
        $list->assertSee('مرهفو جدو التجريبي')->assertSee(__('families.shared_badge', ['count' => 2]));
        $this->assertSame(2, $list->viewData('totals')['households']);
        $this->assertSame(2, $list->viewData('totals')['shared']);
        $this->assertSame(1, $list->viewData('totals')['attention']);
        $list->call('setQuick', 'attention');
        $this->assertSame([$book['head']], array_column($list->viewData('households')->items(), 'head_id'));
        $list->call('setQuick', 'noworker');
        $this->assertSame([$book['other']], array_column($list->viewData('households')->items(), 'head_id'));
        // Every quick filter's page count is the number of households it shows.
        foreach (['attention' => 1, 'shared' => 2, 'children' => 1, 'noworker' => 1, 'projects' => 0, 'noprojects' => 2] as $quick => $expected) {
            $filtered = Livewire::test(FamiliesIndex::class)->set('search', 'DAM-77001')->call('setQuick', $quick)->viewData('households');
            $this->assertSame([$expected, $expected], [$filtered->total(), count($filtered->items())], $quick);
        }
        $list->call('setQuick', 'noworker')->set('size', '4-5');
        $this->assertSame([$book['head']], array_column($list->viewData('households')->items(), 'head_id'));
        $list->set('size', '')->call('sortBy', 'members');
        $this->assertSame([$book['head'], $book['other']], array_column($list->viewData('households')->items(), 'head_id'));
        $list->call('sortBy', 'members');
        $this->assertSame([$book['other'], $book['head']], array_column($list->viewData('households')->items(), 'head_id'));
    }

    public function test_search_finds_a_household_by_any_members_name_number_or_mobile_without_putting_it_in_the_address(): void
    {
        $book = $this->sharedBook();
        $this->damascusOnly();
        // A member's name, their national number (with or without the leading zero) and the wife's mobile.
        foreach (['زهرتا', 'نجمو التجريبي', '04512345678', '4512345678', '0944000111', 'وردتا, nothing-here'] as $term) {
            $list = Livewire::test(FamiliesIndex::class)->set('search', $term);
            $this->assertSame([$book['head']], array_column($list->viewData('households')->items(), 'head_id'), $term);
        }
        $list = Livewire::test(FamiliesIndex::class)->set('search', 'زهرتا');
        $list->assertSee(__('families.matched'));
        $this->assertSame([$book['daughter']], $list->viewData('households')->items()[0]->matched->pluck('person_id')->all());
        // The whole household is counted, not only the matching member.
        $this->assertSame(4, $list->viewData('households')->items()[0]->members);
        $this->assertSame([], Livewire::test(FamiliesIndex::class)->set('search', 'لا-أحد-بهذا')->viewData('households')->items());
        $address = (new \ReflectionClass(FamiliesIndex::class))->getProperty('search')->getAttributes(Url::class);
        $this->assertSame([], $address);
    }

    public function test_the_list_uses_the_same_number_of_queries_whatever_the_page_holds(): void
    {
        $this->as('role.data-entry');
        $service = app(FamilyService::class);
        $cities = $service->cityIds(auth()->user());
        $count = function (int $perPage) use ($service, $cities): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $page = $service->page($cities, [], $perPage);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$queries, count($page->items())];
        };
        [$few, $shownFew] = $count(20);
        [$many, $shownMany] = $count(100);
        $this->assertGreaterThan($shownFew, $shownMany);
        $this->assertSame($few, $many);
        $this->assertLessThan(25, $many);
    }

    public function test_the_family_page_labels_the_head_and_shows_each_household_with_what_needs_attention(): void
    {
        $book = $this->sharedBook();
        $this->damascusOnly();
        $this->get('/ar/families/'.$book['family'])->assertOk()->assertSee('أسرة مرهفو جدو التجريبي')->assertSee('أسرة سندبادو والدو الثاني')
            ->assertSee(__('families.several_title', ['count' => 2]))->assertSee('DAM-77001')->assertSee('زهرتا');
        foreach (['head', 'other'] as $who) {
            $this->assertDatabaseHas('account_audit_events', ['event_type' => 'people.view', 'subject_type' => 'person_entity', 'subject_id' => $book[$who]]);
        }
        $home = app(FamilyService::class)->household($book['family'], $book['head']);
        $this->assertSame(['members' => 4, 'residents' => 3, 'working' => 1, 'children' => 1, 'income' => 250000.0, 'projects' => 0], $home['totals']);
        $this->assertSame($book['head'], $home['members'][0]->person_id);
        $this->assertTrue($home['members'][0]->is_head);
        $flags = collect($home['flags'])->keyBy('key');
        $this->assertSame([$book['daughter']], collect($flags['national']['members'])->pluck('person_id')->all());
        $this->assertSame(['family_size' => 4, 'household_size' => 3], $flags['counts']['computed']);
        $this->assertArrayNotHasKey('no_head', $flags->all());
        $this->assertSame([$book['head'], $book['other']], app(FamilyService::class)->householdsOfNumber($this->city('DAM'), 'DAM-77001')->pluck('head_id')->all());
        // The same number on another family record of the city is named on the page.
        $twin = $this->family('DAM-77001');
        [$third] = $this->member($twin, null, 'مستفيد', ['first_name' => 'توأمو', 'last_name' => 'المكرر']);
        $this->get('/ar/families/'.$book['family'])->assertSee(__('families.elsewhere_title'))->assertSee('توأمو والدو المكرر')
            ->assertSee(route('families.show', ['locale' => 'ar', 'family' => $twin]).'#h-'.$third, false);
        // The son works and has a work row: no "work table" reminder; without the row there is one.
        $this->assertArrayNotHasKey('work', $flags->all());
        DB::table('person_work_experiences')->where('person_id', $book['son'])->update(['deleted_at' => now()]);
        $this->assertArrayHasKey('work', collect(app(FamilyService::class)->household($book['family'], $book['head'])['flags'])->keyBy('key')->all());

        // The stored figures change only when asked, and only for this household.
        $this->panel($book)->call('recount')->assertHasNoErrors();
        $this->assertSame([4, 3], $this->counts($book['head']));
        $this->assertSame([1, 1], $this->counts($book['other']));
    }

    public function test_family_wide_tables_name_the_member_and_their_relation(): void
    {
        $book = $this->sharedBook();
        $this->damascusOnly();
        $now = now();
        $base = ['city_id' => $this->city('DAM'), 'person_id' => $book['wife'], 'created_at' => $now, 'updated_at' => $now];
        DB::table('person_illnesses')->insert($base + ['note' => 'ملاحظة صحية تجريبية']);
        DB::table('person_assets')->insert($base + ['description' => 'ملك تجريبي للزوجة']);
        DB::table('person_aid_sources')->insert($base + ['notes' => 'مساعدة تجريبية سابقة']);
        $this->panel($book)->assertSee('ملاحظة صحية تجريبية')->assertSee('ملك تجريبي للزوجة')->assertSee('مساعدة تجريبية سابقة')->assertSee('ورشة تجريبية')->assertSee('زوج/ة')->assertSee('نجار');
    }

    public function test_adding_from_a_childs_page_gives_the_head_as_provider_and_updates_the_counts(): void
    {
        $book = $this->sharedBook();
        $this->damascusOnly();
        $resident = $this->id('person_states', 'مقيم/ة');
        $panel = Livewire::test(FamilyPanel::class, ['personId' => $book['son']])->assertSet('headId', $book['head'])->call('openAdd')
            ->assertSet('member.person_state_id', (string) $resident)
            ->set('member.relation_id', (string) $this->id('relations', 'ابن/ة'))
            // Suggested from the head, only into empty fields.
            ->assertSet('member.father_name', 'مرهفو')->assertSet('member.last_name', 'التجريبي')
            ->set('member.first_name', 'بدرو')->set('member.sex', 'male')->set('member.birth_date', '2015')
            ->call('add')->assertHasNoErrors();
        $new = DB::table('people')->where('first_name', 'بدرو')->first();
        $row = DB::table('family_members')->where('person_id', $new->id)->first();
        $this->assertSame($book['head'], (int) $row->provider_person_id);
        $this->assertSame($book['family'], (int) $row->family_id);
        $this->assertSame($resident, (int) $new->person_state_id);
        $this->assertStringStartsWith('2015-01-01', (string) $new->birth_date);
        $this->assertSame([5, 4], $this->counts($book['head']));
        $details = (string) DB::table('account_audit_events')->where('event_type', 'people.updated')->where('subject_id', $book['head'])->orderByDesc('id')->value('details');
        $this->assertStringContainsString('family.member', $details);
        $this->assertStringContainsString('family_size', $details);

        // The head cannot be removed from the child's page, nor from the family page; the child not from their own page.
        $panel->call('remove', $book['headRow'])->assertHasErrors('member');
        $panel->call('remove', $book['sonRow'])->assertHasErrors('member');
        $this->panel($book)->call('remove', $book['headRow'])->assertHasErrors('member');
        $this->assertNull(DB::table('family_members')->where('id', $book['headRow'])->value('deleted_at'));
        $this->panel($book)->call('remove', $book['sonRow'])->assertHasNoErrors();
        $this->assertNotNull(DB::table('family_members')->where('id', $book['sonRow'])->value('deleted_at'));
        $this->assertSame([4, 3], $this->counts($book['head']));
        // The other household on the book is untouched.
        $this->assertSame([1, 1], $this->counts($book['other']));
    }

    public function test_beneficiary_is_not_offered_or_accepted_for_a_member(): void
    {
        $book = $this->sharedBook();
        $this->damascusOnly();
        $beneficiary = $this->id('relations', 'مستفيد');
        $panel = $this->panel($book)->call('openAdd');
        $this->assertNotContains($beneficiary, $panel->viewData('options')['relation_id']->pluck('id')->all());
        $panel->set('member.first_name', 'ممنوعو')->set('member.last_name', 'التجريبي')->set('member.relation_id', (string) $beneficiary)->call('add')->assertHasErrors('member.relation_id');
        $this->assertDatabaseMissing('people', ['first_name' => 'ممنوعو']);
        $this->panel($book)->call('startEdit', $book['sonRow'])->set('edit.relation_id', (string) $beneficiary)->call('saveEdit')->assertHasErrors('edit.relation_id');
        // The head keeps the relation whatever is sent.
        $this->panel($book)->call('startEdit', $book['headRow'])->set('edit.relation_id', (string) $this->id('relations', 'أب'))->set('edit.has_work', '1')->set('edit.work_name', 'عمل المعيل')->call('saveEdit')->assertHasNoErrors();
        $this->assertDatabaseHas('family_members', ['id' => $book['headRow'], 'relation_id' => $beneficiary, 'work_name' => 'عمل المعيل']);
    }

    public function test_editing_a_member_changes_the_person_and_the_residence_state_recounts_that_household(): void
    {
        $book = $this->sharedBook();
        $this->damascusOnly();
        $away = $this->id('person_states', 'مسافر/ة');
        $panel = $this->panel($book)->call('startEdit', $book['sonRow'])->assertSet('edit.first_name', 'نجمو')->assertSet('edit.has_work', '1')
            ->set('edit.person_state_id', (string) $away)->set('edit.birth_date', '2005')->call('saveEdit')->assertHasNoErrors();
        $this->assertDatabaseHas('people', ['id' => $book['son'], 'person_state_id' => $away, 'national_number' => '04512345678']);
        $this->assertSame([4, 2], $this->counts($book['head']));
        $this->assertSame([1, 1], $this->counts($book['other']));
        // A national number someone else has needs the "not the same person" confirmation; its value is never in the audit.
        $taken = (string) DB::table('people')->where('id', $book['wife'])->value('national_number');
        $panel->call('startEdit', $book['sonRow'])->set('edit.national_number', $taken)->call('saveEdit')->assertHasErrors('confirm')
            ->set('confirmed', true)->call('saveEdit')->assertHasNoErrors();
        $this->assertDatabaseHas('people', ['id' => $book['son'], 'national_number' => $taken]);
        $this->assertSame(0, DB::table('account_audit_events')->where('details', 'like', '%'.$taken.'%')->count());
    }

    public function test_adding_a_registered_person_makes_them_resident_when_they_have_no_state(): void
    {
        $book = $this->sharedBook();
        $this->damascusOnly();
        $loose = (int) DB::table('people')->insertGetId(['city_id' => $this->city('DAM'), 'first_name' => 'طليقو', 'last_name' => 'المسجل', 'created_at' => now(), 'updated_at' => now()]);
        $this->panel($book)->call('openAdd')->set('mode', 'existing')->set('pickSearch', 'طليقو')->assertSee('طليقو المسجل')->call('pick', $loose)
            ->set('member.relation_id', (string) $this->id('relations', 'أخ'))->call('add')->assertHasNoErrors();
        $this->assertDatabaseHas('people', ['id' => $loose, 'person_state_id' => $this->id('person_states', 'مقيم/ة')]);
        $this->assertDatabaseHas('family_members', ['person_id' => $loose, 'provider_person_id' => $book['head']]);
        $this->panel($book)->call('openAdd')->set('mode', 'existing')->call('pick', $loose)->call('add')->assertHasErrors('member.person_id');
    }

    public function test_registering_a_beneficiary_records_resident_and_the_counts_of_the_new_household(): void
    {
        $this->damascusOnly();
        Livewire::test(PersonForm::class)->set('person.first_name', 'مستجدو')->set('person.last_name', 'الجديد')->set('person.sex', 'male')->call('save')->assertHasNoErrors();
        $id = (int) DB::table('people')->where('first_name', 'مستجدو')->value('id');
        $this->assertSame($this->id('person_states', 'مقيم/ة'), (int) DB::table('people')->where('id', $id)->value('person_state_id'));
        $this->assertSame([1, 1], $this->counts($id));
        // Changing the state on the person form recounts that household.
        Livewire::test(PersonForm::class, ['personId' => $id])->set('person.person_state_id', (string) $this->id('person_states', 'مسافر/ة'))->call('save')->assertHasNoErrors();
        $this->assertSame([1, 0], $this->counts($id));
    }

    public function test_a_new_beneficiary_saved_with_family_rows_in_one_transaction_is_counted_after_them(): void
    {
        $account = $this->damascusOnly();
        $state = $this->id('person_states', 'مقيم/ة');
        $head = DB::transaction(function () use ($account, $state) {
            // As the application form does: the beneficiary first, then the family table, then one commit.
            $head = app(PeopleService::class)->create($account, $this->city('DAM'), ['first_name' => 'مقدمو', 'last_name' => 'الطلب'], 'DAM-77004', false);
            $family = (int) DB::table('family_members')->where('person_id', $head)->value('family_id');
            $this->member($family, $head, 'ابن/ة', ['person_state_id' => $state]);
            $this->member($family, $head, 'ابن/ة', ['person_state_id' => null]);

            return $head;
        });
        $this->assertSame([3, 2], $this->counts($head));
    }

    public function test_a_person_of_another_city_cannot_be_picked_into_a_family(): void
    {
        $book = $this->sharedBook();
        $aleppo = $this->family('ALP-77002', 'ALP');
        [$stranger] = $this->member($aleppo, null, 'مستفيد');
        $this->damascusOnly();
        $this->panel($book)->call('openAdd')->call('pick', $stranger)->assertNotFound();
        $this->panel($book)->set('adding', true)->set('mode', 'existing')->set('member.person_id', (string) $stranger)->call('add')->assertNotFound();
        $this->assertSame(1, DB::table('family_members')->where('person_id', $stranger)->count());
    }

    public function test_a_person_in_two_households_shows_both_on_their_page_with_their_role(): void
    {
        $book = $this->sharedBook();
        $this->damascusOnly();
        // The son becomes a beneficiary of his own household on another book.
        $own = $this->family('DAM-77002');
        app(PeopleService::class)->joinFamily($book['son'], $this->city('DAM'), 'DAM-77002');
        $homes = app(FamilyService::class)->memberships(auth()->user(), $book['son']);
        $this->assertSame([[$own, $book['son']], [$book['family'], $book['head']]], $homes->map(fn ($m) => [$m->family_id, $m->head_id])->all());
        $this->get('/ar/people/'.$book['son'])->assertOk()->assertSee(__('families.role_head'))
            ->assertSee(__('families.role_member', ['relation' => 'ابن/ة', 'name' => 'مرهفو جدو التجريبي']))
            ->assertSee(route('families.show', ['locale' => 'ar', 'family' => $book['family']]).'#h-'.$book['head'], false);
        // The individuals list counts the person's own household, not everyone on a shared book.
        $list = Livewire::test(PeopleIndex::class)->set('search', 'سندبادو')->call('setColumns', ['name', 'family_number', 'family_size', 'family_role']);
        $row = $list->viewData('people')->items()[0];
        $this->assertSame(1, (int) $row->col_family_size);
        $this->assertSame(__('families.head'), (new PeopleDataset)->field('family_role')->cell($row)['text']);
    }

    public function test_other_cities_are_invisible_and_a_reader_cannot_change_a_family(): void
    {
        $book = $this->sharedBook();
        $aleppo = $this->family('ALP-77001', 'ALP');
        [$alpHead] = $this->member($aleppo, null, 'مستفيد', ['first_name' => 'حلبيو']);
        $this->damascusOnly();
        $this->get('/ar/families/'.$aleppo)->assertNotFound();
        $this->assertSame([], Livewire::test(FamiliesIndex::class)->set('search', 'حلبيو')->viewData('households')->items());
        $this->assertSame([], Livewire::test(FamiliesIndex::class)->call('toggleCity', $this->city('ALP'))->set('search', 'ALP-77001')->viewData('households')->items());
        Livewire::test(FamilyPanel::class, ['familyId' => $aleppo, 'headId' => $alpHead, 'compact' => false])->assertNotFound();

        $this->as('role.guest');
        $this->get('/ar/families/'.$book['family'])->assertOk()->assertDontSee(__('people.add_member'));
        $this->panel($book)->set('adding', true)->set('member.first_name', 'ضيفو')->set('member.last_name', 'ضيف')->call('add')->assertForbidden();
        $this->panel($book)->call('remove', $book['sonRow'])->assertForbidden();
        $this->panel($book)->call('recount')->assertForbidden();
        $this->assertDatabaseMissing('people', ['first_name' => 'ضيفو']);
        $this->assertSame([4, 4], $this->counts($book['head']));
    }

    public function test_rows_without_a_recorded_provider_are_not_given_a_guessed_head(): void
    {
        $family = $this->family('DAM-77003');
        [, $row] = $this->member($family, null, 'أخ', ['first_name' => 'تائهو'], ['provider_person_id' => null]);
        $this->damascusOnly();
        $home = app(FamilyService::class)->household($family, 0);
        $this->assertSame('no_head', $home['flags'][0]['key']);
        $this->get('/ar/families/'.$family)->assertOk()->assertSee(__('families.no_head_title'));
        $list = Livewire::test(FamiliesIndex::class)->set('search', 'DAM-77003')->assertSee(__('families.no_head'));
        $this->assertSame(0, $list->viewData('households')->items()[0]->head_id);
        Livewire::test(FamilyPanel::class, ['familyId' => $family, 'headId' => 0, 'compact' => false])->set('adding', true)
            ->set('member.first_name', 'آخرو')->set('member.last_name', 'تجريبي')->call('add')->assertHasErrors('member');
        $this->assertNull(DB::table('family_members')->where('id', $row)->value('provider_person_id'));
    }

    public function test_family_work_in_the_application_keeps_the_job_text(): void
    {
        $this->assertSame(['person_id', 'occupation_id', 'description', 'work_type_id', 'salary'], array_keys(ApplicationFields::grid('family_work')['fields']));
    }

    public function test_both_languages_have_the_same_family_texts(): void
    {
        $keys = fn (string $locale) => array_keys(Arr::dot(require lang_path($locale.'/families.php')));
        $this->assertSame($keys('ar'), $keys('en'));
        $this->assertSame(Households::RESIDENT, DB::table('person_states')->where('id', Households::residentStateId())->value('name_ar'));
    }
}
