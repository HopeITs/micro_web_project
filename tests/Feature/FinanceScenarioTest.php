<?php

namespace Tests\Feature;

use App\Livewire\PaymentSchedule;
use App\Livewire\ProjectFinance;
use App\Models\WebAccount;
use App\Services\Finance\Money;
use App\Services\Lifecycle\LifecycleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M4.9 user scenarios: the financial user records a loan, creates and edits the instalment schedule and pays it until the project is
 * finished; other roles read or are refused; deletions follow the owner decisions (unpaid instalment by finance, loan by the
 * administrator while nothing is paid); another city and a stale page are refused; the payment schedule lists what is due.
 */
class FinanceScenarioTest extends TestCase
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

    /** An accepted project with a beneficiary, a donor, its follow-up tasks and no loan or notices. */
    private function project(int $skip = 0): object
    {
        $project = DB::table('projects')->whereExists(fn ($q) => $q->selectRaw('1')->from('project_applicants as a')->whereColumn('a.project_id', 'projects.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('loans as l')->whereColumn('l.project_id', 'projects.id'))->orderBy('id')->skip($skip)->first();
        DB::table('project_state_history')->where('project_id', $project->id)->delete();
        DB::table('project_tasks')->where('project_id', $project->id)->delete();
        DB::table('web_notices')->where('project_id', $project->id)->delete();
        DB::table('projects')->where('id', $project->id)->update(['donor_id' => DB::table('donors')->orderBy('id')->value('id'), 'donor_group_id' => null]);
        $project = DB::table('projects')->where('id', $project->id)->first();
        app(LifecycleService::class)->recordInitial($project, 'Accepted');
        app(LifecycleService::class)->assignTasks($project, 'f');

        return $project;
    }

    private function current(int $projectId): ?string
    {
        return DB::table('project_state_history as h')->join('project_states as s', 's.id', '=', 'h.project_state_id')
            ->where('h.project_id', $projectId)->where('h.is_current', true)->value('s.name_en');
    }

    /** Records a 1,000 USD loan, 300 per instalment, and saves the generated schedule (300, 300, 300, 100). */
    private function loanWithSchedule(object $project): void
    {
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])
            ->set('loanForm.currency', 'USD')->set('loanForm.amount', '1000')->set('loanForm.returned_amount', '1,000')->set('loanForm.installment_amount', '300')
            ->set('loanForm.taken_on', '2026-01-10')->call('saveLoan')->assertHasNoErrors();
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->call('generateSchedule')->assertHasNoErrors()->call('savePayments')->assertHasNoErrors();
    }

    private function installments(int $projectId)
    {
        return DB::table('loan_installments as i')->join('loans as l', 'l.id', '=', 'i.loan_id')->where('l.project_id', $projectId)
            ->whereNull('i.deleted_at')->whereNull('l.deleted_at')->orderBy('i.due_on')->get(['i.*']);
    }

    public function test_financial_records_a_loan_builds_the_schedule_and_pays_it_to_finished(): void
    {
        $this->as('role.financial');
        $project = $this->project();
        $this->get('/ar/projects/'.$project->id)->assertOk()->assertSee(route('finance.show', ['locale' => 'ar', 'project' => $project->id]));
        $this->get('/ar/applications/'.$project->id.'/finance')->assertOk()->assertSeeLivewire(ProjectFinance::class)->assertSee(__('finance.loan.save', [], 'ar'));
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'finance.view')->where('subject_id', $project->id)->exists());

        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])
            ->set('loanForm.amount', '1000')->set('loanForm.returned_amount', '1000')->set('loanForm.installment_amount', '300')->set('loanForm.taken_on', '2026-01-10')
            ->call('saveLoan')->assertHasNoErrors()->assertSee(__('finance.loan.saved', [], 'ar'));
        $loan = DB::table('loans')->where('project_id', $project->id)->first();
        $this->assertSame('USD', $loan->currency);
        $this->assertSame('3.3', Money::round((string) $loan->installments_count, 1));
        $this->assertSame('Financed', $this->current($project->id));
        $task = DB::table('project_tasks as t')->join('task_definitions as d', 'd.id', '=', 't.task_definition_id')->where('t.project_id', $project->id)->where('d.name_ar', 'تسليم كامل المبلغ و تحديد الأقساط')->value('t.state');
        $this->assertSame(1, (int) $task, 'The loan task is ticked.');
        $this->assertGreaterThan(0, DB::table('web_notices')->where('project_id', $project->id)->where('kind', 'visit')->whereNull('account_id')->count(), 'Visit notices are created.');
        $event = DB::table('account_audit_events')->where('event_type', 'finance.loan_saved')->latest('id')->first();
        $this->assertStringContainsString('"field":"amount"', (string) $event->details);

        // The generator proposes four monthly rows from a month after the loan; the last takes the remainder. A row is edited before saving.
        $page = Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->assertSet('generate.count', '4')->assertSet('generate.first_due', '2026-02-10')
            ->call('generateSchedule')->assertHasNoErrors();
        $this->assertSame(['300', '300', '300', '100'], array_column($page->get('rows'), 'amount'));
        $this->assertSame('2026-05-10', $page->get('rows')[3]['due_on']);
        $page->set('rows.3.note', 'الدفعة الأخيرة')->call('savePayments')->assertHasNoErrors();
        $rows = $this->installments($project->id);
        $this->assertCount(4, $rows);
        $this->assertSame('الدفعة الأخيرة', $rows[3]->note);
        $financial = DB::table('account_city_roles')->where('city_id', $project->city_id)->where('role', 'financial')->whereNull('revoked_at')->count();
        $this->assertSame(4 * $financial, DB::table('web_notices')->where('kind', 'payment')->whereIn('loan_installment_id', $rows->pluck('id'))->count());
        // A second generation is refused once rows are saved.
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->call('generateSchedule')->assertHasErrors('generate.count');

        // Paying the first three leaves 100 (below one and a half instalments) but the last row is unpaid: still Financed.
        $page = Livewire::test(ProjectFinance::class, ['projectId' => $project->id]);
        foreach ([0, 1, 2] as $i) {
            $page->set("rows.$i.is_paid", true);
        }
        $page->set('rows.0.received_on', '2026-02-12')->call('savePayments')->assertHasNoErrors();
        $this->assertSame('Financed', $this->current($project->id));
        $first = $this->installments($project->id)->first();
        $this->assertSame('2026-02-12', substr((string) $first->received_on, 0, 10));
        $this->assertSame(0, DB::table('web_notices')->where('loan_installment_id', $first->id)->where('seen', false)->count(), 'A paid instalment\'s notices are seen.');

        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->set('rows.3.is_paid', true)->call('savePayments')->assertHasNoErrors();
        $this->assertSame('Finished', $this->current($project->id));
        $this->assertSame(1, DB::table('web_notices')->where('project_id', $project->id)->where('kind', 'clearance')->count());
        $this->assertSame('2026-05-11', (string) DB::table('web_notices')->where('project_id', $project->id)->where('kind', 'clearance')->value('notice_date'));
        $this->assertGreaterThan(0, DB::table('web_notices')->where('project_id', $project->id)->where('kind', 'trophy')->count());
        $event = DB::table('account_audit_events')->where('event_type', 'finance.payments_saved')->latest('id')->first();
        $this->assertStringContainsString('"field":"state"', (string) $event->details);

        // Saving again does not finish twice.
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->set('rows.3.note', 'تم')->call('savePayments')->assertHasNoErrors();
        $this->assertSame(1, DB::table('project_state_history')->where('project_id', $project->id)->whereIn('project_state_id', DB::table('project_states')->where('name_en', 'Finished')->select('id'))->count());
    }

    public function test_invalid_amounts_and_an_unfunded_project_are_refused(): void
    {
        $this->as('role.financial');
        $project = $this->project();
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->set('loanForm.amount', '0')->call('saveLoan')->assertHasErrors('loanForm.amount');
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->set('loanForm.amount', '10')->set('loanForm.installment_amount', '1.234')->call('saveLoan')
            ->assertHasErrors('loanForm.installment_amount');
        $this->assertSame(0, DB::table('loans')->where('project_id', $project->id)->count());

        DB::table('project_state_history')->where('project_id', $project->id)->delete();
        app(LifecycleService::class)->recordInitial($project, 'Under Review');
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->set('loanForm.amount', '10')->call('saveLoan')->assertHasErrors('loan');
        $this->assertSame(0, DB::table('loans')->where('project_id', $project->id)->count());
    }

    public function test_choosing_a_group_sets_its_donor(): void
    {
        $this->as('role.financial');
        $project = $this->project();
        $group = DB::table('donor_groups')->where('donor_id', '<>', $project->donor_id)->first();
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->set('donorForm.group_id', (string) $group->id)->call('saveDonor')->assertHasNoErrors();
        $row = DB::table('projects')->where('id', $project->id)->first();
        $this->assertSame((int) $group->donor_id, (int) $row->donor_id);
        $this->assertSame((int) $group->id, (int) $row->donor_group_id);
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->set('donorForm.donor_id', '')->set('donorForm.group_id', '')->call('saveDonor')->assertHasErrors('donorForm.donor_id');
    }

    public function test_data_entry_and_guest_are_refused_and_legal_only_reads(): void
    {
        $this->as('role.financial');
        $project = $this->project();
        $this->loanWithSchedule($project);

        foreach (['role.data-entry', 'role.guest'] as $user) {
            $this->as($user);
            $this->get('/ar/applications/'.$project->id.'/finance')->assertForbidden();
            $this->get('/ar/payments')->assertForbidden();
            $this->get('/ar/projects/'.$project->id)->assertOk()->assertDontSee(route('finance.show', ['locale' => 'ar', 'project' => $project->id]));
        }
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'access.denied')->exists());

        // Legal holds finance.view only: it reads the page, cannot write.
        $this->as('role.legal');
        $this->get('/en/applications/'.$project->id.'/finance')->assertOk()->assertSee(__('finance.read_only', [], 'en'))->assertDontSee(__('finance.loan.save', [], 'en'));
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->set('rows.0.is_paid', true)->call('savePayments')->assertForbidden();
        $this->assertSame(0, $this->installments($project->id)->where('is_paid', true)->count());
    }

    public function test_financial_deletes_an_unpaid_instalment_but_not_a_paid_one_nor_the_loan(): void
    {
        $this->as('role.financial');
        $project = $this->project();
        $this->loanWithSchedule($project);
        $rows = $this->installments($project->id);
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->set('rows.0.is_paid', true)->call('savePayments')->assertHasNoErrors();

        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->call('deleteInstallment', $rows[3]->id)->assertHasNoErrors();
        $this->assertNotNull(DB::table('loan_installments')->where('id', $rows[3]->id)->value('deleted_at'), 'Soft deleted.');
        $this->assertSame(0, DB::table('web_notices')->where('loan_installment_id', $rows[3]->id)->count());
        $event = DB::table('account_audit_events')->where('event_type', 'finance.installment_deleted')->latest('id')->first();
        $this->assertStringContainsString('"field":"installment"', (string) $event->details);

        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->call('deleteInstallment', $rows[0]->id)->assertHasErrors('rows');
        $this->assertNull(DB::table('loan_installments')->where('id', $rows[0]->id)->value('deleted_at'));
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->call('deleteLoan')->assertForbidden();
        $this->assertSame(1, DB::table('loans')->where('project_id', $project->id)->whereNull('deleted_at')->count());
    }

    public function test_administrator_deletes_a_loan_without_paid_instalments_and_the_project_is_accepted_again(): void
    {
        $this->as('role.financial');
        $project = $this->project();
        $this->loanWithSchedule($project);
        $this->assertSame('Financed', $this->current($project->id));

        $this->as('role.administrator');
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->assertSee(__('finance.loan.delete', [], 'ar'))->call('deleteLoan')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(0, DB::table('loans')->where('project_id', $project->id)->whereNull('deleted_at')->count());
        $this->assertSame(1, DB::table('loans')->where('project_id', $project->id)->whereNotNull('deleted_at')->count(), 'Soft deleted.');
        $this->assertSame(0, $this->installments($project->id)->count());
        $this->assertSame('Accepted', $this->current($project->id));
        $this->assertSame(0, DB::table('web_notices')->where('project_id', $project->id)->whereIn('kind', ['payment', 'visit'])->count());
        $event = DB::table('account_audit_events')->where('event_type', 'finance.loan_deleted')->latest('id')->first();
        $this->assertStringContainsString('"field":"state"', (string) $event->details);
        $this->get('/ar/projects/'.$project->id)->assertOk()->assertSee(__('hc.no_loan', [], 'ar'));

        // A new loan can be recorded again; once an instalment is paid the administrator cannot delete it.
        $this->as('role.financial');
        $this->loanWithSchedule($project);
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->set('rows.0.is_paid', true)->call('savePayments')->assertHasNoErrors();
        $this->as('role.administrator');
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->call('deleteLoan')->assertHasErrors('loan');
        $this->assertSame(1, DB::table('loans')->where('project_id', $project->id)->whereNull('deleted_at')->count());
    }

    public function test_another_city_is_refused(): void
    {
        $account = $this->as('role.financial');
        $project = $this->project();
        DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', $project->city_id)->update(['revoked_at' => now()]);
        $this->get('/ar/applications/'.$project->id.'/finance')->assertNotFound();
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->assertNotFound();
    }

    public function test_a_stale_page_is_refused(): void
    {
        $this->as('role.financial');
        $project = $this->project();
        $stale = Livewire::test(ProjectFinance::class, ['projectId' => $project->id]);
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->set('loanForm.amount', '500')->call('saveLoan')->assertHasNoErrors();
        $stale->set('loanForm.amount', '700')->call('saveLoan')->assertHasErrors('version');
        $this->assertSame('500', Money::canonical(DB::table('loans')->where('project_id', $project->id)->value('amount')));
    }

    public function test_payment_schedule_opens_on_what_is_due_and_the_tiles_filter_it(): void
    {
        $this->as('role.financial');
        $project = $this->project();
        $this->loanWithSchedule($project);
        // The sample data's loans are in SYP; this one is the only USD loan.
        $listed = fn ($page) => $page->viewData('rows')->getCollection()->where('project_id', $project->id)->count();

        $this->get('/ar/payments')->assertOk()->assertSeeLivewire(PaymentSchedule::class)->assertSee(__('finance.payments.tiles.overdue', [], 'ar'));
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'finance.list')->exists());
        $page = Livewire::test(PaymentSchedule::class)->set('currency', 'USD');
        $this->assertSame(4, $listed($page), 'The default view lists the unpaid instalments due by the month end, overdue included.');
        $this->assertSame(4, $page->viewData('tiles')['overdue']);
        $this->assertSame('1000', $page->viewData('totals')->firstWhere('currency', 'USD')->total);
        $this->assertSame(1, $page->viewData('totals')->count(), 'Totals stay per currency.');

        // Paid this month: nothing of this project yet; paying one today lists it.
        $this->assertSame(0, $listed(Livewire::test(PaymentSchedule::class)->set('currency', 'USD')->call('show', 'paid_month')));
        Livewire::test(ProjectFinance::class, ['projectId' => $project->id])->set('rows.0.is_paid', true)->set('rows.0.received_on', now()->toDateString())->call('savePayments')->assertHasNoErrors();
        $paid = Livewire::test(PaymentSchedule::class)->set('currency', 'USD')->call('show', 'paid_month');
        $this->assertSame(1, $listed($paid));
        $this->assertSame(1, $paid->viewData('tiles')['paid_month']);
        $this->assertSame(3, $listed(Livewire::test(PaymentSchedule::class)->set('currency', 'USD')));
        $this->assertSame(0, $listed(Livewire::test(PaymentSchedule::class)->set('currency', 'SYP')->call('show', 'paid_month')));

        $this->get('/ar/payments/export?format=csv&view=unpaid')->assertOk();
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'reports.exported')->exists());
        // Legal reads the schedule but has no finance.export.
        $this->as('role.legal');
        $this->get('/ar/payments')->assertOk();
        $this->get('/ar/payments/export?format=csv')->assertForbidden();
    }
}
