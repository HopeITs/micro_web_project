<?php

namespace Tests\Unit;

use App\Models\WebAccount;
use App\Services\AccountProvisioner;
use App\Services\Reports\ReportDrill;
use App\Services\Reports\ReportQuery;
use App\Support\Datasets\InstallmentsDataset;
use App\Support\Datasets\InvalidQuerySpec;
use App\Support\Datasets\PeopleDataset;
use App\Support\Datasets\ProjectsDataset;
use App\Support\Datasets\ReportSpec;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** ReportQuery: one GROUP BY, city scoped, currency never mixed (smart-search plan 2026-11, phase 3) on the synthetic data. */
class ReportQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('ar');
    }

    private function report(string $username, object $dataset, array $spec, array $extra = [])
    {
        $account = WebAccount::where('username', $username)->firstOrFail();

        return app(ReportQuery::class)->run($account, $dataset, ReportSpec::fromArray($spec), $extra);
    }

    /** Projects with their current state id and city, as the database says. */
    private function projects()
    {
        return DB::table('projects')->whereNull('projects.deleted_at')
            ->leftJoin('project_state_history as h', fn ($j) => $j->on('h.project_id', '=', 'projects.id')->where('h.is_current', true)->whereNull('h.deleted_at'))
            ->get(['projects.id', 'projects.city_id', 'projects.request_date', 'h.project_state_id']);
    }

    /** @return array<string, array<string, int>> row key => column key => value, from a result's first slice */
    private function cells($result): array
    {
        $out = [];
        foreach ($result->pivot(0, true)['rows'] as $row) {
            foreach ($row['cells'] as $col => $value) {
                if ($value !== null) {
                    $out[$row['key']][(string) $col] = (int) $value;
                }
            }
        }

        return $out;
    }

    public function test_group_by_a_lookup_counts_every_row_once(): void
    {
        $result = $this->report('role.administrator', new ProjectsDataset, ['rows' => 'state', 'measure' => 'count']);
        $pivot = $result->pivot(0, true);

        $expected = $this->projects()->groupBy(fn ($p) => (string) $p->project_state_id)->map->count();
        $this->assertGreaterThan(1, $expected->count());
        $got = collect($pivot['rows'])->mapWithKeys(fn ($r) => [$r['key'] => (int) $r['total']]);
        $this->assertEquals($expected->all(), $got->all());
        $this->assertSame($this->projects()->count(), (int) $pivot['grand']);
        // Labels are the lookup names in the current language, in the lookup's own order.
        $financed = (string) DB::table('project_states')->where('name_en', 'Financed')->value('id');
        $this->assertSame(DB::table('project_states')->where('id', $financed)->value('name_ar'), collect($pivot['rows'])->firstWhere('key', $financed)['label']);
    }

    public function test_a_null_group_is_labelled_not_set_and_comes_last(): void
    {
        $withoutDonor = DB::table('projects')->whereNull('deleted_at')->whereNull('donor_id')->count();
        $this->assertGreaterThan(0, $withoutDonor, 'The sample data has projects without a donor.');
        $pivot = $this->report('role.administrator', new ProjectsDataset, ['rows' => 'donor'])->pivot(0, true);
        $last = end($pivot['rows']);
        $this->assertSame('', $last['key']);
        $this->assertSame(__('report_builder.not_set', [], 'ar'), $last['label']);
        $this->assertSame('غير محدد', $last['label']);
        $this->assertSame($withoutDonor, (int) $last['total']);
        app()->setLocale('en');
        $english = $this->report('role.administrator', new ProjectsDataset, ['rows' => 'donor'])->pivot(0, true)['rows'];
        $this->assertSame('Not set', end($english)['label']);
    }

    public function test_group_by_date_year_and_month(): void
    {
        $byYear = $this->report('role.administrator', new ProjectsDataset, ['rows' => ['field' => 'request_date', 'grain' => 'year']])->pivot(0, true);
        $expected = $this->projects()->groupBy(fn ($p) => $p->request_date === null ? '' : substr((string) $p->request_date, 0, 4))->map->count()->all();
        $this->assertEquals($expected, collect($byYear['rows'])->mapWithKeys(fn ($r) => [$r['key'] => (int) $r['total']])->all());

        $byMonth = $this->report('role.administrator', new ProjectsDataset, ['rows' => ['field' => 'request_date', 'grain' => 'month']])->pivot(0, true);
        $expected = $this->projects()->groupBy(fn ($p) => $p->request_date === null ? '' : substr((string) $p->request_date, 0, 7))->map->count()->all();
        $this->assertEquals($expected, collect($byMonth['rows'])->mapWithKeys(fn ($r) => [$r['key'] => (int) $r['total']])->all());
        $this->assertMatchesRegularExpression('/\A\d{4}-\d{2}\z/', $byMonth['rows'][0]['label']);
        // Oldest first.
        $keys = array_values(array_filter(array_column($byYear['rows'], 'key')));
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);
    }

    public function test_two_dimensions_make_a_pivot_with_exact_totals(): void
    {
        $result = $this->report('role.administrator', new ProjectsDataset, ['rows' => 'state', 'columns' => 'city']);
        $this->assertTrue($result->hasColumns);
        $pivot = $result->pivot(0, true);
        $expected = [];
        foreach ($this->projects() as $p) {
            $expected[(string) $p->project_state_id][(string) $p->city_id] = ($expected[(string) $p->project_state_id][(string) $p->city_id] ?? 0) + 1;
        }
        $this->assertEquals($expected, $this->cells($result));

        $byCity = $this->projects()->groupBy('city_id')->map->count();
        foreach ($pivot['cols'] as $col) {
            $this->assertSame($byCity[(int) $col['key']], (int) $pivot['colTotals'][$col['key']]);
        }
        $this->assertSame($this->projects()->count(), (int) $pivot['grand']);
        $this->assertSame($this->projects()->count(), (int) array_sum(array_column($pivot['rows'], 'total')));
    }

    public function test_share_and_distinct_and_filters(): void
    {
        $share = $this->report('role.administrator', new ProjectsDataset, ['rows' => 'stage', 'measure' => 'share'])->pivot(0, true);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($share['rows'], 'total')), 0.0001);
        $this->assertEqualsWithDelta(100.0, $share['grand'], 0.0001);

        $distinct = $this->report('role.administrator', new ProjectsDataset, ['rows' => 'city', 'measure' => 'distinct'])->pivot(0, true);
        $this->assertSame($this->projects()->count(), (int) $distinct['grand']);

        // The report's own filters narrow it: only "full" applications.
        $full = DB::table('projects')->whereNull('deleted_at')->where('application_stage', 'full')->count();
        $filtered = $this->report('role.administrator', new ProjectsDataset, ['rows' => 'city', 'filters' => [['field' => 'stage', 'op' => 'in', 'values' => ['full']]]])->pivot(0, true);
        $this->assertSame($full, (int) $filtered['grand']);
    }

    public function test_avg_min_max_totals_come_from_the_values_not_from_averaging_averages(): void
    {
        // People: projects_count (a number) by sex; the average of a group is its sum over its count.
        $result = $this->report('role.administrator', new PeopleDataset, ['rows' => 'sex', 'measure' => ['type' => 'avg', 'field' => 'projects_count']]);
        $pivot = $result->pivot(0, true);
        $all = DB::table('people')->whereNull('deleted_at')->count();
        $linked = DB::table('project_applicants as a')->join('people as p', 'p.id', '=', 'a.person_id')->whereNull('a.deleted_at')->whereNull('p.deleted_at')->count();
        $this->assertEqualsWithDelta($linked / max(1, $all), $pivot['grand'], 0.0001);

        $max = $this->report('role.administrator', new PeopleDataset, ['rows' => 'sex', 'measure' => ['type' => 'max', 'field' => 'projects_count']])->pivot(0, true);
        $this->assertSame(max(array_map(fn ($r) => (float) $r['total'], $max['rows'])), (float) $max['grand']);
    }

    public function test_money_is_never_summed_across_currencies(): void
    {
        $project = DB::table('projects')->whereNull('deleted_at')->orderBy('id')->first();
        $loan = DB::table('loans')->insertGetId(['city_id' => $project->city_id, 'project_id' => $project->id, 'currency' => 'USD', 'amount' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[100, true], [250, false]] as [$amount, $paid]) {
            DB::table('loan_installments')->insert(['city_id' => $project->city_id, 'loan_id' => $loan, 'amount' => $amount, 'due_on' => now()->subMonth()->toDateString(), 'is_paid' => $paid,
                'received_on' => $paid ? now()->toDateString() : null, 'created_at' => now(), 'updated_at' => now()]);
        }

        $result = $this->report('role.financial', new InstallmentsDataset, ['rows' => 'status', 'measure' => ['type' => 'sum', 'field' => 'amount']]);
        $this->assertTrue($result->byCurrency());
        $currencies = array_column($result->slices, 'currency');
        $this->assertContains('USD', $currencies);
        $this->assertContains('SYP', $currencies);
        $this->assertSame(array_unique($currencies), $currencies);
        foreach ($result->slices as $i => $slice) {
            $direct = (float) DB::table('loan_installments as i')->join('loans as l', 'l.id', '=', 'i.loan_id')->whereNull('i.deleted_at')->whereNull('l.deleted_at')
                ->join('projects as p', 'p.id', '=', 'l.project_id')->whereNull('p.deleted_at')->where('l.currency', $slice['currency'])->sum('i.amount');
            $this->assertEqualsWithDelta($direct, $result->pivot($i)['grand'], 0.001, $slice['currency']);
        }
        $usd = array_search('USD', $currencies, true);
        $this->assertEqualsWithDelta(350.0, $result->pivot($usd)['grand'], 0.001);

        // Even when the rows ARE the currency, each currency stays in its own table.
        $byCurrency = $this->report('role.financial', new InstallmentsDataset, ['rows' => 'currency', 'measure' => ['type' => 'sum', 'field' => 'amount']]);
        $this->assertGreaterThan(1, count($byCurrency->slices));
        foreach ($byCurrency->slices as $i => $slice) {
            $this->assertSame([$slice['currency']], array_column($byCurrency->pivot($i)['rows'], 'key'));
        }
        // A count is not money: one table.
        $this->assertFalse($this->report('role.financial', new InstallmentsDataset, ['rows' => 'status', 'measure' => 'count'])->byCurrency());
    }

    public function test_only_the_cities_where_the_account_reads_the_dataset_are_covered(): void
    {
        $dam = (int) DB::table('cities')->where('code', 'DAM')->value('id');
        $provisioner = app(AccountProvisioner::class);
        $one = $provisioner->createPlain('one.city', 'one.city@hcsyria.org', Hash::make('a-long-test-password'), 'test:report');
        foreach (['projects.view', 'applications.view'] as $capability) {
            $provisioner->grant($one->fresh(), $dam, $capability, 'test:report');
        }

        $result = app(ReportQuery::class)->run($one->fresh(), new ProjectsDataset, ReportSpec::fromArray(['rows' => 'city']));
        $pivot = $result->pivot(0, true);
        $this->assertSame([(string) $dam], array_column($pivot['rows'], 'key'));
        $this->assertSame(DB::table('projects')->whereNull('deleted_at')->where('city_id', $dam)->count(), (int) $pivot['grand']);
        $this->assertLessThan($this->projects()->count(), (int) $pivot['grand']);

        // No people.view in the city: the people dataset is empty for this account, not an error and not another city's rows.
        $this->assertTrue(app(ReportQuery::class)->run($one->fresh(), new PeopleDataset, ReportSpec::fromArray(['rows' => 'sex']))->isEmpty());
        // Instalments need applications.view AND finance.view.
        $this->assertTrue(app(ReportQuery::class)->run($one->fresh(), new InstallmentsDataset, ReportSpec::fromArray(['rows' => 'status']))->isEmpty());

        // A filter on another city cannot widen it.
        $other = (int) DB::table('cities')->where('id', '<>', $dam)->value('id');
        $filtered = app(ReportQuery::class)->run($one->fresh(), new ProjectsDataset, ReportSpec::fromArray(['rows' => 'city', 'filters' => [['field' => 'city', 'op' => 'in', 'values' => [(string) $other]]]]));
        $this->assertTrue($filtered->isEmpty());
    }

    public function test_the_export_capability_narrows_the_cities_further(): void
    {
        // Guest reads projects but may not export: no city holds both.
        $guest = WebAccount::where('username', 'role.guest')->firstOrFail();
        $result = app(ReportQuery::class)->run($guest, new ProjectsDataset, ReportSpec::fromArray(['rows' => 'city']), ['reports.export']);
        $this->assertTrue($result->isEmpty());
        $this->assertFalse($this->report('role.guest', new ProjectsDataset, ['rows' => 'city'])->isEmpty());
    }

    public function test_an_invalid_spec_never_reaches_the_database(): void
    {
        $this->expectException(InvalidQuerySpec::class);
        $this->report('role.administrator', new ProjectsDataset, ['rows' => 'state; DROP TABLE projects']);
    }

    public function test_drill_down_addresses_carry_ids_and_dates_only(): void
    {
        $projects = new ProjectsDataset;
        $spec = ReportSpec::fromArray(['rows' => 'state', 'columns' => ['field' => 'request_date', 'grain' => 'month'], 'filters' => [['field' => 'stage', 'op' => 'in', 'values' => ['full']]]]);
        $url = urldecode(ReportDrill::url($projects, $spec, '3', '2026-02'));
        $this->assertStringContainsString('/ar/projects?', $url);
        $this->assertStringContainsString('f[state][op]=in', $url);
        $this->assertStringContainsString('f[state][values][0]=3', $url);
        $this->assertStringContainsString('f[request_date][op]=between', $url);
        $this->assertStringContainsString('f[request_date][values][0]=2026-02-01', $url);
        $this->assertStringContainsString('f[request_date][values][1]=2026-02-28', $url);
        $this->assertStringContainsString('f[stage][values][0]=full', $url);
        // "Not set" is "empty"; the city is the list's own chip parameter.
        $cityUrl = urldecode(ReportDrill::url($projects, ReportSpec::fromArray(['rows' => 'city', 'columns' => 'donor']), '2', ''));
        $this->assertStringContainsString('city[0]=2', $cityUrl);
        $this->assertStringContainsString('f[donor][op]=empty', $cityUrl);
        // A name filter cannot be written in an address: no links.
        $named = ReportSpec::fromArray(['rows' => 'state', 'filters' => [['field' => 'name', 'op' => 'contains', 'values' => ['سلمى']]]]);
        $this->assertNull(ReportDrill::url($projects, $named, '3'));
        // Instalments: currency and city are the schedule's own parameters; a bare drill gets the neutral filter.
        $installments = new InstallmentsDataset;
        $iUrl = urldecode(ReportDrill::url($installments, ReportSpec::fromArray(['rows' => 'currency', 'measure' => 'count']), 'SYP', null, 'SYP'));
        $this->assertStringContainsString('currency=SYP', $iUrl);
        $this->assertStringContainsString('f[due_on][op]=not_empty', $iUrl);
    }
}
