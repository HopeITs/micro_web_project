<?php

namespace Tests\Feature;

use App\Livewire\ProjectsIndex;
use App\Models\SavedView;
use App\Models\WebAccount;
use App\Support\CityContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Saved views ("عروضي") on the projects list (smart-search plan 2026-11, phase 1 item 2; schema v1.5 `saved_views`). */
class SavedViewScenarioTest extends TestCase
{
    use RefreshDatabase;

    private WebAccount $dataEntry;

    private WebAccount $financial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('ar');
        $this->dataEntry = WebAccount::where('username', 'role.data-entry')->firstOrFail();
        $this->financial = WebAccount::where('username', 'role.financial')->firstOrFail();
        $this->actingAs($this->dataEntry);
        app(CityContext::class)->restrictTo(DB::table('cities')->pluck('id'));
    }

    private function cityId(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function stateId(string $en): string
    {
        return (string) DB::table('project_states')->where('name_en', $en)->value('id');
    }

    private function projectsIn(array $cityIds, ?string $stage = null): int
    {
        return DB::table('projects')->whereNull('deleted_at')->whereIn('city_id', $cityIds)
            ->when($stage, fn ($q) => $q->where('application_stage', $stage))->count();
    }

    public function test_saving_applying_and_deleting_a_view(): void
    {
        $financed = $this->stateId('Financed');
        $list = Livewire::test(ProjectsIndex::class)
            ->call('toggleFilterValue', 'stage', 'full')
            ->call('toggleFilterValue', 'state', $financed)
            ->call('saveView', '  ممول كامل  ')
            ->assertSet('viewError', null)
            ->assertSee('ممول كامل');
        $expected = $list->viewData('projects')->total();

        $view = SavedView::sole();
        $this->assertSame($this->dataEntry->id, $view->account_id);
        $this->assertSame(['projects', 'view', 'ممول كامل', false, false], [$view->dataset, $view->kind, $view->name, $view->is_shared, $view->is_default]);
        $this->assertEqualsCanonicalizing([['field' => 'stage', 'op' => 'in', 'values' => ['full']], ['field' => 'state', 'op' => 'in', 'values' => [$financed]]], $view->spec['filters']);
        $this->assertSame([], $view->spec['sort']);
        $this->assertTrue($view->account->is($this->dataEntry));
        $this->assertDatabaseHas('account_audit_events', ['actor_account_id' => $this->dataEntry->id, 'event_type' => 'saved_views.created', 'decision_locator' => 'saved_view:'.$view->id]);

        // The same name twice is refused; an empty name too.
        $list->call('saveView', 'ممول كامل')->assertSet('viewError', __('hc.view_name_taken'));
        $list->call('saveView', '   ')->assertSet('viewError', __('hc.view_name_invalid'));
        $this->assertSame(1, SavedView::count());

        // A fresh list: applying the view restores the filters and the same results.
        $fresh = Livewire::test(ProjectsIndex::class)->call('applyView', $view->id)
            ->assertSet('activeViewId', $view->id)->assertSet('viewNotice', null);
        $this->assertSame(['stage' => ['op' => 'in', 'values' => ['full']], 'state' => ['op' => 'in', 'values' => [$financed]]], $fresh->get('filters'));
        $this->assertSame($expected, $fresh->viewData('projects')->total());

        // Rename and overwrite with new filters.
        $fresh->call('renameView', $view->id, 'كامل فقط')->call('removeFilter', 'state')->call('overwriteView', $view->id);
        $view->refresh();
        $this->assertSame('كامل فقط', $view->name);
        $this->assertSame([['field' => 'stage', 'op' => 'in', 'values' => ['full']]], $view->spec['filters']);

        $fresh->call('deleteView', $view->id)->assertSet('activeViewId', null);
        $this->assertSame(0, SavedView::count());
        $this->assertDatabaseHas('account_audit_events', ['actor_account_id' => $this->dataEntry->id, 'event_type' => 'saved_views.deleted', 'decision_locator' => 'saved_view:'.$view->id]);
    }

    public function test_the_search_box_and_name_filters_are_never_stored(): void
    {
        $person = DB::table('people')->whereNotNull('national_number')->first();
        Livewire::test(ProjectsIndex::class)
            ->set('search', $person->national_number)
            ->call('setFilterText', 'beneficiary', $person->first_name)
            ->call('toggleFilterValue', 'stage', 'preliminary')
            ->call('saveView', 'أولي');

        $raw = DB::table('saved_views')->value('spec');
        $this->assertStringNotContainsString($person->national_number, $raw);
        $this->assertStringNotContainsString($person->first_name, $raw);
        $this->assertSame([['field' => 'stage', 'op' => 'in', 'values' => ['preliminary']]], SavedView::sole()->spec['filters']);

        // Only a search or a name filter: there is nothing to save.
        Livewire::test(ProjectsIndex::class)->set('search', 'محمد')->call('saveView', 'بحث')->assertSet('viewError', __('hc.view_nothing_to_save'));
        $this->assertSame(1, SavedView::count());

        // Applying a view clears the search box.
        Livewire::test(ProjectsIndex::class)->set('search', 'محمد')->call('applyView', SavedView::sole()->id)->assertSet('search', '')->assertSet('textFilters', []);
    }

    public function test_other_users_private_views_are_invisible_and_untouchable(): void
    {
        $private = SavedView::create(['account_id' => $this->financial->id, 'dataset' => 'projects', 'kind' => 'view', 'name' => 'خاص بالمالية',
            'spec' => ['filters' => [['field' => 'stage', 'op' => 'in', 'values' => ['full']]], 'sort' => []], 'is_shared' => false, 'is_default' => false]);
        $shared = SavedView::create(['account_id' => $this->financial->id, 'dataset' => 'projects', 'kind' => 'view', 'name' => 'مشترك من المالية',
            'spec' => ['filters' => [['field' => 'stage', 'op' => 'in', 'values' => ['preliminary']]], 'sort' => []], 'is_shared' => true, 'is_default' => false]);

        $list = Livewire::test(ProjectsIndex::class)->assertDontSee('خاص بالمالية')->assertSee('مشترك من المالية');
        $names = array_column($list->viewData('filterBar')['views']['list'], 'name');
        $this->assertSame(['مشترك من المالية'], $names);

        $list->call('applyView', $private->id)->assertStatus(404);
        Livewire::test(ProjectsIndex::class)->call('applyView', $shared->id)->assertSet('filters', ['stage' => ['op' => 'in', 'values' => ['preliminary']]]);

        // A shared view of someone else can be applied but not changed, shared or deleted.
        foreach (['deleteView', 'toggleShareView', 'toggleDefaultView', 'overwriteView'] as $action) {
            Livewire::test(ProjectsIndex::class)->call('toggleFilterValue', 'stage', 'full')->call($action, $shared->id)->assertForbidden();
        }
        Livewire::test(ProjectsIndex::class)->call('renameView', $shared->id, 'x')->assertForbidden();
        $this->assertSame(2, SavedView::count());
        $this->assertSame('مشترك من المالية', $shared->fresh()->name);
    }

    public function test_sharing_needs_the_projects_capability_and_a_shared_view_stays_in_the_viewers_cities(): void
    {
        [$dam, $alp] = [$this->cityId('DAM'), $this->cityId('ALP')];
        $list = Livewire::test(ProjectsIndex::class)->call('toggleCity', $dam)->call('toggleCity', $alp)
            ->call('toggleFilterValue', 'stage', 'full')->call('saveView', 'دمشق وحلب');
        $view = SavedView::sole();
        $this->assertContains(['field' => 'city', 'op' => 'in', 'values' => [(string) $dam, (string) $alp]], $view->spec['filters']);
        $list->call('toggleShareView', $view->id);
        $this->assertTrue($view->fresh()->is_shared);

        // A Damascus-only colleague sees the shared view; applying it lists Damascus projects only and says a filter was left out.
        $this->actingAs($this->financial);
        app(CityContext::class)->restrictTo([$dam]);
        $other = Livewire::test(ProjectsIndex::class)->call('applyView', $view->id)
            ->assertSet('cities', [$dam])->assertSet('viewNotice', __('hc.view_filters_dropped'));
        $this->assertSame($this->projectsIn([$dam], 'full'), $other->viewData('projects')->total());
        $other->assertDontSee('ALP-');

        // Without projects.view anywhere an account cannot share; it can still stop sharing.
        DB::table('account_city_capabilities')->where('account_id', $this->dataEntry->id)->where('capability', 'projects.view')->update(['revoked_at' => now('UTC')]);
        $this->actingAs($this->dataEntry);
        app(CityContext::class)->restrictTo([$dam]);
        $this->assertFalse(Livewire::test(ProjectsIndex::class)->instance()->canShareViews());
        Livewire::test(ProjectsIndex::class)->call('toggleShareView', $view->id);
        $this->assertFalse($view->fresh()->is_shared);
        Livewire::test(ProjectsIndex::class)->call('toggleShareView', $view->id)->assertForbidden();
        $this->assertFalse($view->fresh()->is_shared);
    }

    public function test_the_default_view_opens_the_list_when_the_address_has_no_filters(): void
    {
        $list = Livewire::test(ProjectsIndex::class)->call('toggleFilterValue', 'stage', 'full')->call('saveView', 'كامل');
        $list->call('removeFilter', 'stage')->call('toggleFilterValue', 'stage', 'preliminary')->call('saveView', 'أولي');
        [$full, $prelim] = [SavedView::where('name', 'كامل')->sole(), SavedView::where('name', 'أولي')->sole()];

        $list->call('toggleDefaultView', $full->id)->call('toggleDefaultView', $prelim->id);
        $this->assertSame([false, true], [$full->fresh()->is_default, $prelim->fresh()->is_default], 'one default per account and list');

        $opened = Livewire::test(ProjectsIndex::class)->assertSet('activeViewId', $prelim->id)->assertSet('filters', ['stage' => ['op' => 'in', 'values' => ['preliminary']]]);
        $this->assertSame($this->projectsIn(DB::table('cities')->pluck('id')->all(), 'preliminary'), $opened->viewData('projects')->total());

        // Another account's default does not apply to me.
        $this->actingAs($this->financial);
        Livewire::test(ProjectsIndex::class)->assertSet('activeViewId', null)->assertSet('filters', []);
        $this->actingAs($this->dataEntry);

        // Filters in the address win over the default view (query params stay set for the rest of this test).
        Livewire::withQueryParams(['f' => ['stage' => ['op' => 'in', 'values' => ['full']]]])->test(ProjectsIndex::class)
            ->assertSet('activeViewId', null)->assertSet('filters', ['stage' => ['op' => 'in', 'values' => ['full']]]);

        // Toggling again removes the default.
        Livewire::test(ProjectsIndex::class)->call('toggleDefaultView', $prelim->id);
        $this->assertFalse($prelim->fresh()->is_default);
    }

    public function test_an_invalid_stored_spec_is_checked_again_and_reported(): void
    {
        $financed = $this->stateId('Financed');
        $view = SavedView::create(['account_id' => $this->dataEntry->id, 'dataset' => 'projects', 'kind' => 'view', 'name' => 'قديم', 'is_shared' => false, 'is_default' => true,
            'spec' => ['filters' => [
                ['field' => 'stage', 'op' => 'in', 'values' => ['full']],
                ['field' => 'no_such_field', 'op' => 'in', 'values' => ['1']],
                ['field' => 'state', 'op' => 'in', 'values' => [$financed, '999999']],
                ['field' => 'requested_amount', 'op' => 'between', 'values' => ['abc', 'x']],
                ['field' => 'beneficiary', 'op' => 'contains', 'values' => ['محمد']],
                'not a filter',
            ], 'sort' => [['field' => 'nope', 'dir' => 'sideways']]]]);

        Livewire::test(ProjectsIndex::class)->assertOk()->assertSet('activeViewId', $view->id)
            ->assertSet('filters', ['stage' => ['op' => 'in', 'values' => ['full']], 'state' => ['op' => 'in', 'values' => [$financed]]])
            ->assertSet('textFilters', [])->assertSet('viewNotice', __('hc.view_filters_dropped'))->assertSee(__('hc.view_filters_dropped'));

        // A spec that is not even a filter list opens an unfiltered list with the notice.
        DB::table('saved_views')->where('id', $view->id)->update(['spec' => json_encode('broken')]);
        $list = Livewire::test(ProjectsIndex::class)->assertOk()->assertSet('filters', [])->assertSet('viewNotice', __('hc.view_filters_dropped'));
        $this->assertSame(DB::table('projects')->whereNull('deleted_at')->count(), $list->viewData('projects')->total());

        $this->get('/ar/projects')->assertOk()->assertSee(__('hc.views_mine'));
    }
}
