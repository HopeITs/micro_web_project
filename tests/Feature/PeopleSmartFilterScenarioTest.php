<?php

namespace Tests\Feature;

use App\Livewire\PeopleIndex;
use App\Models\SavedView;
use App\Models\WebAccount;
use App\Support\CityContext;
use App\Support\Datasets\PeopleDataset;
use App\Support\Datasets\QuerySpec;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Smart filter bar, multi-value search and saved views on the people list (smart-search plan 2026-11, phase 1 item 5). */
class PeopleSmartFilterScenarioTest extends TestCase
{
    use RefreshDatabase;

    private WebAccount $dataEntry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('ar');
        $this->dataEntry = WebAccount::where('username', 'role.data-entry')->firstOrFail();
        $this->actingAs($this->dataEntry);
        app(CityContext::class)->restrictTo(DB::table('cities')->pluck('id'));
    }

    private function cityId(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function people()
    {
        return DB::table('people')->whereNull('deleted_at');
    }

    public function test_ticking_several_values_lists_people_matching_any_of_them(): void
    {
        $marital = DB::table('marital_statuses')->orderBy('id')->pluck('id')->take(2)->map(fn ($id) => (string) $id)->all();
        $expected = $this->people()->whereIn('marital_status_id', $marital)->count();
        $this->assertGreaterThan(0, $expected);

        $list = Livewire::test(PeopleIndex::class)
            ->call('toggleFilterValue', 'marital_status', $marital[0])->call('toggleFilterValue', 'marital_status', $marital[1]);
        $this->assertSame($expected, $list->viewData('people')->total());

        $list->call('setFilterOp', 'marital_status', 'not_in');
        $this->assertSame($this->people()->count() - $expected, $list->viewData('people')->total());
        $list->call('removeFilter', 'marital_status');
        $this->assertSame($this->people()->count(), $list->viewData('people')->total());

        // Sex and the former with/without projects selector are filters now.
        $male = $this->people()->where('sex', 'male')->count();
        $list->call('toggleFilterValue', 'sex', 'male');
        $this->assertSame($male, $list->viewData('people')->total());
        $list->call('removeFilter', 'sex');
        $withProjects = $this->people()->whereIn('id', DB::table('project_applicants')->whereNull('deleted_at')->select('person_id'))->count();
        $list->call('toggleFilterValue', 'has_projects', 'with');
        $this->assertSame($withProjects, $list->viewData('people')->total());
        $list->call('removeFilter', 'has_projects')->call('toggleFilterValue', 'has_projects', 'without');
        $this->assertSame($this->people()->count() - $withProjects, $list->viewData('people')->total());
    }

    public function test_age_and_registration_ranges_and_text_columns_are_not_filters(): void
    {
        $list = Livewire::test(PeopleIndex::class);
        $all = $list->viewData('people')->total();
        $list->call('setFilterRange', 'age', '0', '1000');
        $this->assertSame($this->people()->whereNotNull('birth_date')->count(), $list->viewData('people')->total());
        $list->call('setFilterRange', 'age', '1000', '');
        $this->assertSame(0, $list->viewData('people')->total());
        $list->call('removeFilter', 'age')->call('setFilterPreset', 'registered', 'this_year');
        $this->assertLessThanOrEqual($all, $list->viewData('people')->total());

        // Names, national numbers, mobiles and family book numbers are reachable through the search box only.
        $keys = array_keys((new PeopleDataset)->filterable());
        $this->assertSame([], array_intersect($keys, ['name', 'national_number', 'mobile', 'family_number']));
        $list->call('setFilterText', 'name', 'محمد')->assertSet('textFilters', []);
    }

    public function test_commas_and_lines_in_the_search_box_find_every_term(): void
    {
        $two = $this->people()->whereNotNull('national_number')->where('national_number', '<>', '')->orderBy('id')->limit(2)->get();
        $this->assertCount(2, $two);
        [$a, $b] = [$two[0], $two[1]];

        $one = Livewire::test(PeopleIndex::class)->set('search', $a->national_number);
        $this->assertGreaterThanOrEqual(1, $one->viewData('people')->total());
        $both = Livewire::test(PeopleIndex::class)->set('search', $a->national_number.",\n".$b->national_number);
        $ids = collect($both->viewData('people')->items())->pluck('id');
        $this->assertTrue($ids->contains($a->id) && $ids->contains($b->id));

        // Names with Arabic letter folding and a number, OR'ed.
        $mixed = Livewire::test(PeopleIndex::class)->set('search', $a->national_number.'، '.str_replace('أ', 'ا', $b->first_name).' '.$b->last_name);
        $ids = collect($mixed->viewData('people')->items())->pluck('id');
        $this->assertTrue($ids->contains($a->id) && $ids->contains($b->id));

        // Nothing typed in the search box reaches the address or the stored state.
        $this->assertStringNotContainsString($a->national_number, json_encode($both->get('filters')));
    }

    public function test_a_damascus_user_never_reaches_another_city(): void
    {
        $dam = $this->cityId('DAM');
        $alp = $this->cityId('ALP');
        app(CityContext::class)->restrictTo([$dam]);
        $damCount = $this->people()->where('city_id', $dam)->count();

        $dataset = new PeopleDataset;
        $this->assertEquals([$dam], array_keys($dataset->field('city')->options()));
        $this->assertNotSame([], QuerySpec::fromArray(['filters' => [['field' => 'city', 'op' => 'in', 'values' => [(string) $alp]]]])->errors($dataset));

        $list = Livewire::withQueryParams(['f' => ['city' => ['op' => 'in', 'values' => [(string) $alp]]]])->test(PeopleIndex::class)->assertSet('filters', []);
        $this->assertSame($damCount, $list->viewData('people')->total());
        $list->call('toggleCity', $alp);
        $this->assertSame($damCount, $list->viewData('people')->total());

        // Search for someone of another city finds nobody, filters cannot widen the list, facet counts stay in Damascus.
        $other = $this->people()->where('city_id', $alp)->whereNotNull('national_number')->first();
        Livewire::test(PeopleIndex::class)->set('search', $other->national_number)->assertDontSee($other->first_name.' '.$other->father_name);
        $facet = Livewire::test(PeopleIndex::class)->instance();
        $this->assertSame($damCount, array_sum($facet->facetCounts('sex')) + $this->people()->where('city_id', $dam)->whereNull('sex')->count());
        $this->assertSame([], array_diff_key($facet->facetCounts('city'), [(string) $dam => 1]));
    }

    public function test_facets_and_group_by_queries_run_on_the_dataset(): void
    {
        $list = Livewire::test(PeopleIndex::class)->call('toggleFilterValue', 'sex', 'female');
        $instance = $list->instance();
        foreach (['sex', 'marital_status', 'education', 'has_projects', 'city'] as $field) {
            $this->assertIsArray($instance->facetCounts($field));
        }
        // A facet never counts its own filter: ticking "female" still counts males under sex.
        $counts = $instance->facetCounts('sex');
        $this->assertSame($this->people()->where('sex', 'male')->count(), $counts['male'] ?? 0);
        // Sensitive fields have no facets.
        $this->assertSame([], $instance->facetCounts('national_number'));
    }

    public function test_saved_view_for_people_stores_ids_only_and_applies_again(): void
    {
        $dam = $this->cityId('DAM');
        $person = $this->people()->whereNotNull('national_number')->first();
        $list = Livewire::test(PeopleIndex::class)->set('search', $person->national_number)
            ->call('toggleFilterValue', 'sex', 'female')->call('toggleCity', $dam)->call('saveView', 'نساء دمشق');
        $expected = $list->viewData('people')->total();

        $view = SavedView::sole();
        $this->assertSame(['people', 'view', 'نساء دمشق'], [$view->dataset, $view->kind, $view->name]);
        $this->assertEqualsCanonicalizing([['field' => 'sex', 'op' => 'in', 'values' => ['female']], ['field' => 'city', 'op' => 'in', 'values' => [(string) $dam]]], $view->spec['filters']);
        $this->assertStringNotContainsString($person->national_number, DB::table('saved_views')->value('spec'));

        // The projects list does not see it; a fresh people list applies it (search box cleared).
        $fresh = Livewire::test(PeopleIndex::class)->set('search', 'x')->call('applyView', $view->id)
            ->assertSet('search', '')->assertSet('cities', [$dam])->assertSet('filters', ['sex' => ['op' => 'in', 'values' => ['female']]]);
        $this->assertSame($this->people()->where('city_id', $dam)->where('sex', 'female')->count(), $fresh->viewData('people')->total());
        $this->assertLessThanOrEqual($fresh->viewData('people')->total(), $expected);
        $this->assertSame(0, SavedView::where('dataset', 'projects')->count());

        // Sharing follows the people.view capability.
        $this->assertTrue($fresh->instance()->canShareViews());
        $fresh->call('toggleShareView', $view->id);
        $this->assertTrue($view->fresh()->is_shared);
        $fresh->call('deleteView', $view->id);
        $this->assertSame(0, SavedView::count());
    }
}
