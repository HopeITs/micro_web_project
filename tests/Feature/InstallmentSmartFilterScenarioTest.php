<?php

namespace Tests\Feature;

use App\Livewire\PaymentSchedule;
use App\Models\WebAccount;
use App\Services\Finance\PaymentScheduleService;
use App\Services\ProjectNumberAllocator;
use App\Support\CityContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Smart filters, search and saved views on the payment schedule (smart-search plan 2026-11, phase 1 item 5). */
class InstallmentSmartFilterScenarioTest extends TestCase
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

    /** A USD loan (the sample data is in SYP) on the nth project of the given cities, with one instalment per [due, paid] pair. */
    private function loan(int $projectId, array $instalments): void
    {
        $project = DB::table('projects')->where('id', $projectId)->first();
        $loan = DB::table('loans')->insertGetId(['city_id' => $project->city_id, 'project_id' => $projectId, 'currency' => 'USD', 'amount' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($instalments as [$due, $paid]) {
            DB::table('loan_installments')->insert(['city_id' => $project->city_id, 'loan_id' => $loan, 'amount' => 100, 'due_on' => $due, 'is_paid' => $paid,
                'received_on' => $paid ? $due : null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function projectIds(int $count): array
    {
        $ids = app(PaymentScheduleService::class)->cities($this->account)->pluck('id')->all();

        return DB::table('projects')->whereNull('deleted_at')->whereIn('city_id', $ids)->orderBy('id')->limit($count)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function number(int $projectId): string
    {
        $p = DB::table('projects')->join('cities as c', 'c.id', '=', 'projects.city_id')->where('projects.id', $projectId)->first(['c.code', 'projects.project_number']);

        return ProjectNumberAllocator::display($p->code, (int) $p->project_number);
    }

    private function fixture(): array
    {
        [$a, $b] = $this->projectIds(2);
        $past = now()->subDays(40)->toDateString();
        $soon = now()->addDays(10)->toDateString();
        $later = now()->addDays(120)->toDateString();
        $this->loan($a, [[$past, true], [now()->subDays(5)->toDateString(), false], [$soon, false]]);
        $this->loan($b, [[$later, false]]);

        return [$a, $b, $past, $soon, $later];
    }

    private function usd(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(PaymentSchedule::class)->set('currency', 'USD');
    }

    private function listed($page): int
    {
        return $page->viewData('rows')->total();
    }

    public function test_status_filter_with_several_values(): void
    {
        $this->fixture();
        $page = $this->usd()->call('toggleFilterValue', 'status', 'paid')->call('toggleFilterValue', 'status', 'overdue');
        $this->assertSame(2, $this->listed($page), 'Paid and overdue together; the default tab relaxes to every instalment.');
        $page->call('removeFilter', 'status')->call('toggleFilterValue', 'status', 'unpaid');
        $this->assertSame(2, $this->listed($page), 'Not-yet-due ones: the soon and the later instalment.');
    }

    public function test_due_date_range_and_preset(): void
    {
        [, , , $soon] = $this->fixture();
        $page = $this->usd()->call('setFilterRange', 'due_on', now()->toDateString(), now()->addDays(30)->toDateString());
        $this->assertSame(1, $this->listed($page));
        $this->assertSame($soon, $page->viewData('rows')->getCollection()->first()->due_on);
        $page = $this->usd()->call('setFilterPreset', 'due_on', 'next_30_days');
        $this->assertSame(1, $this->listed($page));
        $page = $this->usd()->call('setFilterRange', 'due_on', null, now()->subDay()->toDateString());
        $this->assertSame(2, $this->listed($page), 'Everything due before today: the paid and the overdue one.');
    }

    public function test_search_accepts_several_project_numbers(): void
    {
        [$a, $b] = $this->fixture();
        $this->assertSame(3, $this->listed($this->usd()->set('search', $this->number($a))));
        $this->assertSame(1, $this->listed($this->usd()->set('search', $this->number($b))));
        $this->assertSame(4, $this->listed($this->usd()->set('search', $this->number($a).",\n".$this->number($b))), 'Comma or new line: alternatives are OR-ed.');
    }

    public function test_city_scope_is_not_bypassed(): void
    {
        $permitted = app(PaymentScheduleService::class)->cities($this->account)->pluck('id')->all();
        $other = DB::table('projects')->whereNull('deleted_at')->whereNotIn('city_id', $permitted)->orderBy('id')->first();
        if ($other === null) {
            $this->markTestSkipped('The financial account reads finance in every city of the sample data.');
        }
        $this->loan($other->id, [[now()->subDays(3)->toDateString(), false]]);
        $page = $this->usd()->set('search', $this->number($other->id));
        $this->assertSame(0, $this->listed($page));
        $page = $this->usd()->set('cities', [(int) $other->city_id]);
        $this->assertSame(0, $page->viewData('rows')->getCollection()->where('project_id', $other->id)->count());
        $this->assertSame(0, DB::table('loan_installments')->whereIn('city_id', $permitted)->where('city_id', $other->city_id)->count());
    }

    public function test_saved_view_keeps_the_filters_but_not_the_search(): void
    {
        $this->fixture();
        $page = $this->usd()->call('toggleFilterValue', 'status', 'overdue')->set('search', 'x')->call('saveView', 'متأخرة')->assertSet('viewError', null);
        $view = \App\Models\SavedView::where('dataset', 'installments')->firstOrFail();
        $this->assertSame('overdue', $view->spec['filters'][0]['values'][0]);
        $this->assertStringNotContainsString('x', json_encode($view->spec));
        $page->call('clearFilters');
        $this->assertSame(0, count($page->get('filters')));
        $page->call('applyView', $view->id)->assertSet('search', '');
        $this->assertSame(1, $this->listed($page->set('currency', 'USD')));
    }

    public function test_export_respects_the_smart_filters(): void
    {
        $this->fixture();
        $url = '/ar/payments/export?format=csv&currency=USD&f[status][op]=in&f[status][values][]=paid';
        $csv = $this->get($url)->assertOk()->streamedContent();
        $this->assertSame(1, substr_count($csv, 'USD'), 'Only the paid instalment is exported.');
        $this->get('/ar/payments/export?format=csv&f[nope][op]=in&f[nope][values][]=1')->assertStatus(422);
    }
}
