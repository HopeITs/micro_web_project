<?php

namespace Tests\Feature;

use App\Livewire\StatisticsPage;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Reports\StatisticsService;
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
