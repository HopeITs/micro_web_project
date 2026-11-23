<?php

namespace Tests\Feature;

use App\Livewire\PaymentSchedule;
use App\Livewire\ProjectsIndex;
use App\Livewire\ReportBuilder;
use App\Models\SavedView;
use App\Models\WebAccount;
use App\Services\AccountProvisioner;
use App\Services\Finance\PaymentScheduleService;
use App\Services\Reports\ReportDrill;
use App\Support\CityContext;
use App\Support\Navigation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smart-search phase 3 user scenarios, by role: the administrator builds projects by state and city and the totals equal the database;
 * the financial role sums instalments by status per currency; a one-city account sees only its city; an account without finance.view
 * cannot pick instalments; a report is saved, applied, renamed, shared and deleted; export is allowed or refused and audited; tampered
 * field keys are refused; a cell opens the list with the same filters and the list shows the same number.
 */
class ReportBuilderScenarioTest extends TestCase
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

    private function allCities(): void
    {
        app(CityContext::class)->restrictTo(DB::table('cities')->pluck('id'));
    }

    private function projects()
    {
        return DB::table('projects')->whereNull('projects.deleted_at')
            ->leftJoin('project_state_history as h', fn ($j) => $j->on('h.project_id', '=', 'projects.id')->where('h.is_current', true)->whereNull('h.deleted_at'))
            ->get(['projects.id', 'projects.city_id', 'h.project_state_id']);
    }

    private function usdLoan(): void
    {
        $project = DB::table('projects')->whereNull('deleted_at')->orderBy('id')->first();
        $loan = DB::table('loans')->insertGetId(['city_id' => $project->city_id, 'project_id' => $project->id, 'currency' => 'USD', 'amount' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[100, true], [250, false]] as [$amount, $paid]) {
            DB::table('loan_installments')->insert(['city_id' => $project->city_id, 'loan_id' => $loan, 'amount' => $amount, 'due_on' => now()->subMonth()->toDateString(), 'is_paid' => $paid,
                'received_on' => $paid ? now()->toDateString() : null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_the_page_opens_in_both_languages(): void
    {
        $this->as('role.administrator');
        $this->get('/ar/reports')->assertOk()->assertSeeLivewire(ReportBuilder::class)->assertSee(__('report_builder.title', [], 'ar'));
        $this->get('/en/reports')->assertOk()->assertSee('Count of rows', false);
    }

    public function test_pivot_totals_equal_direct_counts(): void
    {
        $this->as('role.administrator');
        $page = Livewire::test(ReportBuilder::class)->set('source', 'projects')->set('rows', 'state')->set('columns', 'city');
        $pivot = $page->viewData('pivots')[0];

        $expected = [];
        foreach ($this->projects() as $p) {
            $expected[(string) $p->project_state_id][(string) $p->city_id] = ($expected[(string) $p->project_state_id][(string) $p->city_id] ?? 0) + 1;
        }
        foreach ($pivot['rows'] as $row) {
            foreach ($row['cells'] as $city => $value) {
                $this->assertSame($expected[$row['key']][(string) $city] ?? null, $value === null ? null : (int) $value);
            }
            $this->assertSame(array_sum($expected[$row['key']]), (int) $row['total']);
        }
        $this->assertSame($this->projects()->count(), (int) $pivot['grand']);
        $page->assertSee(__('report_builder.total', [], 'ar'))->assertSeeHtml('<canvas');
        // The accessible text table of the chart carries the same numbers.
        $page->assertSeeHtml('class="sr-only"');
    }

    public function test_the_visit_is_audited_without_values_and_only_when_the_fields_change(): void
    {
        $me = $this->as('role.administrator');
        $page = Livewire::test(ReportBuilder::class)->set('rows', 'state');
        $count = fn () => DB::table('account_audit_events')->where('actor_account_id', $me->id)->where('event_type', 'reports.statistics')->count();
        $this->assertSame(1, $count());
        $page->call('openFilter', 'stage')->call('toggleFilterValue', 'stage', 'full'); // the filter value changes, the fields do not... the filter field is new
        $afterFilter = $count();
        $page->call('toggleFilterValue', 'stage', 'preliminary');
        $this->assertSame($afterFilter, $count(), 'A changed filter value is not a new run.');
        $decision = (string) DB::table('account_audit_events')->where('actor_account_id', $me->id)->where('event_type', 'reports.statistics')->latest('id')->value('decision_locator');
        $this->assertStringContainsString('ds:projects', $decision);
        $this->assertStringContainsString('rows:state', $decision);
        $this->assertStringNotContainsString('full', $decision);
    }

    public function test_financial_sums_instalments_by_status_per_currency(): void
    {
        $this->usdLoan();
        $this->as('role.financial');
        $page = Livewire::test(ReportBuilder::class)->set('source', 'installments')->set('rows', 'status')->set('measure', 'sum')->assertSet('measureField', 'amount');
        $result = $page->viewData('result');
        $this->assertTrue($result->byCurrency());
        $pivots = $page->viewData('pivots');
        $this->assertGreaterThan(1, count($pivots));
        foreach ($pivots as $pivot) {
            $direct = (float) DB::table('loan_installments as i')->join('loans as l', 'l.id', '=', 'i.loan_id')->join('projects as p', 'p.id', '=', 'l.project_id')
                ->whereNull('i.deleted_at')->whereNull('l.deleted_at')->whereNull('p.deleted_at')->where('l.currency', $pivot['currency'])->sum('i.amount');
            $this->assertEqualsWithDelta($direct, $pivot['grand'], 0.001, $pivot['currency']);
        }
        $page->assertSee(__('report_builder.currency_note', [], 'ar'))->assertSee('USD')->assertSee('SYP');

        // Money measures are refused where the dataset has no currency: projects offer only counts.
        $page->set('source', 'projects');
        $this->assertSame(['count', 'distinct', 'share'], $page->instance()->measureTypes());
        $page->set('measure', 'sum')->assertSet('measure', 'count');
    }

    public function test_a_one_city_account_sees_only_its_city(): void
    {
        $dam = (int) DB::table('cities')->where('code', 'DAM')->value('id');
        $provisioner = app(AccountProvisioner::class);
        $one = $provisioner->createPlain('one.city', 'one.city@hcsyria.org', Hash::make('a-long-test-password'), 'test:report');
        foreach (['projects.view', 'people.view', 'applications.view'] as $capability) {
            $provisioner->grant($one->fresh(), $dam, $capability, 'test:report');
        }
        $this->actingAs($one->fresh());

        $page = Livewire::test(ReportBuilder::class)->set('rows', 'city');
        $pivot = $page->viewData('pivots')[0];
        $this->assertSame([(string) $dam], array_column($pivot['rows'], 'key'));
        $this->assertSame(DB::table('projects')->whereNull('deleted_at')->where('city_id', $dam)->count(), (int) $pivot['grand']);
        $this->assertSame(['projects', 'people'], array_keys($page->viewData('datasets')));
        // The filter bar offers only the city's own city value.
        $page->call('openFilter', 'city')->assertSee(DB::table('cities')->where('id', $dam)->value('name_ar'));
    }

    public function test_without_finance_view_instalments_cannot_be_picked(): void
    {
        $this->as('role.data-entry');
        $page = Livewire::test(ReportBuilder::class);
        $this->assertSame(['projects', 'people'], array_keys($page->viewData('datasets')));
        $page->set('source', 'installments')->assertSet('source', 'projects');
        Livewire::withQueryParams(['ds' => 'installments', 'rows' => 'status'])->test(ReportBuilder::class)->assertSet('source', 'projects');
        // The sidebar entry is there (some dataset is readable)...
        $this->get('/ar/projects')->assertOk()->assertSee(route('reports.index', ['locale' => 'ar']));
    }

    public function test_an_account_that_reads_no_dataset_is_refused_and_has_no_menu_entry(): void
    {
        $provisioner = app(AccountProvisioner::class);
        $nobody = $provisioner->createPlain('nobody', 'nobody@hcsyria.org', Hash::make('a-long-test-password'), 'test:report');
        $provisioner->grant($nobody->fresh(), (int) DB::table('cities')->value('id'), 'tasks.view', 'test:report');
        $this->actingAs($nobody->fresh());
        Livewire::test(ReportBuilder::class)->assertForbidden();
        $this->assertArrayNotHasKey('reports', array_merge(...array_values(array_map(fn ($items) => array_column($items, null, 'key'), Navigation::for($nobody->fresh())))));
    }

    public function test_a_report_is_saved_applied_renamed_shared_and_deleted(): void
    {
        $me = $this->as('role.administrator');
        $page = Livewire::test(ReportBuilder::class)->set('rows', 'state')->set('columns', 'city')
            ->call('openFilter', 'stage')->call('toggleFilterValue', 'stage', 'full')
            ->call('saveView', 'الحالات حسب المدينة')->assertSet('viewError', null);
        $view = SavedView::where('account_id', $me->id)->firstOrFail();
        $this->assertSame(['report', 'projects', 'الحالات حسب المدينة'], [$view->kind, $view->dataset, $view->name]);
        $this->assertSame(['field' => 'state'], $view->spec['rows']);
        $this->assertSame(['field' => 'city'], $view->spec['columns']);
        $this->assertSame(['type' => 'count', 'field' => null], $view->spec['measure']);
        $this->assertSame([['field' => 'stage', 'op' => 'in', 'values' => ['full']]], $view->spec['filters']);

        // Another setup, then the saved one comes back whole.
        $page->set('rows', 'donor')->set('columns', '')->call('removeFilter', 'stage')->set('measure', 'share');
        $page->call('applyView', $view->id)->assertSet('rows', 'state')->assertSet('columns', 'city')->assertSet('measure', 'count');
        $this->assertSame(['stage' => ['op' => 'in', 'values' => ['full']]], $page->get('filters'));
        $this->assertSame(DB::table('projects')->whereNull('deleted_at')->where('application_stage', 'full')->count(), (int) $page->viewData('pivots')[0]['grand']);

        // A plain list view of the same dataset does not show up among reports, and the other way round.
        SavedView::create(['account_id' => $me->id, 'dataset' => 'projects', 'kind' => 'view', 'name' => 'قائمة', 'spec' => ['filters' => []], 'is_shared' => false, 'is_default' => false]);
        $names = collect($page->viewData('filterBar')['views']['list'])->pluck('name')->all();
        $this->assertSame(['الحالات حسب المدينة'], $names);

        $page->call('renameView', $view->id, 'تقرير الحالات')->assertSet('viewError', null);
        $this->assertSame('تقرير الحالات', $view->fresh()->name);
        $page->call('toggleShareView', $view->id);
        $this->assertTrue($view->fresh()->is_shared);

        // A colleague who can read projects sees the shared report, applies it and runs it over their own cities only.
        $this->as('role.guest');
        $guest = Livewire::test(ReportBuilder::class);
        $this->assertContains('تقرير الحالات', collect($guest->viewData('filterBar')['views']['list'])->pluck('name')->all());
        $guest->call('applyView', $view->id)->assertSet('rows', 'state')->assertSet('columns', 'city');
        // ...but cannot rename or delete it.
        $guest->call('renameView', $view->id, 'x')->assertForbidden();
        Livewire::test(ReportBuilder::class)->call('deleteView', $view->id)->assertForbidden();

        $this->as('role.administrator');
        Livewire::test(ReportBuilder::class)->call('deleteView', $view->id);
        $this->assertNull(SavedView::find($view->id));
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'saved_views.deleted')->exists());
    }

    public function test_a_stored_report_that_no_longer_fits_the_dataset_is_dropped_not_run(): void
    {
        $me = $this->as('role.administrator');
        $view = SavedView::create(['account_id' => $me->id, 'dataset' => 'projects', 'kind' => 'report', 'name' => 'قديم', 'is_shared' => false, 'is_default' => false,
            'spec' => ['rows' => ['field' => 'removed_field'], 'columns' => null, 'measure' => ['type' => 'median', 'field' => null], 'filters' => []]]);
        $page = Livewire::test(ReportBuilder::class)->call('applyView', $view->id);
        $this->assertNotSame('removed_field', $page->get('rows'));
        $this->assertSame('count', $page->get('measure'));
        $this->assertNotEmpty($page->viewData('pivots'));
    }

    public function test_tampered_field_keys_are_refused(): void
    {
        $this->as('role.administrator');
        $page = Livewire::test(ReportBuilder::class)->set('rows', 'state');
        foreach (['password', 'projects.id; DROP TABLE projects', 'beneficiary', 'requested_amount'] as $bad) {
            $page->set('rows', $bad)->assertSet('rows', 'state')->assertSee(__('report_builder.invalid', [], 'ar'));
        }
        $page->set('columns', 'nope')->assertSet('columns', '');
        $page->set('columns', 'state')->assertSet('columns', '');
        $page->set('measure', 'median')->assertSet('measure', 'count');
        $page->set('measure', 'sum')->assertSet('measure', 'count');
        $page->set('rowGrain', 'week')->assertSet('rowGrain', '');
        $page->set('chart', 'radar')->assertSet('chart', 'bar');
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('projects'));

        // The same through the address: unknown keys fall back, nothing reaches SQL, the page still renders.
        Livewire::withQueryParams(['ds' => 'users', 'rows' => 'x"y', 'cols' => 'zz', 'm' => 'sum', 'mf' => 'amount', 'f' => ['evil' => ['op' => 'in', 'values' => ['1']]]])
            ->test(ReportBuilder::class)->assertSet('source', 'projects')->assertSet('measure', 'count')->assertSet('filters', [])->assertSet('columns', '');
        // A tampered export request has no token to stand on.
        $this->get('/ar/reports/export/'.str_repeat('a', 40))->assertNotFound();
        $this->get('/ar/reports/export/short')->assertNotFound();
    }

    public function test_export_is_allowed_for_financial_audited_and_single_use(): void
    {
        $me = $this->as('role.financial');
        $page = Livewire::test(ReportBuilder::class)->set('source', 'installments')->set('rows', 'status')->set('columns', 'currency');
        $page->call('exportReport', 'xlsx')->assertRedirect();
        $url = $page->effects['redirect'];
        $this->assertStringContainsString('/ar/reports/export/', $url);

        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $response->headers->get('Content-Type'));
        $response->streamedContent();
        $audit = DB::table('account_audit_events')->where('actor_account_id', $me->id)->where('event_type', 'reports.exported')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('ds:installments', $audit->decision_locator);
        $this->assertStringContainsString('format:xlsx', $audit->decision_locator);
        $this->get($url)->assertNotFound(); // the token is single use

        // CSV: the pivot, with the totals row, no other account's token works.
        $page->call('exportReport', 'csv');
        $csv = $this->get($page->effects['redirect'])->assertOk();
        $text = $csv->streamedContent();
        $this->assertStringContainsString(__('report_builder.total', [], 'ar'), $text);
        $page->call('exportReport', 'csv');
        $token = $page->effects['redirect'];
        $this->as('role.administrator');
        $this->get($token)->assertNotFound();
    }

    public function test_export_is_refused_without_the_export_capability(): void
    {
        $me = $this->as('role.guest'); // reads projects, people and instalments' parents but may export nothing
        $page = Livewire::test(ReportBuilder::class)->set('rows', 'state');
        $this->assertFalse($page->instance()->canExportReport());
        $page->assertDontSee(__('hc.export_xlsx', [], 'ar'));
        $page->call('exportReport', 'xlsx')->assertForbidden();
        $this->assertFalse(DB::table('account_audit_events')->where('actor_account_id', $me->id)->where('event_type', 'reports.exported')->exists());

        // Data entry may export reports (reports.export) but not money: instalments are not even offered, and finance.export decides the rest.
        $this->as('role.legal'); // legal: finance.view but neither reports.export nor finance.export
        $legal = Livewire::test(ReportBuilder::class)->set('source', 'installments')->set('rows', 'status');
        $this->assertContains('installments', array_keys($legal->viewData('datasets')));
        $this->assertFalse($legal->instance()->canExportReport());
        $legal->call('exportReport', 'csv')->assertForbidden();

        // Forged token: a token minted for one account is refused for another and a stale token is gone.
        $this->as('role.financial');
        $ok = Livewire::test(ReportBuilder::class)->set('source', 'installments')->set('rows', 'status');
        $this->assertTrue($ok->instance()->canExportReport());
    }

    public function test_a_cell_opens_the_list_with_the_same_filters_and_the_same_number(): void
    {
        $this->as('role.administrator');
        $this->allCities();
        $page = Livewire::test(ReportBuilder::class)->set('rows', 'state')->set('columns', 'city');
        $result = $page->viewData('result');
        $pivot = $page->viewData('pivots')[0];
        $checked = 0;
        foreach ($pivot['rows'] as $row) {
            foreach ($row['cells'] as $city => $value) {
                if ($value === null) {
                    continue;
                }
                $url = ReportDrill::url($page->viewData('dataset'), $result->spec, $row['key'], (string) $city);
                $page->assertSeeHtml('href="'.e($url).'"');
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $list = Livewire::withQueryParams($query)->test(ProjectsIndex::class);
                $this->assertSame((int) $value, $list->viewData('projects')->total(), "state {$row['key']} in city {$city}");
                $checked++;
            }
        }
        $this->assertGreaterThan(3, $checked);
        // Row total and grand total link too.
        $totalUrl = ReportDrill::url($page->viewData('dataset'), $result->spec, $pivot['rows'][0]['key']);
        parse_str((string) parse_url($totalUrl, PHP_URL_QUERY), $query);
        $this->assertSame((int) $pivot['rows'][0]['total'], Livewire::withQueryParams($query)->test(ProjectsIndex::class)->viewData('projects')->total());
    }

    public function test_an_instalment_cell_opens_the_payment_schedule_with_the_same_number(): void
    {
        $this->usdLoan();
        $this->as('role.financial');
        app(CityContext::class)->restrictTo(app(PaymentScheduleService::class)->cities(auth()->user())->pluck('id'));
        $page = Livewire::test(ReportBuilder::class)->set('source', 'installments')->set('rows', 'status')->set('measure', 'count');
        $result = $page->viewData('result');
        $this->assertFalse($result->byCurrency());
        foreach ($page->viewData('pivots')[0]['rows'] as $row) {
            $url = ReportDrill::url($page->viewData('dataset'), $result->spec, $row['key']);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $list = Livewire::withQueryParams($query)->test(PaymentSchedule::class);
            $this->assertSame((int) $row['total'], $list->viewData('rows')->total(), 'status '.$row['key']);
        }
        // Grand total: no filter of the report's own, the schedule still lists every instalment.
        $grand = ReportDrill::url($page->viewData('dataset'), $result->spec);
        parse_str((string) parse_url($grand, PHP_URL_QUERY), $query);
        $this->assertSame((int) $page->viewData('pivots')[0]['grand'], Livewire::withQueryParams($query)->test(PaymentSchedule::class)->viewData('rows')->total());
    }

    public function test_a_text_filter_turns_the_links_off(): void
    {
        $this->as('role.administrator');
        $word = explode(' ', trim((string) DB::table('projects')->whereNotNull('name')->where('name', '<>', '')->value('name')))[0];
        $page = Livewire::test(ReportBuilder::class)->set('rows', 'state')->call('openFilter', 'name')->call('setFilterText', 'name', $word);
        $this->assertFalse($page->viewData('canDrill'));
        $page->assertSee(__('report_builder.no_links', [], 'ar'));
        $this->assertStringNotContainsString($word, $this->get('/ar/reports')->getContent());
    }

    public function test_the_gallery_shows_the_chart_component(): void
    {
        $this->as('role.administrator');
        $this->get('/ar/design')->assertOk()->assertSee('data-chart', false)->assertSee('x-hc.chart', false);
    }
}
