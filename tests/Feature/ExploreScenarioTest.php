<?php

namespace Tests\Feature;

use App\Livewire\ExplorePage;
use App\Models\WebAccount;
use App\Services\AccountProvisioner;
use App\Support\Datasets\DatasetRegistry;
use App\Support\Datasets\ExplorePresets;
use App\Support\Datasets\ReportSpec;
use App\Support\Navigation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Task X2 user scenarios, by role: the administrator reads the same numbers in the list, the pivot and the chart and drills from a
 * cell into exactly that many rows; a one-city account never sees another city in any view; a read-only guest and the communication
 * role get no export; an `ask-applied` event from the ask box changes the filters; a visitor who is not signed in is sent to log in.
 */
class ExploreScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('ar');
    }

    private function as(string $username): WebAccount
    {
        $account = WebAccount::where('username', $username)->firstOrFail();
        $this->actingAs($account);

        return $account;
    }

    private function projects()
    {
        return DB::table('projects')->whereNull('projects.deleted_at')
            ->leftJoin('project_state_history as h', fn ($j) => $j->on('h.project_id', '=', 'projects.id')->where('h.is_current', true)->whereNull('h.deleted_at'))
            ->get(['projects.id', 'projects.city_id', 'h.project_state_id']);
    }

    private function oneCityAccount(): array
    {
        $dam = (int) DB::table('cities')->orderBy('id')->value('id');
        $provisioner = app(AccountProvisioner::class);
        $one = $provisioner->createPlain('one.city', 'one.city@hcsyria.org', Hash::make('a-long-test-password'), 'test:explore');
        foreach (['projects.view', 'people.view', 'applications.view'] as $capability) {
            $provisioner->grant($one->fresh(), $dam, $capability, 'test:explore');
        }
        $this->actingAs($one->fresh());

        return [$one->fresh(), $dam];
    }

    public function test_the_page_opens_in_both_languages_and_the_sidebar_lists_it(): void
    {
        $admin = $this->as('role.administrator');
        $this->get('/ar/explore')->assertOk()->assertSeeLivewire(ExplorePage::class)->assertSee(__('explore.title', [], 'ar'));
        $this->get('/en/explore')->assertOk()->assertSee('Ready questions', false);
        $items = array_merge(...array_values(Navigation::for($admin)));
        $keys = array_column($items, 'key');
        $this->assertContains('explore', $keys);
        $this->assertLessThan(array_search('search', $keys, true), array_search('explore', $keys, true));
        // The three old pages stay reachable and point to Explore.
        foreach (['search', 'statistics', 'reports'] as $old) {
            $this->get('/ar/'.$old)->assertOk()->assertSee(route('explore.index', ['locale' => 'ar']));
        }
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_log_in(): void
    {
        $this->get('/ar/explore')->assertRedirect();
    }

    public function test_pivot_counts_equal_direct_counts_and_the_cell_drills_into_exactly_that_many_rows(): void
    {
        $this->as('role.administrator');
        $page = Livewire::test(ExplorePage::class)->set('source', 'projects')->call('setShow', 'pivot')->set('rows', 'state')->set('columns', 'city');
        $pivot = $page->viewData('pivots')[0];

        $expected = [];
        foreach ($this->projects() as $p) {
            $expected[(string) $p->project_state_id][(string) $p->city_id] = ($expected[(string) $p->project_state_id][(string) $p->city_id] ?? 0) + 1;
        }
        $checked = 0;
        foreach ($pivot['rows'] as $row) {
            foreach ($row['cells'] as $city => $value) {
                $this->assertSame($expected[$row['key']][(string) $city] ?? null, $value === null ? null : (int) $value);
            }
            $this->assertSame(array_sum($expected[$row['key']]), (int) $row['total']);
        }
        $this->assertSame($this->projects()->count(), (int) $pivot['grand']);
        $this->assertSame($pivot['grand'], (float) $page->viewData('kpis')['count']);

        // Drill from every filled cell: the list holds exactly the cell's count, and the chart view keeps the same filters.
        foreach ($pivot['rows'] as $row) {
            foreach ($row['cells'] as $city => $value) {
                if ($value === null) {
                    continue;
                }
                $list = Livewire::test(ExplorePage::class)->set('source', 'projects')->call('setShow', 'pivot')->set('rows', 'state')->set('columns', 'city')
                    ->call('drill', (string) $row['key'], (string) $city, null)->assertSet('show', 'list');
                $this->assertSame((int) $value, $list->viewData('rowsPage')->total(), "state {$row['key']} x city {$city}");
                $this->assertSame((int) $value, (int) $list->viewData('kpis')['count']);
                $list->call('setShow', 'chart');
                $this->assertSame((float) $value, (float) $list->viewData('pivots')[0]['grand']);
                $checked++;
            }
        }
        $this->assertGreaterThan(0, $checked);
        // A row total drills into the row's rows, the grand total into everything.
        $row = $pivot['rows'][0];
        $list = Livewire::test(ExplorePage::class)->set('source', 'projects')->call('setShow', 'pivot')->set('rows', 'state')->call('drill', (string) $row['key'], null, null);
        $this->assertSame((int) $row['total'], $list->viewData('rowsPage')->total());
    }

    public function test_the_switch_between_views_keeps_the_filters(): void
    {
        $this->as('role.administrator');
        $page = Livewire::test(ExplorePage::class)->call('openFilter', 'stage')->call('toggleFilterValue', 'stage', 'full');
        $full = DB::table('projects')->whereNull('deleted_at')->where('application_stage', 'full')->count();
        $this->assertSame($full, $page->viewData('rowsPage')->total());
        $page->call('setShow', 'pivot')->assertSet('filters.stage.values', ['full']);
        $this->assertSame($full, (int) $page->viewData('pivots')[0]['grand']);
        $page->call('setShow', 'chart')->assertSet('filters.stage.values', ['full'])->assertSeeHtml('<canvas');
        $page->call('setShow', 'list');
        $this->assertSame($full, $page->viewData('rowsPage')->total());
        $this->assertSame($full, (int) $page->viewData('kpis')['count']);
    }

    public function test_every_ready_question_is_valid_for_its_dataset_and_has_both_labels(): void
    {
        foreach (DatasetRegistry::DATASETS as $key => $class) {
            $dataset = DatasetRegistry::find($key);
            $this->assertNotEmpty(ExplorePresets::for($key));
            foreach (ExplorePresets::for($key) as $preset) {
                $this->assertContains($preset['view'], ExplorePresets::VIEWS);
                if ($preset['view'] !== 'list') {
                    $spec = new ReportSpec($preset['rows'], $preset['rowGrain'] ?: null, $preset['columns'] ?: null, $preset['columnGrain'] ?: null,
                        $preset['measure'], $preset['measureField'] ?: null, \App\Support\Datasets\QuerySpec::fromArray(['filters' => $preset['filters']]));
                    $this->assertSame([], $spec->errors($dataset), "$key/{$preset['key']}");
                } else {
                    $this->assertSame([], \App\Support\Datasets\QuerySpec::fromArray(['filters' => $preset['filters']])->errors($dataset), "$key/{$preset['key']}");
                }
                foreach (['ar', 'en'] as $locale) {
                    $this->assertNotSame('explore.q.'.$key.'.'.$preset['key'], __('explore.q.'.$key.'.'.$preset['key'], [], $locale));
                }
            }
        }
        $this->as('role.administrator');
        $page = Livewire::test(ExplorePage::class);
        $page->call('openQuestion', 'state_by_city')->assertSet('show', 'pivot')->assertSet('rows', 'state')->assertSet('columns', 'city');
        $page->call('openQuestion', 'full_stage')->assertSet('show', 'list')->assertSet('filters.stage.values', ['full']);
        $this->assertSame(DB::table('projects')->whereNull('deleted_at')->where('application_stage', 'full')->count(), $page->viewData('rowsPage')->total());
    }

    public function test_a_one_city_account_never_sees_another_city_in_the_list_the_pivot_or_the_chart(): void
    {
        [, $dam] = $this->oneCityAccount();
        $mine = DB::table('projects')->whereNull('deleted_at')->where('city_id', $dam)->count();
        $this->assertLessThan(DB::table('projects')->whereNull('deleted_at')->count(), $mine, 'the seed must hold other cities too');

        $page = Livewire::test(ExplorePage::class)->assertSet('source', 'projects');
        $this->assertSame($mine, $page->viewData('rowsPage')->total());
        $this->assertSame([$dam], array_values(array_unique(array_map(fn ($r) => (int) DB::table('projects')->where('id', $r->id)->value('city_id'), $page->viewData('rowsPage')->items()))));
        $this->assertSame($mine, (int) $page->viewData('kpis')['count']);

        $page->call('setShow', 'pivot')->set('rows', 'city');
        $pivot = $page->viewData('pivots')[0];
        $this->assertSame([(string) $dam], array_column($pivot['rows'], 'key'));
        $this->assertSame($mine, (int) $pivot['grand']);

        $page->call('setShow', 'chart');
        $chart = $page->viewData('charts')[0];
        $this->assertCount(1, $chart['labels']);
        $this->assertSame([(float) $mine], array_map('floatval', $chart['datasets'][0]['data']));

        // Another city asked for by the address is simply empty; a drill into it lists nothing.
        $other = (int) DB::table('cities')->where('id', '!=', $dam)->value('id');
        $page->call('setShow', 'pivot')->call('drill', (string) $other, null, null);
        $this->assertSame(0, $page->viewData('rowsPage')->total());
        // Instalments (finance.view) are not offered at all.
        $this->assertSame(['projects', 'people'], array_keys($page->viewData('datasets')));
    }

    public function test_export_is_refused_without_the_capability_and_offered_with_it(): void
    {
        $this->as('role.guest');
        $guest = Livewire::test(ExplorePage::class)->assertOk();
        $this->assertFalse($guest->viewData('columnPicker')['can_export']);
        $guest->assertDontSee(__('hc.export_xlsx', [], 'ar'));
        $guest->call('exportTable', 'csv')->assertForbidden();
        Livewire::test(ExplorePage::class)->call('exportReport', 'csv')->assertForbidden();

        $me = $this->as('role.communication');
        $communication = Livewire::test(ExplorePage::class)->assertOk();
        $this->assertFalse($communication->viewData('columnPicker')['can_export']);
        $this->assertFalse($communication->viewData('canExportReport'));
        $communication->call('setShow', 'pivot')->assertDontSee(__('hc.export_xlsx', [], 'ar'));
        $communication->call('exportTable', 'xlsx')->assertForbidden();
        Livewire::test(ExplorePage::class)->call('exportReport', 'xlsx')->assertForbidden();
        $this->assertFalse(DB::table('account_audit_events')->where('actor_account_id', $me->id)->whereIn('event_type', ['reports.exported', 'projects.exported'])->exists());

        // The administrator exports the list and the pivot through the existing single-use tokens.
        $admin = $this->as('role.administrator');
        app(\App\Support\CityContext::class)->restrictTo(DB::table('cities')->pluck('id')); // what the request middleware does
        $page = Livewire::test(ExplorePage::class);
        $page->call('exportTable', 'csv')->assertRedirect();
        $url = (string) parse_url($this->lastRedirect($page), PHP_URL_PATH);
        $this->assertStringContainsString('/ar/projects/export/', $url);
        $this->get($url)->assertOk();
        $this->assertTrue(DB::table('account_audit_events')->where('actor_account_id', $admin->id)->where('event_type', 'projects.exported')->exists());
        $this->get($url)->assertNotFound(); // single use

        $page = Livewire::test(ExplorePage::class)->call('setShow', 'pivot');
        $page->call('exportReport', 'csv')->assertRedirect();
        $this->get((string) parse_url($this->lastRedirect($page), PHP_URL_PATH))->assertOk();
        $this->assertTrue(DB::table('account_audit_events')->where('actor_account_id', $admin->id)->where('event_type', 'reports.exported')->exists());
    }

    private function lastRedirect($component): string
    {
        return (string) $component->effects['redirect'];
    }

    public function test_an_ask_applied_event_changes_the_filters(): void
    {
        $this->as('role.administrator');
        $page = Livewire::test(ExplorePage::class)->call('setShow', 'chart');
        $page->dispatch('ask-applied', dataset: 'projects', spec: ['filters' => [['field' => 'stage', 'op' => 'in', 'values' => ['full']]]])
            ->assertSet('filters.stage', ['op' => 'in', 'values' => ['full']])->assertSet('show', 'list');
        $this->assertSame(DB::table('projects')->whereNull('deleted_at')->where('application_stage', 'full')->count(), $page->viewData('rowsPage')->total());

        // With a report: the pivot opens with the asked rows, columns and measure; the old filters are replaced.
        $page->dispatch('ask-applied', dataset: 'projects', spec: ['filters' => [['field' => 'sex', 'op' => 'in', 'values' => ['female']]]],
            report: ['rows' => 'state', 'columns' => 'city', 'measure' => 'count'])
            ->assertSet('show', 'pivot')->assertSet('rows', 'state')->assertSet('columns', 'city')->assertSet('filters', ['sex' => ['op' => 'in', 'values' => ['female']]]);

        // Another dataset the account may read; one it may not read, an unknown field and a tampered spec change nothing.
        $page->dispatch('ask-applied', dataset: 'people', spec: ['filters' => []])->assertSet('source', 'people')->assertSet('filters', []);
        $page->dispatch('ask-applied', dataset: 'nonsense', spec: ['filters' => []])->assertSet('source', 'people');
        $page->dispatch('ask-applied', dataset: 'projects', spec: ['filters' => [['field' => 'no_such_field', 'op' => 'in', 'values' => ['1']]]])->assertSet('source', 'people');

        // An account that cannot read instalments cannot be sent there by an event.
        $this->as('role.data-entry');
        Livewire::test(ExplorePage::class)->dispatch('ask-applied', dataset: 'installments', spec: ['filters' => []])->assertSet('source', 'projects');
    }

    public function test_tampered_address_values_fall_back_and_text_never_reaches_the_address(): void
    {
        $this->as('role.administrator');
        Livewire::withQueryParams(['ds' => 'nope', 'v' => 'x', 'rows' => 'password', 'm' => 'drop', 'chart' => 'evil'])->test(ExplorePage::class)
            ->assertSet('source', 'projects')->assertSet('show', 'list')->assertSet('rows', 'state')->assertSet('measure', 'count')->assertSet('chart', 'bar');
        $page = Livewire::test(ExplorePage::class)->call('openFilter', 'beneficiary')->call('setFilterText', 'beneficiary', 'سامر');
        $this->assertSame([], $page->get('filters'));
        $this->assertArrayHasKey('beneficiary', $page->get('textFilters'));
    }
}
