<?php

namespace App\Livewire;

use App\Services\Finance\Fields;
use App\Services\Finance\FinanceService;
use App\Services\Finance\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The finance page of a project (M4.9): donor and group, the loan, the instalment grid (generate, add, edit, pay, delete) and the totals.
 * Every write goes through FinanceService, which rechecks the capabilities and the version of what the page showed.
 */
class ProjectFinance extends Component
{
    #[Locked]
    public int $projectId;

    public string $version = '';

    /** @var array{donor_id: string, group_id: string} */
    public array $donorForm = ['donor_id' => '', 'group_id' => ''];

    /** @var array<string, string> */
    public array $loanForm = [];

    /** @var list<array{id: ?int, amount: string, due_on: string, is_paid: bool, received_on: string, note: string}> */
    public array $rows = [];

    /** @var array{count: string, first_due: string} */
    public array $generate = ['count' => '', 'first_due' => ''];

    public string $notice = '';

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
        $this->load();
    }

    private function service(): FinanceService
    {
        return app(FinanceService::class);
    }

    /** Fills the forms from what is stored. */
    private function load(): void
    {
        $panel = $this->service()->panel(auth()->user(), $this->projectId);
        $project = $panel['project'];
        $loan = $panel['loan'];
        $this->version = $panel['version'];
        $this->donorForm = ['donor_id' => (string) ($project->donor_id ?? ''), 'group_id' => (string) ($project->donor_group_id ?? '')];
        $this->loanForm = [
            'currency' => (string) ($loan->currency ?? 'USD'), 'exchange_rate' => Money::canonical($loan->exchange_rate ?? null) ?? '',
            'amount' => Money::canonical($loan->amount ?? null) ?? '', 'taken_on' => Fields::day($loan->taken_on ?? null) ?: now()->toDateString(),
            'returned_amount' => Money::canonical($loan->returned_amount ?? null) ?? '', 'installment_amount' => Money::canonical($loan->installment_amount ?? null) ?? '',
            'note' => (string) ($loan->note ?? ''), 'receipt_number' => (string) ($loan->receipt_number ?? ''),
        ];
        $this->rows = $panel['installments']->map(fn ($r) => ['id' => (int) $r->id, 'amount' => $r->amount, 'due_on' => $r->due_on, 'is_paid' => $r->is_paid,
            'received_on' => $r->received_on, 'note' => (string) ($r->note ?? '')])->values()->all();
        $count = FinanceService::defaultCount($loan);
        $first = $loan?->taken_on ? CarbonImmutable::parse($loan->taken_on)->addMonthNoOverflow()->toDateString() : '';
        $this->generate = ['count' => $count > 0 ? (string) $count : '', 'first_due' => $first];
    }

    private function optionalId(string $value): ?int
    {
        return $value === '' ? null : (int) $value;
    }

    /** Runs a write; validation messages land on the form, a stale page on `version`. */
    private function save(callable $action, string $form, string $done): bool
    {
        $this->notice = '';
        $this->resetErrorBag();
        try {
            $action($this->service());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError(in_array($field, ['version', 'loan', 'rows'], true) || str_starts_with($field, 'rows.') || str_starts_with($field, 'generate.') ? $field : $form.'.'.$field, $messages[0]);
            }

            return false;
        }
        $this->load();
        $this->notice = __('finance.'.$done);

        return true;
    }

    public function saveDonor(): void
    {
        $this->validate(['donorForm.donor_id' => ['nullable', 'integer', 'min:1'], 'donorForm.group_id' => ['nullable', 'integer', 'min:1']]);
        $this->save(fn (FinanceService $s) => $s->saveDonor(auth()->user(), $this->projectId, $this->optionalId($this->donorForm['donor_id']), $this->optionalId($this->donorForm['group_id']), $this->version), 'donorForm', 'saved');
    }

    public function saveLoan(): void
    {
        $this->validate(['loanForm.*' => ['nullable', 'string', 'max:65535']]);
        $this->save(fn (FinanceService $s) => $s->saveLoan(auth()->user(), $this->projectId, $this->loanForm, $this->version), 'loanForm', 'loan.saved');
    }

    /** Owner decision 1: fills the grid monthly from the instalment amount, the count and the first due date; nothing is stored yet. */
    public function generateSchedule(): void
    {
        $this->resetErrorBag();
        $this->notice = '';
        if (collect($this->rows)->contains(fn ($r) => $r['id'] !== null)) {
            $this->addError('generate.count', __('finance.errors.generate_exists'));

            return;
        }
        $loan = $this->service()->loan($this->projectId);
        if ($loan === null) {
            $this->addError('generate.count', __('finance.errors.no_loan'));

            return;
        }
        $count = filter_var($this->generate['count'], FILTER_VALIDATE_INT);
        try {
            $this->rows = FinanceService::schedule(Money::canonical($loan->returned_amount) ?? '0', Money::canonical($loan->installment_amount) ?? '0', $count === false ? 0 : $count, trim($this->generate['first_due']));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->notice = __('finance.installments.generated');
    }

    public function addRow(): void
    {
        $last = end($this->rows) ?: null;
        $due = $last && Fields::isDate($last['due_on']) ? CarbonImmutable::parse($last['due_on'])->addMonthNoOverflow()->toDateString() : ($this->generate['first_due'] ?: now()->toDateString());
        $this->rows[] = ['id' => null, 'amount' => $this->loanForm['installment_amount'] ?? '', 'due_on' => $due, 'is_paid' => false, 'received_on' => '', 'note' => ''];
    }

    /** Removes a row that is not saved yet (saved rows are deleted with deleteInstallment). */
    public function removeRow(int $index): void
    {
        if (isset($this->rows[$index]) && $this->rows[$index]['id'] === null) {
            unset($this->rows[$index]);
            $this->rows = array_values($this->rows);
        }
    }

    public function savePayments(): void
    {
        $this->validate(['rows' => ['array', 'max:'.FinanceService::MAX_ROWS], 'rows.*.amount' => ['nullable', 'string', 'max:40'], 'rows.*.due_on' => ['nullable', 'string', 'max:10'],
            'rows.*.received_on' => ['nullable', 'string', 'max:10'], 'rows.*.note' => ['nullable', 'string', 'max:65535'], 'rows.*.is_paid' => ['boolean']]);
        $this->save(fn (FinanceService $s) => $s->savePayments(auth()->user(), $this->projectId, $this->rows, $this->version), 'rows', 'installments.saved');
    }

    public function deleteInstallment(int $installmentId): void
    {
        $this->save(fn (FinanceService $s) => $s->deleteInstallment(auth()->user(), $this->projectId, $installmentId, $this->version), 'rows', 'installments.deleted');
    }

    public function deleteLoan(): void
    {
        if ($this->save(fn (FinanceService $s) => $s->deleteLoan(auth()->user(), $this->projectId, $this->version), 'loanForm', 'loan.deleted')) {
            session()->flash('status', __('finance.loan.deleted'));
            $this->redirectRoute('projects.show', ['locale' => app()->getLocale(), 'project' => $this->projectId]);
        }
    }

    public function render(FinanceService $service): View
    {
        return view('livewire.project-finance', ['panel' => $service->panel(auth()->user(), $this->projectId)]);
    }
}
