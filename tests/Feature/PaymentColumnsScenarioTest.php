<?php

namespace Tests\Feature;

use App\Livewire\PaymentSchedule;
use App\Models\SavedView;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Finance\PaymentScheduleService;
use App\Support\CityContext;
use App\Support\Datasets\InstallmentsDataset;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** Dynamic columns, header sort, rows per page and the Excel/CSV export on the payment schedule (smart-search plan, phase 2). */
class PaymentColumnsScenarioTest extends TestCase
{
    use RefreshDatabase;

    private WebAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('ar');
        $this->account = WebAccount::where('username', 'role.financial')->firstOrFail();
        $this->actingAs($this->account);
        app(CityContext::class)->restrictTo(app(PaymentScheduleService::class)->cities($this->account)->pluck('id'));
    }

    /** A loan in the given currency on a project, one instalment per [due, paid, amount]. */
    private function loan(int $projectId, array $instalments, string $currency = 'USD'): void
    {
        $project = DB::table('projects')->where('id', $projectId)->first();
        $loan = DB::table('loans')->insertGetId(['city_id' => $project->city_id, 'project_id' => $projectId, 'currency' => $currency, 'amount' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($instalments as [$due, $paid, $amount]) {
            DB::table('loan_installments')->insert(['city_id' => $project->city_id, 'loan_id' => $loan, 'amount' => $amount, 'due_on' => $due, 'is_paid' => $paid,
                'received_on' => $paid ? $due : null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Projects of the financial account's cities, one per city first so city scope can be told apart. */
    private function projects(int $count): array
    {
        $cities = app(PaymentScheduleService::class)->cities($this->account)->pluck('id')->all();

        return DB::table('projects')->whereNull('deleted_at')->whereIn('city_id', $cities)->orderBy('city_id')->orderBy('id')->get(['id', 'city_id'])
            ->groupBy('city_id')->map(fn ($g) => $g->first())->take($count)->values()->all();
    }

    private function fixture(): array
    {
        $projects = $this->projects(2);
        $this->assertCount(2, $projects, 'the sample data has projects in two finance cities');
        [$a, $b] = $projects;
        $this->loan($a->id, [[now()->subDays(40)->toDateString(), true, 100], [now()->subDays(5)->toDateString(), false, 250], [now()->addDays(10)->toDateString(), false, 300]]);
        $this->loan($b->id, [[now()->subDays(2)->toDateString(), false, 700]]);

        return [$a, $b];
    }

    private function page()
    {
        return Livewire::test(PaymentSchedule::class)->set('currency', 'USD')->call('show', 'unpaid');
    }

    /** @return list<string> */
    private function shownKeys($page): array
    {
        return array_column($page->viewData('table')['columns'], 'key');
    }

    /** Follows the page's export redirect and returns [headings, rows] of the CSV. */
    private function csv($page): array
    {
        $url = $page->effects['redirect'] ?? null;
        $this->assertNotNull($url);
        $this->assertStringNotContainsString('search', $url);
        $body = $this->get($url)->assertOk()->streamedContent();
        $lines = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), preg_split('/\r\n/', trim(substr($body, 3))));

        return [$lines[0], array_slice($lines, 1)];
    }

    public function test_columns_show_hide_order_and_are_saved_in_a_view(): void
    {
        $this->fixture();
        $dataset = new InstallmentsDataset;
        $page = $this->page();
        $this->assertSame($dataset->defaultColumns(), $this->shownKeys($page));
        $this->assertSame(['number', 'beneficiary', 'group', 'amount', 'currency', 'due_on', 'status', 'received_on'], $dataset->defaultColumns());
        // Today's table stays: status badge, the finance link and the totals block.
        $page->assertSee(__('finance.installments.not_paid'))->assertSee(__('finance.payments.open'))->assertSee(__('finance.payments.totals'));

        $page->call('setColumns', ['status', 'number', 'loan_amount', 'installment_no', 'days_overdue', 'mobile'])->assertSet('columnError', null)
            ->assertSeeHtml('data-label="'.__('finance.payments.columns.days_overdue').'"')->assertDontSeeHtml('data-label="'.__('finance.payments.columns.amount').'"');
        $this->assertSame(['status', 'number', 'loan_amount', 'installment_no', 'days_overdue', 'mobile'], $this->shownKeys($page));
        $row = collect($page->viewData('rows')->items())->firstWhere('col_days_overdue', 5);
        $this->assertNotNull($row, 'an instalment due 5 days ago is 5 days overdue');
        $this->assertSame(2, (int) $row->col_installment_no);
        $this->assertSame(1000, (int) $row->col_loan_amount);

        $page->call('setColumns', ['number', 'password'])->assertSet('columnError', __('hc.columns_invalid'));
        $this->assertSame(['status', 'number', 'loan_amount', 'installment_no', 'days_overdue', 'mobile'], $this->shownKeys($page));

        $page->call('saveView', 'أقساطي')->assertSet('viewError', null);
        $view = SavedView::sole();
        $this->assertSame(['status', 'number', 'loan_amount', 'installment_no', 'days_overdue', 'mobile'], $view->columns);
        $fresh = Livewire::test(PaymentSchedule::class);
        $this->assertSame($dataset->defaultColumns(), $this->shownKeys($fresh));
        $fresh->call('applyView', $view->id);
        $this->assertSame(['status', 'number', 'loan_amount', 'installment_no', 'days_overdue', 'mobile'], $this->shownKeys($fresh));
        $fresh->call('resetColumns')->assertSet('tableColumns', '');
        $this->assertSame($dataset->defaultColumns(), $this->shownKeys($fresh));

        $url = Livewire::withQueryParams(['cols' => 'amount,evil,number'])->test(PaymentSchedule::class);
        $this->assertSame(['amount', 'number'], $this->shownKeys($url));
    }

    public function test_header_sort_and_rows_per_page(): void
    {
        $this->fixture();
        $page = $this->page()->call('sortBy', 'amount')->assertSet('tableSort', 'amount');
        $amounts = array_map(fn ($r) => (float) $r->col_amount, $page->viewData('rows')->items());
        $sorted = $amounts;
        sort($sorted);
        $this->assertSame($sorted, $amounts);
        $this->assertSame([250.0, 300.0, 700.0], array_slice($amounts, 0, 3));
        $this->assertStringContainsString('aria-sort="ascending"', $page->html());
        $page->call('sortBy', 'amount');
        $amounts = array_map(fn ($r) => (float) $r->col_amount, $page->viewData('rows')->items());
        $this->assertSame(700.0, $amounts[0]);
        $this->assertStringContainsString('aria-sort="descending"', $page->html());
        $page->call('sortBy', 'beneficiary')->call('sortBy', 'nope')->assertSet('tableSort', '-amount');

        // Default order is the schedule order (due date) when nothing is sorted.
        $page->call('sortBy', 'amount');
        $dues = array_map(fn ($r) => (string) $r->col_due_on, $this->page()->viewData('rows')->items());
        $sortedDues = $dues;
        sort($sortedDues);
        $this->assertSame($sortedDues, $dues);

        $list = Livewire::test(PaymentSchedule::class)->set('currency', 'USD');
        $this->assertSame(15, $list->viewData('rows')->perPage());
        $this->assertSame(50, $list->set('perPage', 50)->viewData('rows')->perPage());
        $this->assertSame(15, $list->set('perPage', 7)->viewData('rows')->perPage());
    }

    public function test_export_has_exactly_the_visible_columns_filters_search_and_sort_and_totals_stay(): void
    {
        [$a, $b] = $this->fixture();
        $page = $this->page()->call('setColumns', ['number', 'amount', 'status', 'beneficiary'])->call('sortBy', 'amount')->call('sortBy', 'amount');
        $page->assertSeeHtml("exportTable('xlsx')")->assertSeeHtml("exportTable('csv')");
        $total = $page->viewData('rows')->total();
        $this->assertSame(3, $total);
        $totals = $page->viewData('totals')->firstWhere('currency', 'USD');

        $page->call('exportTable', 'csv');
        [$head, $rows] = $this->csv($page);
        $this->assertSame([__('hc.number'), __('finance.payments.columns.amount'), __('finance.payments.columns.status'), __('hc.beneficiary')], $head);
        $this->assertCount(3, $rows);
        $this->assertSame(['700', '300', '250'], array_column($rows, 1), 'sorted by amount, descending');
        $this->assertEquals((float) $totals->total, array_sum(array_column($rows, 1)), 'the file adds up to the page totals');

        $event = DB::table('account_audit_events')->where('event_type', 'installments.exported')->sole();
        $this->assertSame($this->account->id, (int) $event->actor_account_id);
        $this->assertSame('export:installments/format:csv/rows:3/cols:number.amount.status.beneficiary/sensitive:beneficiary', $event->decision_locator);

        // Filters, the status chip and the search narrow the file the same way; the search never appears in the address.
        $project = DB::table('projects')->where('id', $b->id)->first();
        $search = $this->page()->set('search', (string) $project->project_number)->call('toggleFilterValue', 'status', 'overdue')->call('setColumns', ['number', 'amount']);
        $expected = $search->viewData('rows')->total();
        $this->assertGreaterThan(0, $expected);
        $search->call('exportTable', 'csv');
        $url = $search->effects['redirect'];
        $this->assertStringNotContainsString((string) $project->project_number, parse_url($url, PHP_URL_QUERY) ?? '');
        [, $rows] = $this->csv($search);
        $this->assertCount($expected, $rows);
        $this->get($url)->assertNotFound();

        // The default "due" tab (nothing narrowed) exports what the page lists, not every instalment.
        $due = Livewire::test(PaymentSchedule::class)->set('currency', 'USD');
        $listed = $due->viewData('rows')->total();
        $due->call('exportTable', 'csv');
        [, $rows] = $this->csv($due);
        $this->assertCount($listed, $rows);
    }

    public function test_export_stays_in_the_permitted_cities_and_needs_finance_export(): void
    {
        [$a, $b] = $this->fixture();
        $damascus = app(AccountProvisioner::class)->createPlain('test.fin-one-city', 'test.fin-one-city@hcsyria.org', Hash::make('a-long-test-password'), 'test:fin-one-city');
        DB::table('web_accounts')->where('id', $damascus->id)->update(['password_changed_at' => now()]);
        app(AccessRoles::class)->assign($damascus->fresh(), (int) $a->city_id, 'financial', 'test:fin-one-city');
        $this->actingAs($damascus->fresh());
        app(CityContext::class)->restrictTo([(int) $a->city_id]);
        $page = Livewire::test(PaymentSchedule::class)->set('currency', 'USD')->call('show', 'unpaid')->call('toggleCity', (int) $b->city_id)->call('setColumns', ['number', 'amount']);
        $page->call('exportTable', 'csv');
        [, $rows] = $this->csv($page);
        $this->assertSame(2, count($rows), 'only the permitted city\'s unpaid instalments');

        // A token is bound to its account.
        $page->call('exportTable', 'csv');
        $url = $page->effects['redirect'];
        $this->actingAs($this->account);
        $this->get($url)->assertNotFound();

        // Legal reads the schedule but has no finance.export: no buttons, the action and the route are refused and the refusal audited.
        $legal = WebAccount::where('username', 'role.legal')->firstOrFail();
        $this->actingAs($legal);
        app(CityContext::class)->restrictTo(app(PaymentScheduleService::class)->cities($legal)->pluck('id'));
        $denied = Livewire::test(PaymentSchedule::class)->assertDontSeeHtml('exportTable(');
        $this->assertFalse($denied->viewData('columnPicker')['can_export']);
        $denied->call('exportTable', 'csv')->assertForbidden();
        $token = str_repeat('b', 40);
        Cache::put('dataset-export:'.$token, ['account' => $legal->id, 'component' => PaymentSchedule::class, 'format' => 'csv',
            'state' => [], 'columns' => ['number'], 'sort' => []], now()->addMinute());
        $this->get(route('payments.export', ['locale' => 'ar', 'token' => $token]))->assertForbidden();
        $this->assertDatabaseHas('account_audit_events', ['actor_account_id' => $legal->id, 'event_type' => 'access.denied', 'outcome' => 'denied']);
        $this->assertSame(0, DB::table('account_audit_events')->where('actor_account_id', $legal->id)->where('event_type', 'installments.exported')->count());
    }
}
