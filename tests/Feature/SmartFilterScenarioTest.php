<?php

namespace Tests\Feature;

use App\Livewire\ProjectsIndex;
use App\Models\WebAccount;
use App\Support\CityContext;
use App\Support\Datasets\ProjectsDataset;
use App\Support\Datasets\QuerySpec;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Smart filter bar on the projects list (smart-search plan 2026-11, phase 1), as data entry and as a one-city user. */
class SmartFilterScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('ar');
        $this->actingAs(WebAccount::where('username', 'role.data-entry')->firstOrFail());
    }

    private function cityId(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function allCities(): void
    {
        app(CityContext::class)->restrictTo(DB::table('cities')->pluck('id'));
    }

    /** @return Collection<int, object> projects with their current state id */
    private function projects()
    {
        return DB::table('projects')->whereNull('projects.deleted_at')
            ->leftJoin('project_state_history as h', fn ($j) => $j->on('h.project_id', '=', 'projects.id')->where('h.is_current', true)->whereNull('h.deleted_at'))
            ->get(['projects.id', 'projects.city_id', 'projects.application_stage', 'h.project_state_id']);
    }

    private function stateId(string $en): string
    {
        return (string) DB::table('project_states')->where('name_en', $en)->value('id');
    }

    public function test_ticking_several_states_lists_every_project_in_any_of_them(): void
    {
        $this->allCities();
        [$financed, $rejected] = [$this->stateId('Financed'), $this->stateId('Rejected')];
        $expected = $this->projects()->whereIn('project_state_id', [(int) $financed, (int) $rejected])->count();
        $this->assertGreaterThan(0, $expected);

        $list = Livewire::test(ProjectsIndex::class)
            ->call('openFilter', 'state')
            ->call('toggleFilterValue', 'state', $financed)
            ->call('toggleFilterValue', 'state', $rejected)
            ->assertSet('filters', ['state' => ['op' => 'in', 'values' => [$financed, $rejected]]])
            ->assertSee(__('hc.state').':');
        $this->assertSame($expected, $list->viewData('projects')->total());

        // "Is not" keeps the ticked values and inverts them; removing the chip lists everything again.
        $list->call('setFilterOp', 'state', 'not_in');
        $this->assertSame($this->projects()->count() - $expected, $list->viewData('projects')->total());
        $list->call('removeFilter', 'state')->assertSet('filters', []);
        $this->assertSame($this->projects()->count(), $list->viewData('projects')->total());

        // Values that are not options of the field are ignored.
        $list->call('toggleFilterValue', 'state', '999999')->assertSet('filters', []);
    }

    public function test_the_stage_and_amount_and_relative_date_filters(): void
    {
        $this->allCities();
        $list = Livewire::test(ProjectsIndex::class)->call('toggleFilterValue', 'stage', 'preliminary');
        $this->assertSame($this->projects()->where('application_stage', 'preliminary')->count(), $list->viewData('projects')->total());

        $list->call('removeFilter', 'stage')->call('setFilterRange', 'requested_amount', '15000000', '');
        $this->assertSame(DB::table('projects')->where('requested_amount', '>=', 15000000)->count(), $list->viewData('projects')->total());

        $list->call('setFilterRange', 'requested_amount', '', '')->assertSet('filters', []);

        $this->travelTo('2025-03-15');
        [$from, $to] = QuerySpec::presetRange('last_90_days');
        $list->call('setFilterPreset', 'request_date', 'last_90_days')->assertSet('filters', ['request_date' => ['op' => 'between', 'values' => ['last_90_days']]]);
        $this->assertSame(DB::table('projects')->whereBetween('request_date', [$from, $to.' 23:59:59'])->count(), $list->viewData('projects')->total());
    }

    public function test_commas_and_lines_in_the_search_box_find_every_term(): void
    {
        $this->allCities();
        $list = Livewire::test(ProjectsIndex::class)->set('search', "DAM-1001, HMS-1002\nALP 1003\r\nWAD-1004");
        $this->assertSame(4, $list->viewData('projects')->total());
        $list->assertSee('DAM-1001')->assertSee('HMS-1002')->assertSee('ALP-1003')->assertSee('WAD-1004')->assertDontSee('DAM-1002');

        // A pasted column of 20 numbers without city code finds them in every permitted city.
        $column = implode("\n", range(1001, 1020));
        $this->assertSame(DB::table('projects')->whereBetween('project_number', [1001, 1020])->count(), Livewire::test(ProjectsIndex::class)->set('search', $column)->viewData('projects')->total());

        // National numbers of two beneficiaries, pasted one per line.
        $people = DB::table('project_applicants as pa')->join('people as p', 'p.id', '=', 'pa.person_id')->whereNotNull('p.national_number')
            ->orderBy('pa.project_id')->limit(2)->get(['pa.project_id', 'p.national_number']);
        $found = Livewire::test(ProjectsIndex::class)->set('search', $people[0]->national_number."\n".$people[1]->national_number)->viewData('projects');
        $ids = array_map(fn ($p) => (int) $p->id, $found->items());
        $this->assertContains((int) $people[0]->project_id, $ids);
        $this->assertContains((int) $people[1]->project_id, $ids);

        // Spaces inside one term still mean AND: a first name with a wrong last name finds nothing.
        $this->assertSame(0, Livewire::test(ProjectsIndex::class)->set('search', 'محمد zzzz-no-such')->viewData('projects')->total());
    }

    public function test_a_damascus_user_never_reaches_another_city_through_filter_values(): void
    {
        app(CityContext::class)->restrictTo([$this->cityId('DAM')]);
        $aleppo = (string) $this->cityId('ALP');
        $damascusCount = $this->projects()->where('city_id', $this->cityId('DAM'))->count();

        // The city field offers only Damascus, so an Aleppo id is not a valid value anywhere.
        $dataset = new ProjectsDataset;
        $this->assertEquals([$this->cityId('DAM')], array_keys($dataset->field('city')->options()));
        $this->assertNotSame([], QuerySpec::fromArray(['filters' => [['field' => 'city', 'op' => 'in', 'values' => [$aleppo]]]])->errors($dataset));

        // An Aleppo id typed into the address is dropped; the list stays Damascus only.
        $list = Livewire::withQueryParams(['f' => ['city' => ['op' => 'in', 'values' => [$aleppo]]]])->test(ProjectsIndex::class)->assertSet('filters', []);
        $this->assertSame($damascusCount, $list->viewData('projects')->total());
        $list->call('toggleCity', (int) $aleppo)->assertDontSee('ALP-');
        $this->assertSame($damascusCount, $list->viewData('projects')->total());

        // "Is not Damascus" can only empty the list, never widen it; facet counts stay inside Damascus.
        $spec = QuerySpec::fromArray(['filters' => [['field' => 'city', 'op' => 'not_in', 'values' => [(string) $this->cityId('DAM')]]]]);
        $this->assertSame(0, $spec->apply($dataset->query(), $dataset)->count());
        $counts = Livewire::test(ProjectsIndex::class)->call('openFilter', 'state')->instance()->facetCounts('state');
        $this->assertSame($damascusCount, array_sum($counts) + $this->projects()->where('city_id', $this->cityId('DAM'))->whereNull('project_state_id')->count());
        $this->assertSame([], array_diff_key(Livewire::test(ProjectsIndex::class)->instance()->facetCounts('city'), [(string) $this->cityId('DAM') => 1]));
    }

    public function test_facet_counts_follow_the_other_filters_but_not_their_own(): void
    {
        $this->allCities();
        $financed = $this->stateId('Financed');
        $list = Livewire::test(ProjectsIndex::class)
            ->call('toggleFilterValue', 'stage', 'full')
            ->call('toggleFilterValue', 'state', $financed);

        // Stage counts ignore the stage filter but respect the state filter.
        $stage = $list->instance()->facetCounts('stage');
        $this->assertSame($this->projects()->where('project_state_id', (int) $financed)->countBy('application_stage')->sortKeys()->all(), collect($stage)->sortKeys()->all());

        // State counts respect the stage filter and the search, and match the rows shown when ticked alone.
        $list->set('search', '1001');
        $state = $list->instance()->facetCounts('state');
        $expected = DB::table('projects')->where('application_stage', 'full')->where('project_number', 1001)
            ->join('project_state_history as h', fn ($j) => $j->on('h.project_id', '=', 'projects.id')->where('h.is_current', true))
            ->groupBy('h.project_state_id')->selectRaw('h.project_state_id as s, COUNT(*) as n')->pluck('n', 's')->mapWithKeys(fn ($n, $s) => [(string) $s => (int) $n])->sortKeys()->all();
        $this->assertSame($expected, collect($state)->sortKeys()->all());
        $this->assertSame($state[$financed] ?? 0, $list->viewData('projects')->total());

        // The open editor shows each value with its count.
        $list->call('openFilter', 'state')->assertSeeHtml('<span class="muted num">'.number_format($state[$financed] ?? 0).'</span>');

        // Names are never counted.
        $this->assertSame([], $list->instance()->facetCounts('beneficiary'));
    }

    public function test_names_and_national_numbers_stay_out_of_the_address(): void
    {
        $this->allCities();
        $person = DB::table('people')->whereNotNull('national_number')->first();

        $list = Livewire::test(ProjectsIndex::class)->call('setFilterText', 'beneficiary', $person->first_name)
            ->assertSet('filters', [])->assertSet('textFilters', ['beneficiary' => ['op' => 'contains', 'values' => [$person->first_name]]])
            ->assertSee($person->first_name);
        $this->assertGreaterThan(0, $list->viewData('projects')->total());

        // Text filters and unknown fields sent through the URL are dropped.
        Livewire::withQueryParams(['f' => [
            'national_number' => ['op' => 'contains', 'values' => [$person->national_number]],
            'password' => ['op' => 'in', 'values' => ['x']],
            'stage' => ['op' => 'in', 'values' => ['full']],
        ]])->test(ProjectsIndex::class)->assertSet('filters', ['stage' => ['op' => 'in', 'values' => ['full']]]);

        // Clearing removes the bar's filters with the rest.
        $list->call('clearFilters')->assertSet('textFilters', [])->assertSet('filters', []);
        $this->get('/ar/projects?f[stage][op]=in&f[stage][values][0]=full')->assertOk()->assertSee(__('hc.filter_add'));
    }
}
