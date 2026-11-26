<?php

namespace Tests\Feature;

use App\Livewire\MyDesk;
use App\Models\SavedView;
use App\Models\WebAccount;
use App\Services\Desk\DeskSummary;
use App\Support\Datasets\ProjectsDataset;
use App\Support\Datasets\QuerySpec;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * X3 "My desk" user scenarios: each role sees exactly the tiles its capabilities allow, the numbers equal direct queries and leave out the
 * cities the account does not hold, only the account's own saved items appear, a guest sees no tasks or notices, and the page renders in
 * Arabic and English.
 */
class MyDeskScenarioTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $cities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->cities = DB::table('cities')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertGreaterThanOrEqual(2, count($this->cities));
    }

    private function as(string $role): WebAccount
    {
        $account = WebAccount::where('username', 'role.'.$role)->firstOrFail();
        $this->actingAs($account);

        return $account;
    }

    /** Keeps the account's grants in one city only (the others are revoked, as an administrator would). */
    private function onlyCity(WebAccount $account, int $city): void
    {
        DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', '<>', $city)->update(['revoked_at' => now()]);
    }

    /** @return array<string, array<string, mixed>> the account's tiles by key */
    private function tiles(WebAccount $account): array
    {
        return collect(app(DeskSummary::class)->tiles($account))->keyBy('key')->all();
    }

    private function keys(WebAccount $account): array
    {
        $keys = array_keys($this->tiles($account));
        sort($keys);

        return $keys;
    }

    private function projectIn(int $city, int $skip = 0): object
    {
        return DB::table('projects')->where('city_id', $city)->whereNull('deleted_at')->orderBy('id')->skip($skip)->firstOrFail();
    }

    private function setState(int $project, string $state): void
    {
        $id = (int) DB::table('project_states')->where('name_en', $state)->value('id');
        $this->assertGreaterThan(0, $id);
        DB::table('project_state_history')->where('project_id', $project)->where('is_current', true)->update(['project_state_id' => $id]);
    }

    public function test_each_role_sees_exactly_the_tiles_its_capabilities_allow(): void
    {
        $expected = [
            'administrator' => ['decisions', 'executive', 'installments', 'no_loan', 'notices', 'tasks', 'visits'],
            'financial' => ['executive', 'installments', 'no_loan', 'notices', 'tasks'],
            'data-entry' => ['notices', 'tasks', 'visits'],
            'monitoring' => ['notices', 'tasks', 'visits'],
            'guest' => [],
        ];
        foreach ($expected as $role => $keys) {
            $this->assertSame($keys, $this->keys($this->as($role)), $role);
        }
    }

    public function test_tile_counts_equal_direct_queries_and_leave_out_other_cities(): void
    {
        [$a, $b] = $this->cities;
        $admin = $this->as('administrator');
        $today = now()->toDateString();

        // Waiting decisions and accepted-without-loan projects in both cities.
        foreach ([$a, $b] as $city) {
            $this->setState($this->projectIn($city, 0)->id, 'Waiting');
            $this->setState($this->projectIn($city, 1)->id, 'Under Review');
            $accepted = $this->projectIn($city, 2)->id;
            $this->setState($accepted, 'Accepted');
            DB::table('loans')->where('project_id', $accepted)->update(['deleted_at' => now()]);
        }
        // Visits due: one per city, one far ahead (not due), one already seen.
        $notice = fn (int $city, string $date, bool $seen = false) => DB::table('web_notices')->insert(['city_id' => $city, 'account_id' => null, 'kind' => 'visit', 'notice_date' => $date,
            'title' => 'v', 'body' => 'Visit M1-1', 'seen' => $seen, 'retired_by_kobo' => false, 'created_at' => now()]);
        $notice($a, $today);
        $notice($b, now()->subDays(3)->toDateString());
        $notice($b, now()->addDays(40)->toDateString());
        $notice($a, $today, true);
        // Executive files: expiring in a, expired in b, far in b.
        $file = fn (int $city, int $skip, string $current, int $months) => DB::table('executive_files')->insert(['city_id' => $city, 'project_id' => $this->projectIn($city, $skip)->id,
            'current_on' => $current, 'months' => $months, 'created_at' => now(), 'updated_at' => now()]);
        $file($a, 0, now()->subMonths(12)->toDateString(), 12);
        $file($b, 1, now()->subMonths(30)->toDateString(), 12);
        $file($b, 2, $today, 60);
        // My notices.
        foreach ([$a, $b] as $city) {
            DB::table('web_notices')->insert(['city_id' => $city, 'account_id' => $admin->id, 'kind' => 'message', 'notice_date' => $today, 'title' => 'm', 'body' => 'b', 'seen' => false, 'retired_by_kobo' => false, 'created_at' => now()]);
        }

        $counts = fn (array $cities) => [
            'decisions' => DB::table('projects as p')->whereNull('p.deleted_at')->whereIn('p.city_id', $cities)->whereIn('p.id', fn ($q) => $q->select('project_id')->from('project_state_history')
                ->where('is_current', 1)->whereNull('deleted_at')->whereIn('project_state_id', DB::table('project_states')->whereIn('name_en', ['Waiting', 'Under Review'])->pluck('id')))->count(),
            'no_loan' => DB::table('projects as p')->whereNull('p.deleted_at')->whereIn('p.city_id', $cities)->whereIn('p.id', fn ($q) => $q->select('project_id')->from('project_state_history')
                ->where('is_current', 1)->whereNull('deleted_at')->where('project_state_id', DB::table('project_states')->where('name_en', 'Accepted')->value('id')))
                ->whereNotIn('p.id', DB::table('loans')->whereNull('deleted_at')->select('project_id'))->count(),
            'installments' => (int) DB::selectOne('select count(*) c from loan_installments i join loans l on l.id = i.loan_id join projects p on p.id = l.project_id where i.deleted_at is null and l.deleted_at is null '
                .'and p.deleted_at is null and i.is_paid = 0 and i.due_on < ? and p.city_id in ('.implode(',', $cities).')', [now()->addDays(7)->toDateString()])->c,
            'visits' => DB::table('web_notices')->where('kind', 'visit')->whereNull('account_id')->where('seen', 0)->whereIn('city_id', $cities)->where('notice_date', '<', now()->addDays(7)->toDateString())->count(),
            'executive' => collect(DB::table('executive_files as f')->join('projects as p', 'p.id', '=', 'f.project_id')->whereNull('f.deleted_at')->whereNull('p.deleted_at')->whereIn('f.city_id', $cities)->get(['f.current_on', 'f.months']))
                ->filter(fn ($f) => \Carbon\Carbon::parse($f->current_on)->addMonthsNoOverflow((int) $f->months)->lt(now()->addDays(30)->startOfDay()))->count(),
            'notices' => DB::table('web_notices')->where('account_id', $admin->id)->where('seen', 0)->whereIn('city_id', $cities)->count(),
        ];

        $tiles = $this->tiles($admin);
        foreach ($counts($this->cities) as $key => $expected) {
            $this->assertSame($expected, $tiles[$key]['count'], "$key (all cities)");
        }
        $this->assertGreaterThan(0, $tiles['decisions']['count']);
        $this->assertGreaterThan(0, $tiles['no_loan']['count']);
        $this->assertGreaterThan(0, $tiles['executive']['count']);

        // A data-entry account held in one city counts that city alone.
        $entry = $this->as('data-entry');
        $this->onlyCity($entry, $a);
        $one = $this->tiles($entry);
        $this->assertSame($counts([$a])['visits'], $one['visits']['count']);
        $this->assertLessThan($tiles['visits']['count'], $one['visits']['count']);
        $this->assertSame(1, $one['visits']['count']);

        // Same for the financial account in the second city.
        $financial = $this->as('financial');
        $this->onlyCity($financial, $b);
        $two = $this->tiles($financial);
        $expected = $counts([$b]);
        foreach (['no_loan', 'installments', 'executive'] as $key) {
            $this->assertSame($expected[$key], $two[$key]['count'], "$key (city $b only)");
        }
        $this->assertLessThan($tiles['executive']['count'], $two['executive']['count']);
    }

    public function test_task_tile_counts_the_open_tasks_the_account_is_responsible_for(): void
    {
        $entry = $this->as('data-entry');
        [$a, $b] = $this->cities;
        $definition = (int) DB::table('task_definitions')->where('type', 'a')->where('state', 1)->orderBy('id')->value('id');
        $this->assertSame(0, $this->tiles($entry)['tasks']['count']);

        foreach ([$a, $b] as $city) {
            $project = $this->projectIn($city, 0);
            DB::table('project_tasks')->updateOrInsert(['project_id' => $project->id, 'task_definition_id' => $definition],
                ['city_id' => $city, 'state' => 0, 'task_date' => null, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null]);
            DB::table('account_city_tasks')->insert(['account_id' => $entry->id, 'city_id' => $city, 'task_definition_id' => $definition, 'assigned_at' => now()]);
        }
        $this->assertSame(2, $this->tiles($entry)['tasks']['count']);

        // The task board's own "my tasks" says the same.
        $this->assertSame(2, app(\App\Services\Staff\TaskBoardService::class)->mine($entry)['total']);

        $this->onlyCity($entry, $a);
        $this->assertSame(1, $this->tiles($entry)['tasks']['count']);
    }

    public function test_tile_links_open_the_lists_with_the_same_numbers(): void
    {
        $admin = $this->as('administrator');
        foreach ($this->cities as $city) {
            $accepted = $this->projectIn($city, 2)->id;
            $this->setState($accepted, 'Accepted');
            DB::table('loans')->where('project_id', $accepted)->update(['deleted_at' => now()]);
            $this->setState($this->projectIn($city, 0)->id, 'Waiting');
        }
        $tiles = $this->tiles($admin);
        foreach (['decisions', 'no_loan'] as $key) {
            parse_str((string) parse_url($tiles[$key]['href'], PHP_URL_QUERY), $query);
            $dataset = new ProjectsDataset;
            $spec = QuerySpec::fromArray(['filters' => collect($query['f'])->map(fn ($f, $field) => ['field' => $field, 'op' => $f['op'], 'values' => $f['values'] ?? []])->values()->all()])->validate($dataset);
            $list = $dataset->query()->whereIn($dataset->cityColumn(), $this->cities);
            $spec->apply($list, $dataset, [], false);
            $this->assertSame($tiles[$key]['count'], $list->count(), $key);
        }
        $this->assertStringContainsString('/payments', $tiles['installments']['href']);
        $this->assertStringContainsString('/task-board', $tiles['tasks']['href']);
    }

    public function test_only_own_saved_items_show_default_first_and_private_ones_of_others_never(): void
    {
        $me = $this->as('administrator');
        $other = WebAccount::where('username', 'role.financial')->firstOrFail();
        $save = fn (WebAccount $who, string $name, array $extra = []) => SavedView::create($extra + ['account_id' => $who->id, 'dataset' => 'projects', 'kind' => 'view', 'name' => $name,
            'spec' => ['filters' => [['field' => 'state', 'op' => 'in', 'values' => [(string) DB::table('project_states')->where('name_en', 'Waiting')->value('id')]]]],
            'columns' => null, 'is_shared' => false, 'is_default' => false]);
        $save($other, 'PRIVATE-OF-OTHER');
        $save($other, 'SHARED-OF-OTHER', ['is_shared' => true]);
        $save($me, 'ZZ mine plain');
        $save($me, 'AA mine default', ['is_default' => true]);
        $save($me, 'Mine report', ['kind' => 'report', 'spec' => ['rows' => ['field' => 'state'], 'columns' => null, 'measure' => ['type' => 'count', 'field' => null], 'filters' => []]]);
        foreach (range(1, 8) as $i) {
            $save($me, "Extra $i");
        }

        $cards = app(DeskSummary::class)->savedCards($me);
        $names = array_column($cards, 'name');
        $this->assertCount(DeskSummary::SAVED_LIMIT, $cards);
        $this->assertSame('AA mine default', $names[0]);
        $this->assertNotContains('PRIVATE-OF-OTHER', $names);
        $this->assertNotContains('SHARED-OF-OTHER', $names);

        $page = Livewire::test(MyDesk::class)->call('loadCards')->assertSee('AA mine default')->assertDontSee('PRIVATE-OF-OTHER')->assertDontSee('SHARED-OF-OTHER');
        $page->assertSeeHtml('data-desk-card=');

        // The view card shows its live count: projects waiting, in the admin's cities.
        $waiting = DB::table('project_state_history')->where('is_current', 1)->whereNull('deleted_at')->where('project_state_id', DB::table('project_states')->where('name_en', 'Waiting')->value('id'))->count();
        $card = collect($cards)->firstWhere('name', 'AA mine default');
        $this->assertSame($waiting, $card['count']);
        $this->assertStringContainsString('f%5Bstate%5D', $card['href']);

        // The report card ranks its rows (state counts) and links to the report builder.
        $report = collect($cards)->firstWhere('name', 'Mine report');
        $this->assertNotNull($report);
        $this->assertNotEmpty($report['rows']);
        $this->assertLessThanOrEqual(5, count($report['rows']));
        $this->assertStringContainsString('/reports', $report['href']);

        // The other account sees only what it owns.
        $this->as('financial');
        $this->assertContains('SHARED-OF-OTHER', array_column(app(DeskSummary::class)->savedCards($other), 'name'));
        $this->assertNotContains('AA mine default', array_column(app(DeskSummary::class)->savedCards($other), 'name'));
    }

    public function test_a_view_card_counts_only_the_cities_the_account_holds(): void
    {
        $me = $this->as('administrator');
        SavedView::create(['account_id' => $me->id, 'dataset' => 'projects', 'kind' => 'view', 'name' => 'All projects view', 'spec' => ['filters' => []], 'columns' => null, 'is_shared' => false, 'is_default' => false]);
        $total = app(DeskSummary::class)->savedCards($me)[0]['count'];
        $this->assertSame(DB::table('projects')->whereNull('deleted_at')->count(), $total);

        $this->onlyCity($me, $this->cities[0]);
        $one = app(DeskSummary::class)->savedCards($me)[0]['count'];
        $this->assertSame(DB::table('projects')->whereNull('deleted_at')->where('city_id', $this->cities[0])->count(), $one);
        $this->assertLessThan($total, $one);
    }

    public function test_guest_sees_no_tasks_or_notices_blocks_and_an_empty_desk(): void
    {
        $this->as('guest');
        $page = Livewire::test(MyDesk::class);
        $page->assertDontSee(__('desk.tile.tasks'))->assertDontSee(__('desk.tile.notices'))->assertDontSee(__('desk.tile.installments'))
            ->assertSee(__('desk.waiting_none'))->assertDontSeeHtml('data-desk-tile=');
        $page->call('loadCards')->assertSee(__('desk.views_none'));
    }

    public function test_the_page_renders_in_arabic_and_english(): void
    {
        $this->as('administrator');
        $this->get('/ar')->assertOk()->assertSee('مكتبي')->assertSee('بانتظاري')->assertSeeLivewire(MyDesk::class);
        $this->get('/en')->assertOk()->assertSee('My desk')->assertSee('Waiting on me')->assertSee('Recently opened projects');
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(MyDesk::class)->call('loadCards')->assertOk()->assertSee(__('desk.actions'))->assertSee(__('desk.action.new_application'));
        }
        // The recently opened list keeps ids and numbers only: nothing but the key is read, and no name is rendered by the server.
        $this->get('/en')->assertSee('mp.recent.projects', false);
        $this->as('guest');
        $this->get('/en')->assertOk()->assertSee('My desk')->assertDontSee(__('desk.tile.tasks', [], 'en'));
    }
}
