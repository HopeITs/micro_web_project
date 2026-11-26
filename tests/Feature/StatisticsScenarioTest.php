<?php

namespace Tests\Feature;

use App\Livewire\StatisticsPage;
use App\Models\SavedView;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Reports\ProjectReportFilters;
use App\Services\Reports\Search\SearchService;
use App\Services\Reports\StatisticsService;
use App\Services\Staff\AuditLogService;
use App\Support\Navigation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\TestCase;

/**
 * M4.17-C user scenarios: every axis counts each project once and equals a direct query on the seeded data, the funded series follows the
 * state keys, filters apply to every axis, a one-city account never sees another city, a guest is refused, the export is token based,
 * authorised and audited.
 *
 * Filter bar and reports (25 November 2026): "is none of" is the complement of "is one of"; the count beside a value is what ticking it
 * gives; cross tables add up to the single axis; top N plus others is everything; a pressed bar filters to exactly its number; the page
 * checks every value; the export follows the view; saved views keep keys only.
 */
class StatisticsScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function as(string $username): WebAccount
    {
        $account = WebAccount::where('username', $username)->firstOrFail();
        $this->actingAs($account);

        return $account;
    }

    private function custom(string $name, array $capabilities, array $cities): WebAccount
    {
        $provisioner = app(AccountProvisioner::class);
        $account = $provisioner->createPlain($name, $name.'@hcsyria.org', Hash::make('a-long-test-password'), 'test:statistics');
        foreach ($cities as $city) {
            foreach ($capabilities as $capability) {
                $provisioner->grant($account, $city, $capability, 'test:statistics');
            }
        }
        DB::table('web_accounts')->where('id', $account->id)->update(['password_hash' => Hash::make('a-long-test-password'), 'password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        $account = $account->fresh();
        $this->actingAs($account);

        return $account;
    }

    private function stats(string $axis, array $state = []): array
    {
        return app(StatisticsService::class)->axis(auth()->user(), $axis, $state);
    }

    /** Projects with a state in force, optionally in a city and in the given state names. */
    private function direct(?int $city = null, ?array $states = null): int
    {
        $q = DB::table('projects as p')->join('project_state_history as h', fn ($j) => $j->on('h.project_id', '=', 'p.id')->where('h.is_current', true)->whereNull('h.deleted_at'))
            ->join('project_states as s', 's.id', '=', 'h.project_state_id')->whereNull('p.deleted_at');
        $city !== null && $q->where('p.city_id', $city);
        $states !== null && $q->whereIn('s.name_en', $states);

        return (int) $q->distinct()->count('p.id');
    }

    public function test_every_axis_counts_each_project_once_and_status_equals_a_direct_query(): void
    {
        $this->as('role.administrator');
        $all = $this->direct();
        $this->assertGreaterThan(0, $all);
        foreach (['status', 'fund_type', 'project_type', 'subtype', 'category', 'subcategory', 'partnership', 'parish', 'sex', 'marital', 'age'] as $axis) {
            $result = $this->stats($axis);
            $this->assertSame($all, $result['totals']['all'], "$axis lists every project once");
            $this->assertSame(['all', 'funded'], $result['series']);
        }
        $status = $this->stats('status');
        $this->assertSame($this->direct(null, StatisticsService::FUNDED), $status['totals']['funded']);
        foreach ($status['rows'] as $row) {
            $name = DB::table('project_states')->where('id', (int) $row['key'])->value('name_en');
            $this->assertSame($this->direct(null, [$name]), $row['counts']['all'], $name);
            $this->assertSame(in_array($name, StatisticsService::FUNDED, true) ? $row['counts']['all'] : 0, $row['counts']['funded'], $name);
        }
        // Computed axes run and never exceed the projects.
        foreach (['donor', 'fund_year', 'fund_amount', 'sustainability'] as $axis) {
            $result = $this->stats($axis);
            $this->assertLessThanOrEqual($all, array_sum($result['totals']), $axis);
        }
        $loans = (int) DB::table('loans')->whereNull('deleted_at')->distinct()->count('project_id');
        $this->assertSame($loans, $this->stats('fund_year')['totals']['all'], 'Fund year counts the projects with a loan.');
        $this->assertSame($loans, $this->stats('fund_amount')['totals']['all']);
    }

    public function test_a_filter_applies_to_every_axis_and_cities_split_the_numbers(): void
    {
        $this->as('role.administrator');
        $dam = (int) DB::table('cities')->where('code', 'DAM')->value('id');
        $hms = (int) DB::table('cities')->where('code', 'HMS')->value('id');
        $one = $this->stats('status', ['cities' => [$dam]]);
        $this->assertSame($this->direct($dam), $one['totals']['all']);
        $this->assertSame([$dam], $one['shown']);
        $both = $this->stats('project_type', ['cities' => [$dam, $hms]]);
        $this->assertSame($this->direct($dam) + $this->direct($hms), $both['totals']['all']);
        $perCity = [0, 0];
        foreach ($both['rows'] as $row) {
            $perCity[0] += $row['by_city'][$dam] ?? 0;
            $perCity[1] += $row['by_city'][$hms] ?? 0;
        }
        $this->assertSame([$this->direct($dam), $this->direct($hms)], $perCity);

        // A state filter narrows every axis to those projects.
        $waiting = (int) DB::table('project_states')->where('name_en', 'Waiting')->value('id');
        $expected = DB::table('project_state_history')->where('is_current', true)->whereNull('deleted_at')->where('project_state_id', $waiting)->distinct()->count('project_id');
        foreach (['status', 'category', 'sex', 'age', 'partnership'] as $axis) {
            $this->assertSame($expected, $this->stats($axis, ['status' => [$waiting]])['totals']['all'], $axis);
        }
        // Request year applies on every axis, including those that once skipped it.
        $year = (int) substr((string) DB::table('projects')->whereNotNull('request_date')->orderBy('id')->value('request_date'), 0, 4);
        $inYear = (int) DB::table('projects')->whereNull('deleted_at')->where('request_date', '>=', "$year-01-01")->where('request_date', '<', ($year + 1).'-01-01')->count();
        $this->assertSame($inYear, $this->stats('status', ['request_year' => [$year]])['totals']['all']);
        $this->assertLessThanOrEqual($inYear, $this->stats('donor', ['request_year' => [$year]])['totals']['all']);
    }

    public function test_a_one_city_account_never_sees_another_city_and_a_guest_is_refused(): void
    {
        $hms = (int) DB::table('cities')->where('code', 'HMS')->value('id');
        $dam = (int) DB::table('cities')->where('code', 'DAM')->value('id');
        $this->custom('stats.onecity', AccessRoles::capabilities('administrator'), [$hms]);
        $this->assertSame($this->direct($hms), $this->stats('status')['totals']['all']);
        $this->assertSame($this->direct($hms), $this->stats('status', ['cities' => [$dam]])['totals']['all'], 'The URL cannot widen the scope.');
        Livewire::test(StatisticsPage::class)->assertOk()->assertSee(__('statistics.axes.status'));

        $bare = $this->custom('stats.bare', ['projects.view'], [$hms]);
        $this->get(route('statistics.index', ['locale' => 'ar']))->assertForbidden();
        $this->assertGreaterThan(0, DB::table('account_audit_events')->where('actor_account_id', $bare->id)->where('event_type', 'access.denied')->count());
        $this->assertArrayNotHasKey('statistics', collect(Navigation::for($bare))->flatten(1)->pluck('url', 'key')->filter()->all());
    }

    public function test_the_page_renders_for_the_administrator_with_a_chart_and_links_to_the_search(): void
    {
        $this->as('role.administrator');
        $this->get(route('statistics.index', ['locale' => 'en']))->assertOk()->assertSee(__('statistics.title', [], 'en'));
        $page = Livewire::test(StatisticsPage::class)->assertSee(__('statistics.series.funded'))->assertSeeHtml('class="hc-chart"');
        $page->set('axis', 'fund_type')->assertSee(__('statistics.axes.fund_type'));
        $page->assertSeeHtml('/search?');
        $page->set('axis', 'sustainability')->assertSee(__('statistics.series.ongoing'));
        $page->set('axis', 'not-an-axis')->assertOk(); // falls back to a valid axis
    }

    /** The applications search's project counter for a filter set. */
    private function searched(array $filters): int
    {
        return app(SearchService::class)->page(auth()->user(), 'applications', $filters)['total'];
    }

    private function facets(string $key, array $filters = []): array
    {
        return app(StatisticsService::class)->facetCounts(auth()->user(), $key, $filters);
    }

    private function state(string $name): int
    {
        return (int) DB::table('project_states')->where('name_en', $name)->value('id');
    }

    /** The seeded loans have no rate; give some of them one, so the amount buckets and the USD filter have values. */
    private function priceSomeLoans(): void
    {
        foreach (DB::table('loans')->orderBy('id')->limit(40)->pluck('id') as $i => $id) {
            DB::table('loans')->where('id', $id)->update(['currency' => 'SYP', 'amount' => 15000 * (($i % 8) * 1000 + 500), 'exchange_rate' => 15000]);
        }
    }

    public function test_excluding_values_is_the_exact_complement_of_including_them(): void
    {
        $this->as('role.administrator');
        // Projects without a value must stay when a value is excluded.
        $some = DB::table('projects')->orderBy('id')->limit(7)->pluck('id');
        DB::table('projects')->whereIn('id', $some)->update(['fund_type_id' => null, 'request_date' => null]);
        $all = $this->direct();
        foreach (['status', 'fund_type', 'project_type', 'subtype', 'category', 'subcategory', 'career', 'donor', 'donor_group', 'parish', 'sex', 'marital', 'age', 'home_street', 'project_street', 'request_year', 'fund_year'] as $key) {
            $counts = array_filter($this->facets($key));
            if ($counts === []) {
                continue;
            }
            $values = array_slice(array_keys($counts), 0, 2);
            $in = $this->stats('status', [$key => $values])['totals']['all'];
            $out = $this->stats('status', [$key => $values, 'not' => [$key]])['totals']['all'];
            $this->assertGreaterThan(0, $in, $key);
            $this->assertSame($all, $in + $out, "$key: include and exclude cover every project exactly once");
        }
        $fund = (int) array_key_first($this->facets('fund_type'));
        $this->assertSame(DB::table('projects')->whereNull('deleted_at')->where(fn ($q) => $q->where('fund_type_id', '<>', $fund)->orWhereNull('fund_type_id'))->count(),
            $this->stats('status', ['fund_type' => [$fund], 'not' => ['fund_type']])['totals']['all']);
        // "Exclude" without a value, or for a key that cannot be excluded, is dropped.
        $this->assertSame([], ProjectReportFilters::clean(['not' => ['fund_type', 'q', 'partnership', ['x']]])['not']);
    }

    public function test_the_count_beside_a_value_is_what_ticking_it_gives_under_the_other_filters(): void
    {
        $this->as('role.administrator');
        $this->priceSomeLoans();
        $year = (int) substr((string) DB::table('projects')->orderBy('id')->value('request_date'), 0, 4);
        $base = ['sex' => ['female'], 'request_year' => [$year], 'usd_from' => ''];
        $checked = 0;
        foreach (['status', 'fund_type', 'project_type', 'category', 'career', 'donor', 'parish', 'marital', 'age', 'sex', 'request_year', 'fund_year'] as $key) {
            $counts = $this->facets($key, $base);
            foreach (array_slice($counts, 0, 3, true) as $value => $count) {
                $this->assertSame($count, $this->stats('status', [$key => [$value]] + $base)['totals']['all'], "$key = $value");
                $checked++;
            }
        }
        $this->assertGreaterThan(20, $checked);
        $partnership = $this->facets('partnership', $base);
        foreach (['individual', 'partnership'] as $value) {
            $this->assertSame($partnership[$value], $this->stats('status', ['partnership' => $value] + $base)['totals']['all'], $value);
        }
        // A one-city account counts its own city only, whatever the address asks for.
        $hms = (int) DB::table('cities')->where('code', 'HMS')->value('id');
        $dam = (int) DB::table('cities')->where('code', 'DAM')->value('id');
        $this->custom('stats.facets', ['applications.view'], [$hms]);
        $this->assertSame($this->direct($hms), array_sum($this->facets('status', ['cities' => [$dam]])));
    }

    public function test_the_headline_numbers_and_every_cross_table_add_up(): void
    {
        $this->as('role.administrator');
        $this->priceSomeLoans();
        $summary = app(StatisticsService::class)->summary(auth()->user(), []);
        $this->assertSame($this->direct(), $summary['all']);
        $this->assertSame($this->direct(null, StatisticsService::FUNDED), $summary['funded']);
        $this->assertSame($this->direct(null, StatisticsService::ONGOING), $summary['ongoing']);
        $this->assertSame($this->direct(null, StatisticsService::CLOSED), $summary['closed']);

        $statistics = app(StatisticsService::class);
        $pairs = [['status', 'city'], ['category', 'sex'], ['project_type', 'fund_year'], ['age', 'sex'], ['sustainability', 'city'], ['fund_year', 'fund_type'], ['donor', 'status'],
            ['fund_amount', 'age'], ['sex', 'fund_amount'], ['parish', 'sustainability'], ['partnership', 'marital']];
        foreach ($pairs as [$axis, $by]) {
            $plain = $this->stats($axis);
            $crossed = $statistics->axis(auth()->user(), $axis, [], false, $by);
            $this->assertSame($plain['totals'], $crossed['totals'], "$axis x $by keeps the totals of the single axis");
            $this->assertSame(array_column($plain['rows'], 'counts'), array_column($crossed['rows'], 'counts'), "$axis x $by keeps every row");
            foreach ($crossed['rows'] as $row) {
                foreach ($crossed['series'] as $series) {
                    $this->assertSame($row['counts'][$series], array_sum(array_map(fn ($cell) => $cell[$series] ?? 0, $row['cells'])), "$axis x $by row {$row['key']} $series adds up");
                }
            }
            $shaped = $statistics->shape($crossed);
            $this->assertSame($shaped['total'], array_sum($shaped['column_totals']), "$axis x $by columns add up");
            $this->assertSame($shaped['total'], array_sum(array_column($shaped['rows'], 'value')));
        }
        // One cell against a direct query: the owners of a sex in a category.
        $cell = $statistics->shape($statistics->axis(auth()->user(), 'category', [], false, 'sex'));
        $row = collect($cell['rows'])->first(fn ($r) => ($r['values']['female'] ?? 0) > 0);
        $this->assertSame(DB::table('projects as p')->join('project_applicants as a', 'a.project_id', '=', 'p.id')->join('people as pe', 'pe.id', '=', 'a.person_id')
            ->where('a.role', 'مستفيد')->where('pe.sex', 'female')->where('p.project_category_id', (int) $row['key'])->whereNull('p.deleted_at')->count(), $row['values']['female']);
        // Projects without a loan have their own column when the second axis is a loan axis.
        $withLoan = DB::table('loans')->whereNull('deleted_at')->distinct()->count('project_id');
        $byYear = $statistics->shape($statistics->axis(auth()->user(), 'project_type', [], false, 'fund_year'));
        $this->assertSame($this->direct() - $withLoan, $byYear['column_totals']['na']);
        // An axis cannot be crossed with itself or with an unknown one.
        $this->assertNull($statistics->axis(auth()->user(), 'status', [], false, 'status')['by']);
        $this->assertNull($statistics->axis(auth()->user(), 'status', [], false, 'x" or 1')['by']);
    }

    public function test_order_largest_n_with_others_and_the_funded_view_never_change_the_numbers(): void
    {
        $this->as('role.administrator');
        $statistics = app(StatisticsService::class);
        $raw = $this->stats('category');
        $full = $statistics->shape($raw);
        $this->assertGreaterThan(5, count(array_filter($full['rows'], fn ($r) => $r['value'] > 0)), 'The seeded data has enough categories.');
        $top = $statistics->shape($raw, ['top' => 5, 'sort' => 'desc']);
        $this->assertLessThanOrEqual(6, count($top['rows']));
        $this->assertSame($full['total'], array_sum(array_column($top['rows'], 'value')), 'Top 5 plus the others is everything.');
        $last = end($top['rows']);
        $this->assertTrue($last['other']);
        $values = array_column(array_slice($top['rows'], 0, 5), 'value');
        $sorted = $values;
        rsort($sorted);
        $this->assertSame($sorted, $values);
        $this->assertSame($sorted, array_slice(collect($full['rows'])->pluck('value')->sortDesc()->values()->all(), 0, 5), 'They are the five largest.');
        foreach (array_keys($full['city_totals']) as $city) {
            $this->assertSame($full['city_totals'][$city], array_sum(array_map(fn ($r) => $r['per_city'][$city] ?? 0, $top['rows'])));
        }
        $funded = $statistics->shape($raw, ['show' => 'funded', 'nozero' => true]);
        $this->assertSame(['funded'], $funded['visible']);
        $this->assertSame($raw['totals']['funded'], array_sum(array_column($funded['rows'], 'value')));
        $this->assertNotContains(0, array_column($funded['rows'], 'value'));
        $asc = array_column($statistics->shape($raw, ['sort' => 'asc'])['rows'], 'value');
        $copy = $asc;
        sort($copy);
        $this->assertSame($copy, $asc);
        $this->assertCount(count($full['rows']), $statistics->shape($raw, ['top' => 7, 'sort' => 'evil'])['rows'], 'Unlisted view values are ignored.');
    }

    public function test_a_pressed_bar_filters_to_exactly_its_number_and_the_search_agrees(): void
    {
        $this->as('role.administrator');
        $this->priceSomeLoans();
        $checked = [];
        foreach (['status', 'fund_type', 'project_type', 'subtype', 'category', 'subcategory', 'parish', 'sex', 'marital', 'age', 'donor', 'fund_year', 'fund_amount'] as $axis) {
            $series = $this->stats($axis)['series'][0];
            foreach ($this->stats($axis)['rows'] as $row) {
                if ($row['counts'][$series] === 0 || ($narrowed = StatisticsService::narrow([], $axis, $row['key'])) === null) {
                    continue;
                }
                $this->assertSame($row['counts'][$series], $this->stats($axis, $narrowed)['totals'][$series], "$axis {$row['key']} filtered");
                $this->assertSame($row['counts'][$series], $this->searched($narrowed), "$axis {$row['key']} in the search");
                $checked[$axis] = ($checked[$axis] ?? 0) + 1;
                if ($checked[$axis] === 3) {
                    break;
                }
            }
        }
        $this->assertArrayHasKey('fund_amount', $checked);
        $this->assertArrayHasKey('fund_year', $checked);

        // A filter already set is narrowed, never widened; a bucket with no matching filter offers nothing.
        $this->assertSame(['usd_from' => '500', 'usd_to' => '1000'], StatisticsService::narrow(['usd_from' => '500'], 'fund_amount', 'a0'));
        $this->assertSame(['fund_type' => [3]], StatisticsService::narrow(['fund_type' => [3, 4], 'not' => ['fund_type']], 'fund_type', '3'));
        foreach ([['status', 'none'], ['fund_amount', 'norate'], ['sustainability', 'y0'], ['partnership', '1'], ['partnership', '2'], ['sex', 'robot'], ['status', '1 or 1']] as [$axis, $key]) {
            $this->assertNull(StatisticsService::narrow([], $axis, $key), "$axis $key");
        }
        // The funded numbers lead to the funded states only, inside a state filter already chosen.
        $statistics = app(StatisticsService::class);
        $funded = $statistics->narrowSeries([], 'funded');
        $this->assertSame($this->direct(null, StatisticsService::FUNDED), $this->searched($funded));
        $this->assertNull($statistics->narrowSeries(['status' => [$this->state('Waiting')]], 'funded'));
        $this->assertNotContains($this->state('Financed'), $statistics->narrowSeries(['status' => [$this->state('Financed')], 'not' => ['status']], 'funded')['status']);
    }

    /** The applications search's counter for the address a number of the page links to. */
    private function behind(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('applications', $query['mode']);

        return $this->searched(($query['f'] ?? []) + ['cities' => $query['city']]);
    }

    public function test_every_linked_number_opens_a_search_with_that_many_projects(): void
    {
        $this->as('role.administrator');
        $page = Livewire::test(StatisticsPage::class);
        $followed = 0;
        // Plain table with a filter excluding a state: both series of the first rows with projects.
        $page->call('openFilter', 'status')->call('setExclude', 'status', true)->call('toggleValue', 'status', (string) $this->state('Waiting'))->set('axis', 'category');
        foreach (array_slice(array_values(array_filter($page->viewData('result')['rows'], fn ($r) => $r['value'] > 0)), 0, 3) as $row) {
            foreach (['all', 'funded'] as $series) {
                if ($row['counts'][$series] > 0) {
                    $this->assertSame($row['counts'][$series], $this->behind($row['urls'][$series]), "category {$row['key']} $series");
                    $followed++;
                }
            }
        }
        // Cross tables: cells by a second axis and by city, funded included.
        $page->call('clearFilters');
        foreach ([['category', 'sex', 'both'], ['project_type', 'city', 'funded'], ['fund_year', 'fund_type', 'both']] as [$axis, $by, $show]) {
            $page->set('axis', $axis)->set('by', $by)->set('show', $show);
            $cells = 0;
            foreach ($page->viewData('result')['rows'] as $row) {
                foreach ($row['cell_urls'] as $column => $url) {
                    if ($url !== null && $cells < 4) {
                        $this->assertSame($row['values'][$column], $this->behind($url), "$axis {$row['key']} x $by $column $show");
                        $cells++;
                    }
                }
            }
            $this->assertGreaterThan(0, $cells, "$axis x $by has linked cells");
            $followed += $cells;
        }
        $this->assertGreaterThan(12, $followed);
    }

    public function test_the_filter_bar_checks_every_value_it_is_given(): void
    {
        $this->as('role.administrator');
        $waiting = (string) $this->state('Waiting');
        $page = Livewire::test(StatisticsPage::class)->call('openFilter', 'status')->assertSet('editing', 'status');
        $this->assertNotEmpty($page->viewData('bar')['editor']['options']);
        $this->assertSame($this->direct(null, ['Waiting']), collect($page->viewData('bar')['editor']['options'])->firstWhere('value', $waiting)['count']);
        $page->call('toggleValue', 'status', $waiting)->assertSet('filters', ['status' => [(int) $waiting]]);
        $this->assertSame($this->direct(null, ['Waiting']), $page->viewData('tiles')['all']['count']);
        $page->call('toggleValue', 'status', '999999')->call('toggleValue', 'status', 'abc')->call('toggleValue', 'q', '1')->assertSet('filters', ['status' => [(int) $waiting]]);
        $page->call('setExclude', 'status', true)->assertSet('filters', ['status' => [(int) $waiting], 'not' => ['status']]);
        $this->assertSame($this->direct() - $this->direct(null, ['Waiting']), $page->viewData('tiles')['all']['count']);
        $page->call('setValues', 'status', [])->assertSet('filters', []);

        $today = now(AuditLogService::TIMEZONE);
        $page->call('openFilter', 'request')->call('setPreset', 'request', 'last_year')
            ->assertSet('filters', ['request_from' => ($today->year - 1).'-01-01', 'request_to' => ($today->year - 1).'-12-31'])->assertSee(__('search.ui.preset.last_year'));
        $page->call('setPreset', 'request', 'forever')->call('setRange', 'request', '2025-02-30', '')->assertSet('filters', [])->assertSet('filterNotice', __('search.ui.bad_value'));
        $page->call('setRange', 'amount', '5000', '10')->assertSet('filterNotice', __('search.ui.backwards'));
        $page->call('removeFilter', 'amount')->assertSet('filters', []);
        $page->call('setChoice', 'partnership', 'partnership')->assertSet('filters', ['partnership' => 'partnership'])->call('setChoice', 'partnership', 'nonsense')->assertSet('filters', []);
        $page->call('closeFilter')->call('openFilter', 'meeting')->assertSet('editing', null)->call('openFilter', 'q')->assertSet('editing', null);
        $page->call('toggleCity', 999)->assertSet('cities', []);

        // A bar of the chart: its actions, then the filter.
        $page->call('pick', $waiting)->assertSee(__('statistics.filter_by_this'))->call('filterBy', $waiting)->assertSet('filters', ['status' => [(int) $waiting]])->assertSet('picked', null);
        // A headline tile filters to its states and a second press removes it.
        $page->call('clearFilters')->call('filterSeries', 'funded');
        $this->assertSame($this->direct(null, StatisticsService::FUNDED), $page->viewData('tiles')['all']['count']);
        $this->assertTrue($page->viewData('tiles')['funded']['active']);
        $page->call('filterSeries', 'closed');
        $this->assertSame($this->direct(null, StatisticsService::CLOSED), $page->viewData('tiles')['all']['count']);
        $page->call('filterSeries', 'closed')->assertSet('filters', []);
        // Ready reports set the view and keep the cities and filters.
        $page->call('applyReport', 'top_donors')->assertSet('axis', 'donor')->assertSet('top', '10')->assertSet('chart', 'hbar');
        $page->call('applyReport', 'requests_year')->assertSet('filters', ['request_from' => $today->year.'-01-01', 'request_to' => $today->year.'-12-31']);
        $page->call('clearFilters');
        foreach (array_keys(StatisticsPage::REPORTS) as $report) {
            $page->call('applyReport', $report)->assertOk();
        }
        // Every axis renders crossed with every other, with its chart and table.
        foreach (['status' => 'city', 'sustainability' => 'age', 'fund_amount' => 'fund_year', 'sex' => 'category'] as $axis => $by) {
            $page->set('axis', $axis)->set('by', $by)->assertOk()->assertSee(__('statistics.axes.'.$axis));
        }

        // Nothing unlisted survives from the address, and no text.
        $tampered = Livewire::withQueryParams(['f' => ['q' => 'secret-name', 'status' => ['x'], 'not' => ['q', 'status']], 'by' => 'status', 'top' => '7', 'chart' => 'evil', 'sort' => 'evil', 'show' => 'evil'])
            ->test(StatisticsPage::class)->assertSet('filters', [])->assertSet('by', '')->assertSet('top', '0')->assertSet('chart', 'auto')->assertSet('sortRows', 'default')->assertSet('show', 'both');
        $tampered->assertDontSee('secret-name');
    }

    public function test_the_export_follows_what_is_shown(): void
    {
        $this->as('role.administrator');
        $page = Livewire::test(StatisticsPage::class)->set('axis', 'category')->set('by', 'city')->set('top', '5')->set('sortRows', 'desc');
        $shown = $page->viewData('result');
        $csv = $this->get($page->call('exportTable', 'csv')->effects['redirect'])->assertOk()->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r\n/', trim($csv))));
        $this->assertCount(count($shown['rows']) + 2, $lines);
        $this->assertStringContainsString(DB::table('cities')->where('code', 'DAM')->value('name_ar'), $lines[0]);
        $this->assertStringContainsString((string) $shown['total'], end($lines));
        $this->assertSame('statistics:category/by:city/rows:'.(count($shown['rows']) + 1), DB::table('account_audit_events')->where('event_type', 'reports.exported')->latest('id')->value('decision_locator'));

        $page = Livewire::test(StatisticsPage::class)->set('show', 'funded');
        $csv = $this->get($page->call('exportTable', 'csv')->effects['redirect'])->streamedContent();
        $this->assertStringContainsString(__('statistics.series.funded'), $csv);
        $this->assertStringNotContainsString(__('statistics.series.all'), $csv);
    }

    public function test_a_saved_view_keeps_keys_only_and_applies_within_the_viewers_cities(): void
    {
        $admin = $this->as('role.administrator');
        $dam = (int) DB::table('cities')->where('code', 'DAM')->value('id');
        $hms = (int) DB::table('cities')->where('code', 'HMS')->value('id');
        $page = Livewire::test(StatisticsPage::class)->call('toggleCity', $dam)->call('toggleCity', $hms)->set('axis', 'sex')->set('top', '5')
            ->call('openFilter', 'status')->call('toggleValue', 'status', (string) $this->state('Waiting'))->call('setPreset', 'request', 'this_year')
            ->call('saveView', 'طلبات منتظرة')->assertSet('viewError', null);
        $view = SavedView::query()->where('dataset', 'statistics')->firstOrFail();
        $this->assertSame($admin->id, $view->account_id);
        $this->assertEquals([
            ['field' => 'city', 'op' => 'in', 'values' => [$dam, $hms]], ['field' => 'status', 'op' => 'in', 'values' => [$this->state('Waiting')]], ['field' => 'request', 'op' => 'between', 'values' => ['this_year']],
        ], $view->spec['filters'], 'Field keys, ids and a ready period only.');
        $this->assertSame('sex', $view->spec['view']['axis']);
        $page->call('saveView', 'طلبات منتظرة')->assertSet('viewError', __('hc.view_name_taken'));
        $page->call('clearFilters')->set('axis', 'status')->call('applyView', $view->id)->assertSet('axis', 'sex')->assertSet('top', '5')->assertSet('cities', [$dam, $hms]);
        $this->assertSame([(int) $this->state('Waiting')], $page->get('filters')['status']);
        $this->assertArrayHasKey('request_from', $page->get('filters'));
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'saved_views.created')->exists());
        $page->call('toggleShareView', $view->id);

        $this->custom('stats.viewer', ['applications.view'], [$hms]);
        Livewire::test(StatisticsPage::class)->call('applyView', $view->id)->assertSet('cities', [$hms])->assertSet('viewNotice', __('hc.view_filters_dropped'));
        Livewire::test(StatisticsPage::class)->call('deleteView', $view->id)->assertForbidden();
        $view->refresh()->update(['is_shared' => false]);
        Livewire::test(StatisticsPage::class)->assertDontSee('طلبات منتظرة')->call('applyView', $view->id)->assertStatus(404);

        $this->actingAs($admin);
        Livewire::test(StatisticsPage::class)->call('deleteView', $view->id);
        $this->assertSame(0, SavedView::query()->count());
    }

    public function test_the_export_is_token_based_authorised_and_audited(): void
    {
        $admin = $this->as('role.administrator');
        foreach (['axis', 'cities', 'filters', 'show', 'chart'] as $property) {
            $this->assertNotEmpty((new ReflectionProperty(StatisticsPage::class, $property))->getAttributes(Url::class), $property);
        }
        $redirect = Livewire::test(StatisticsPage::class)->set('axis', 'status')->call('exportTable', 'csv')->effects['redirect'];
        $this->assertMatchesRegularExpression('#/ar/statistics/export/[A-Za-z0-9]{40}$#', $redirect);
        $before = DB::table('account_audit_events')->where('event_type', 'reports.exported')->count();
        $response = $this->get($redirect)->assertOk();
        $csv = $response->streamedContent();
        $states = DB::table('project_states')->count();
        $this->assertSame($states + 2, count(array_filter(preg_split('/\r\n/', trim($csv)))), 'Header, every state, total.');
        $event = DB::table('account_audit_events')->where('event_type', 'reports.exported')->latest('id')->first();
        $this->assertSame($before + 1, DB::table('account_audit_events')->where('event_type', 'reports.exported')->count());
        $this->assertSame('statistics:status/rows:'.($states + 1), $event->decision_locator);
        $this->assertSame($admin->id, (int) $event->actor_account_id);
        $this->get($redirect)->assertNotFound();

        $xlsx = Livewire::test(StatisticsPage::class)->call('exportTable', 'xlsx')->effects['redirect'];
        $this->get($xlsx)->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $token = Livewire::test(StatisticsPage::class)->call('exportTable', 'csv')->effects['redirect'];
        $this->as('role.financial');
        $this->get($token)->assertNotFound(); // another account cannot use it

        // Without reports.export the buttons are absent and the call is refused.
        $viewer = $this->custom('stats.noexport', ['applications.view', 'projects.view'], [1]);
        Livewire::test(StatisticsPage::class)->assertDontSee(__('search.export_csv'))->call('exportTable', 'csv')->assertForbidden();
        $this->assertSame(0, DB::table('account_audit_events')->where('actor_account_id', $viewer->id)->where('event_type', 'reports.exported')->count());
    }
}
