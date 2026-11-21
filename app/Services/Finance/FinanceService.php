<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Lifecycle\LifecycleService;
use App\Services\Staff\ProjectNotices;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Donor, loan and instalments of one project (M4.9, desktop Loans_Form and the AdminPanel donor rules) on the unified schema: `projects`
 * (donor_id, donor_group_id), `donors`, `donor_groups`, `loans`, `loan_installments`, `project_tasks`, `web_notices`, with the project
 * states through LifecycleService.
 *
 * Reading needs `applications.view` and `finance.view` in the project's city (another city: 404), writing `finance.manage`. Owner
 * decisions (21 Nov 2026): the schedule is generated from the instalment amount, count and first due date and stays editable; the
 * financial user deletes an unpaid instalment only; only the administrator (here: `finance.manage` with `lifecycle.manage`, which only
 * that role holds) deletes a loan, and only while none of its instalments is paid, which sends the project back to Accepted and removes
 * the notices the loan created. Deletions are soft and audited with details. Every write runs under the project row lock and carries the
 * version of what the page showed.
 */
final class FinanceService
{
    /** States in which a project is funded: the donor is mandatory, and a loan may be recorded (AdminPanel_From:1335–1383). */
    public const FUNDED = [LifecycleService::ACCEPTED, LifecycleService::FINANCED, LifecycleService::FINISHED, LifecycleService::CANCELED, LifecycleService::CLOSED];

    public const LOAN_TASK = 'تسليم كامل المبلغ و تحديد الأقساط';

    /** Stored currency codes (the desktop's SP is stored as SYP since the unified schema). */
    public const CURRENCIES = ['USD', 'SYP'];

    /** Rows the generator and the grid accept for one loan. */
    public const MAX_ROWS = 300;

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit, private LifecycleService $lifecycle, private ProjectNotices $projectNotices) {}

    // ---------------------------------------------------------------- access

    /** The project, readable with applications.view + finance.view in its city (another city: 404, as the rest of the site). */
    public function project(WebAccount $account, int $projectId): object
    {
        $project = $this->lifecycle->project($account, $projectId);
        if (! $this->access->check($account, (int) $project->city_id, 'finance.view')) {
            $this->audit->record($account->id, 'access.denied', 'denied', (int) $project->city_id);
            abort(403);
        }

        return $project;
    }

    public function canManage(WebAccount $account, int $city): bool
    {
        return $this->access->check($account, $city, 'finance.manage');
    }

    /** Deleting a loan is the administrator's (owner decision 2): finance.manage together with lifecycle.manage. */
    public function canDeleteLoan(WebAccount $account, int $city): bool
    {
        return $this->canManage($account, $city) && $this->access->check($account, $city, 'lifecycle.manage');
    }

    /** Refuses (and records the refusal) before any transaction, so the audit row is not rolled back. */
    private function writable(WebAccount $account, int $projectId, bool $deleteLoan = false): object
    {
        $project = $this->project($account, $projectId);
        $city = (int) $project->city_id;
        if (! ($deleteLoan ? $this->canDeleteLoan($account, $city) : $this->canManage($account, $city))) {
            $this->audit->record($account->id, 'access.denied', 'denied', $city);
            abort(403);
        }

        return $project;
    }

    // ---------------------------------------------------------------- reading

    /** The page itself was opened (audited once per visit, not on every Livewire round trip). */
    public function opened(WebAccount $account, int $projectId): object
    {
        $project = $this->project($account, $projectId);
        $this->audit->record($account->id, 'finance.view', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId);

        return $project;
    }

    public function loan(int $projectId): ?object
    {
        return DB::table('loans')->where('project_id', $projectId)->whereNull('deleted_at')->orderBy('id')->first();
    }

    /** The loan's instalments in schedule order (due date, then entry order). */
    public function installments(?object $loan): Collection
    {
        if ($loan === null) {
            return collect();
        }

        return DB::table('loan_installments')->where('loan_id', $loan->id)->whereNull('deleted_at')->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')->orderBy('due_on')->orderBy('id')->get()
            ->map(function ($row) {
                $row->amount = Money::canonical($row->amount) ?? '0';
                $row->is_paid = (bool) $row->is_paid;
                $row->due_on = Fields::day($row->due_on);
                $row->received_on = Fields::day($row->received_on);

                return $row;
            });
    }

    /** A fingerprint of what the page showed: donor, group, state history, the loan and its instalments. */
    public function version(object $project, Collection $history, ?object $loan, Collection $installments): string
    {
        $loanRow = $loan === null ? null : collect((array) $loan)->except(['created_at', 'updated_at'])->map(fn ($v) => $v === null ? null : (string) $v)->all();

        return hash('sha256', json_encode([
            $project->donor_id, $project->donor_group_id,
            $history->map(fn ($r) => [$r->id, $r->project_state_id, (bool) $r->is_current])->all(), $loanRow,
            $installments->map(fn ($r) => [$r->id, $r->amount, $r->due_on, (bool) $r->is_paid, $r->received_on, $r->note])->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /** Read model of the finance page. */
    public function panel(WebAccount $account, int $projectId): array
    {
        $project = $this->project($account, $projectId);
        $city = (int) $project->city_id;
        $history = $this->lifecycle->history($projectId);
        $current = $this->lifecycle->current($history);
        $loan = $this->loan($projectId);
        $installments = $this->installments($loan);
        $canManage = $this->canManage($account, $city);
        $funded = $current !== null && in_array($current->state_en, self::FUNDED, true);
        $donor = $project->donor_id === null ? null : DB::table('donors')->where('id', $project->donor_id)->first(['id', 'name_ar', 'name_en']);
        $group = $project->donor_group_id === null ? null : DB::table('donor_groups')->where('id', $project->donor_group_id)->first(['id', 'name_ar', 'name_en', 'donor_id']);

        return [
            'project' => $project, 'current' => $current, 'funded' => $funded, 'donor' => $donor, 'group' => $group,
            'ineligible' => $this->ineligible($current, $project->donor_id),
            'canManage' => $canManage, 'canDeleteLoan' => $this->canDeleteLoan($account, $city),
            'loan' => $loan, 'installments' => $installments, 'model' => $loan === null ? null : $this->model($loan, $installments),
            'version' => $this->version($project, $history, $loan, $installments),
            'visitNoticesMissing' => $loan !== null && ! $this->projectNotices->hasVisitNotices($project),
            'donors' => $canManage ? $this->donors($project) : collect(),
            'groups' => $canManage ? DB::table('donor_groups')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $project->donor_group_id ?? 0))
                ->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en', 'donor_id']) : collect(),
        ];
    }

    /** @return array{values: array, summary: array, opposite: ?string, last_paid: bool} */
    public function model(object $loan, Collection $installments): array
    {
        $values = [
            'amount' => Money::canonical($loan->amount) ?? '0', 'rate_exchange' => Money::canonical($loan->exchange_rate) ?? '0',
            'returned' => Money::canonical($loan->returned_amount) ?? '0', 'payment' => Money::canonical($loan->installment_amount) ?? '0',
        ];

        return [
            'values' => $values,
            'summary' => LoanMath::summary($values['returned'], $values['payment'], $installments->map(fn ($r) => ['amount' => $r->amount, 'paid' => $r->is_paid])->all()),
            'opposite' => Money::isZero($values['rate_exchange']) ? null : LoanMath::opposite((string) $loan->currency, $values['amount'], $values['rate_exchange']),
            'last_paid' => $installments->isNotEmpty() && $installments->last()->is_paid,
        ];
    }

    /** Active donors, plus the project's own one even when hidden since. */
    private function donors(object $project): Collection
    {
        return DB::table('donors')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $project->donor_id ?? 0))->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']);
    }

    /** Why a loan or instalment write is refused (a finance.errors.ineligible_* key), or null. */
    private function ineligible(?object $current, mixed $donorId): ?string
    {
        if ($current === null || ! in_array($current->state_en, self::FUNDED, true)) {
            return 'state';
        }

        return $donorId === null ? 'donor' : null;
    }

    // ---------------------------------------------------------------- helpers for writes

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => __('finance.errors.'.$key, $replace)]);
    }

    /** Locks the project row, re-reads it and refuses a stale page. Returns [project, history, current, loan, installments]. */
    private function begin(int $projectId, string $version): array
    {
        $project = DB::table('projects')->where('id', $projectId)->lockForUpdate()->first();
        $history = $this->lifecycle->history($projectId);
        $loan = $this->loan($projectId);
        $installments = $this->installments($loan);
        if (! hash_equals($this->version($project, $history, $loan, $installments), $version)) {
            $this->fail('version', 'stale');
        }

        return [$project, $history, $this->lifecycle->current($history), $loan, $installments];
    }

    private function name(?string $table, mixed $id): ?string
    {
        return $id === null ? null : DB::table($table)->where('id', $id)->value('name_ar');
    }

    private function stateName(string $nameEn): ?string
    {
        return DB::table('project_states')->where('name_en', $nameEn)->value('name_ar');
    }

    // ---------------------------------------------------------------- donor

    /**
     * Sets the project's donor and group. Choosing a group sets the donor to the group's donor (desktop). A funded project keeps a donor;
     * any other project cannot be given one here.
     */
    public function saveDonor(WebAccount $account, int $projectId, ?int $donorId, ?int $groupId, string $version): void
    {
        $this->writable($account, $projectId);
        DB::transaction(function () use ($account, $projectId, $donorId, $groupId, $version) {
            [$project, , $current] = $this->begin($projectId, $version);
            $funded = $current !== null && in_array($current->state_en, self::FUNDED, true);
            if ($groupId !== null) {
                $group = DB::table('donor_groups')->where('id', $groupId)->first(['id', 'donor_id']);
                $group === null && $this->fail('group_id', 'group_missing');
                $donorId = (int) $group->donor_id;
            }
            if ($donorId === null) {
                $funded && $this->fail('donor_id', 'donor_required');
            } else {
                $funded || $this->fail('donor_id', 'donor_not_allowed');
                DB::table('donors')->where('id', $donorId)->exists() || $this->fail('donor_id', 'donor_missing');
            }
            $details = AccountAuditTrail::changes(
                ['donor' => $this->name('donors', $project->donor_id), 'donor_group' => $this->name('donor_groups', $project->donor_group_id)],
                ['donor' => $this->name('donors', $donorId), 'donor_group' => $this->name('donor_groups', $groupId)],
            );
            if ($details === null) {
                return;
            }
            DB::table('projects')->where('id', $projectId)->update(['donor_id' => $donorId, 'donor_group_id' => $groupId, 'updated_at' => now()]);
            $this->audit->record($account->id, 'finance.donor_assigned', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId, details: $details);
        });
    }

    // ---------------------------------------------------------------- loan

    /**
     * Records or edits the project's loan. $input: currency, exchange_rate, amount, taken_on, returned_amount, installment_amount, note,
     * receipt_number. The first loan moves an Accepted project to Financed, ticks the loan task when assigned and generates the visit
     * notices. Returns the loan id.
     */
    public function saveLoan(WebAccount $account, int $projectId, array $input, string $version): int
    {
        $this->writable($account, $projectId);

        return DB::transaction(function () use ($account, $projectId, $input, $version) {
            [$project, , $current, $loan] = $this->begin($projectId, $version);
            $refusal = $this->ineligible($current, $project->donor_id);
            if ($refusal !== null) {
                $this->fail('loan', 'ineligible_'.$refusal);
            }
            $payload = $this->loanPayload($input);
            $now = now();
            $details = AccountAuditTrail::changes($loan ?? [], $payload) ?? [];
            if ($loan === null) {
                $loanId = (int) DB::table('loans')->insertGetId($payload + ['city_id' => $project->city_id, 'project_id' => $projectId, 'created_at' => $now, 'updated_at' => $now]);
                if ($current->state_en === LifecycleService::ACCEPTED && $this->lifecycle->enterNamed($project, LifecycleService::FINANCED)) {
                    $details[] = ['field' => 'state', 'before' => $current->state_ar, 'after' => $this->stateName(LifecycleService::FINANCED)];
                }
                if ($this->completeLoanTask($project)) {
                    $details[] = ['field' => 'task', 'before' => null, 'after' => self::LOAN_TASK];
                }
                $visits = $this->projectNotices->generateVisitSchedule($project, $payload['taken_on'], $account->id);
                if ($visits > 0) {
                    $details[] = ['field' => 'visit_notices', 'before' => null, 'after' => $visits];
                }
            } else {
                $loanId = (int) $loan->id;
                if ($details === []) {
                    return $loanId;
                }
                DB::table('loans')->where('id', $loanId)->update($payload + ['updated_at' => $now]);
            }
            $this->audit->record($account->id, 'finance.loan_saved', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId, details: $details);

            return $loanId;
        });
    }

    /** Validated loan columns, with the instalment count and repayment rate derived as the desktop (one decimal). */
    private function loanPayload(array $input): array
    {
        $get = fn (string $key) => is_string($input[$key] ?? null) ? trim($input[$key]) : (is_numeric($input[$key] ?? null) ? (string) $input[$key] : '');
        $currency = $get('currency');
        in_array($currency, self::CURRENCIES, true) || $this->fail('currency', 'currency');
        $date = $get('taken_on') === '' ? now()->toDateString() : $get('taken_on');
        Fields::isDate($date) || $this->fail('taken_on', 'date');
        $money = function (string $key, int $scale, int $digits, bool $required = false) use ($get): string {
            $value = Money::normalize($get($key));
            $value = $value === '' ? '0' : $value;
            if (! Money::isDecimal($value, $scale) || strlen(explode('.', $value)[0]) > $digits || ($required && Money::isZero($value))) {
                $this->fail($key, $key);
            }

            return Money::canonical($value) ?? '0';
        };
        $amount = $money('amount', 2, 13, true);
        $rate = $money('exchange_rate', 4, 11);
        $returned = $money('returned_amount', 2, 13);
        $payment = $money('installment_amount', 2, 13);
        $derived = LoanMath::derived($amount, $returned, $payment);
        if (bccomp($derived['count'], '999999', 1) > 0 || bccomp($derived['rate'], '9999', 1) > 0) {
            $this->fail('installment_amount', 'ratio');
        }
        $note = $get('note');
        mb_strlen($note) <= 65535 || $this->fail('note', 'note');
        $receipt = $get('receipt_number');
        mb_strlen($receipt) <= 50 || $this->fail('receipt_number', 'receipt');

        return [
            'currency' => $currency, 'exchange_rate' => $rate, 'amount' => $amount, 'taken_on' => $date, 'returned_amount' => $returned, 'installment_amount' => $payment,
            'installments_count' => $derived['count'], 'rate' => $derived['rate'], 'note' => $note === '' ? null : $note, 'receipt_number' => $receipt === '' ? null : $receipt,
        ];
    }

    /** `Update_Task_MicroProject`: ticks the project's existing assignment of the loan task; nothing when it is not assigned or already done. */
    private function completeLoanTask(object $project): bool
    {
        $task = DB::table('project_tasks as t')->join('task_definitions as d', 'd.id', '=', 't.task_definition_id')->where('t.project_id', $project->id)
            ->whereNull('t.deleted_at')->where('d.name_ar', self::LOAN_TASK)->where(fn ($q) => $q->whereNull('t.state')->orWhere('t.state', '<>', 1))->value('t.id');
        if ($task === null) {
            return false;
        }
        DB::table('project_tasks')->where('id', $task)->update(['state' => 1, 'task_date' => now(), 'updated_at' => now()]);

        return true;
    }

    /** Soft-deletes the loan and its instalments (administrator, none paid); the project goes back to Accepted and the loan's notices go. */
    public function deleteLoan(WebAccount $account, int $projectId, string $version): void
    {
        $this->writable($account, $projectId, deleteLoan: true);
        DB::transaction(function () use ($account, $projectId, $version) {
            [$project, , $current, $loan, $installments] = $this->begin($projectId, $version);
            $loan === null && $this->fail('loan', 'no_loan');
            $installments->contains(fn ($r) => $r->is_paid) && $this->fail('loan', 'loan_has_paid');
            $now = now();
            DB::table('loan_installments')->where('loan_id', $loan->id)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
            DB::table('loans')->where('id', $loan->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            $removed = $this->projectNotices->removeLoan($project, $installments);
            $details = [
                ['field' => 'loan', 'before' => Money::canonical($loan->amount).' '.$loan->currency.' · '.Fields::day($loan->taken_on), 'after' => null],
                ['field' => 'installments', 'before' => $installments->count(), 'after' => 0],
            ];
            if ($removed > 0) {
                $details[] = ['field' => 'notices_removed', 'before' => null, 'after' => $removed];
            }
            if ($current !== null && $current->state_en !== LifecycleService::ACCEPTED && $this->lifecycle->enterNamed($project, LifecycleService::ACCEPTED)) {
                $details[] = ['field' => 'state', 'before' => $current->state_ar, 'after' => $this->stateName(LifecycleService::ACCEPTED)];
            }
            $this->audit->record($account->id, 'finance.loan_deleted', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId, details: $details);
        });
    }

    // ---------------------------------------------------------------- instalments

    /**
     * Generated schedule (owner decision 1): `$count` monthly rows from `$firstDue`, each the instalment amount, the last one the
     * remainder so the rows add up to the returned amount. Nothing is stored: the rows are edited and saved with the grid.
     *
     * @return list<array{id: null, amount: string, due_on: string, is_paid: bool, received_on: string, note: string}>
     */
    public static function schedule(string $returned, string $payment, int $count, string $firstDue): array
    {
        if (Money::isZero($returned) || Money::isZero($payment) || bccomp($payment, '0', 2) < 0) {
            throw ValidationException::withMessages(['generate.count' => __('finance.errors.generate_amounts')]);
        }
        if ($count < 1 || $count > self::MAX_ROWS) {
            throw ValidationException::withMessages(['generate.count' => __('finance.errors.generate_count', ['max' => self::MAX_ROWS])]);
        }
        if (! Fields::isDate($firstDue)) {
            throw ValidationException::withMessages(['generate.first_due' => __('finance.errors.date')]);
        }
        $last = bcsub($returned, bcmul($payment, (string) ($count - 1), 2), 2);
        if (bccomp($last, '0', 2) <= 0) {
            throw ValidationException::withMessages(['generate.count' => __('finance.errors.generate_too_many')]);
        }
        $first = CarbonImmutable::parse($firstDue);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => null, 'amount' => Money::canonical($i === $count - 1 ? $last : $payment) ?? '0', 'due_on' => $first->addMonthsNoOverflow($i)->toDateString(),
                'is_paid' => false, 'received_on' => '', 'note' => ''];
        }

        return $rows;
    }

    /** The default instalment count for the generator: the returned amount over the instalment, rounded up. */
    public static function defaultCount(?object $loan): int
    {
        $returned = Money::canonical($loan->returned_amount ?? null) ?? '0';
        $payment = Money::canonical($loan->installment_amount ?? null) ?? '0';
        if ($loan === null || Money::isZero($returned) || Money::isZero($payment)) {
            return 0;
        }
        $whole = bcdiv($returned, $payment, 0);

        return (int) $whole + (bccomp(bcmul($whole, $payment, 2), $returned, 2) < 0 ? 1 : 0);
    }

    /**
     * Saves the grid of the loan's instalments. Each row: id?, amount, due_on, is_paid, received_on, note. Unchanged rows are not written.
     * A new unpaid row sends its payment notice; a changed row's notices follow it. A paid last row with less than one and a half
     * instalments left finishes a Financed project once and sends the clearance notices.
     */
    public function savePayments(WebAccount $account, int $projectId, array $rows, string $version): void
    {
        $this->writable($account, $projectId);
        DB::transaction(function () use ($account, $projectId, $rows, $version) {
            [$project, , $current, $loan, $existing] = $this->begin($projectId, $version);
            $loan === null && $this->fail('rows', 'no_loan');
            $refusal = $this->ineligible($current, $project->donor_id);
            if ($refusal !== null) {
                $this->fail('rows', 'ineligible_'.$refusal);
            }
            count($rows) <= self::MAX_ROWS || $this->fail('rows', 'generate_count', ['max' => self::MAX_ROWS]);
            $existing = $existing->keyBy('id');
            $details = [];
            $now = now();
            foreach (array_values($rows) as $index => $row) {
                $id = Fields::blank($row['id'] ?? null) ? null : (int) $row['id'];
                if ($id === null && Fields::blank($row['amount'] ?? null) && Fields::blank($row['due_on'] ?? null) && Fields::blank($row['note'] ?? null)) {
                    continue;
                }
                $stored = $id === null ? null : $existing->get($id);
                if ($id !== null && $stored === null) {
                    $this->fail('rows', 'row_missing');
                }
                $payload = $this->rowPayload($row, $index);
                $label = '#'.($index + 1);
                if ($stored === null) {
                    $newId = (int) DB::table('loan_installments')->insertGetId($payload + ['city_id' => $project->city_id, 'loan_id' => $loan->id, 'created_at' => $now, 'updated_at' => $now]);
                    if (! $payload['is_paid']) {
                        $this->projectNotices->paymentDue($project, (object) ['id' => $newId], $payload['amount'], $payload['due_on'], $account->id);
                    }
                    $details[] = ['field' => 'installment', 'before' => null, 'after' => $label.' '.$this->describe($payload)];
                } else {
                    $before = ['amount' => $stored->amount, 'due_on' => $stored->due_on, 'is_paid' => $stored->is_paid, 'received_on' => $stored->received_on === '' ? null : $stored->received_on, 'note' => $stored->note];
                    if (AccountAuditTrail::changes($before, $payload) === null) {
                        continue;
                    }
                    DB::table('loan_installments')->where('id', $stored->id)->update($payload + ['updated_at' => $now]);
                    $this->projectNotices->paymentChanged($stored, $payload['amount'], $payload['due_on'], $payload['is_paid']);
                    $details[] = ['field' => 'installment', 'before' => $label.' '.$this->describe($before), 'after' => $label.' '.$this->describe($payload)];
                }
            }
            if ($details === []) {
                return;
            }
            if ($this->finishWhenRepaid($project, $current, $loan, $account->id)) {
                $details[] = ['field' => 'state', 'before' => $current->state_ar, 'after' => $this->stateName(LifecycleService::FINISHED)];
            }
            $this->audit->record($account->id, 'finance.payments_saved', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId, details: $details);
        });
    }

    /** "100 · 2026-12-01 · paid 2026-12-03" for the audit details. */
    private function describe(array $row): string
    {
        return trim((Money::canonical($row['amount']) ?? '0').' · '.($row['due_on'] ?? '').' · '.($row['is_paid'] ? 'مدفوع '.($row['received_on'] ?? '') : 'غير مدفوع'));
    }

    private function rowPayload(array $row, int $index): array
    {
        $field = fn (string $key) => 'rows.'.$index.'.'.$key;
        $amount = Money::normalize(Fields::text((string) ($row['amount'] ?? '')));
        $due = trim(Fields::text((string) ($row['due_on'] ?? '')));
        $paid = in_array($row['is_paid'] ?? false, [true, 1, '1', 'on'], true);
        $received = trim(Fields::text((string) ($row['received_on'] ?? '')));
        $note = Fields::text((string) ($row['note'] ?? ''));
        (Money::isDecimal($amount, 2, true) && strlen(ltrim(explode('.', $amount)[0], '-')) <= 13) || $this->fail($field('amount'), 'payment_amount');
        Fields::isDate($due) || $this->fail($field('due_on'), 'payment_due');
        if ($received !== '' && ! Fields::isDate($received)) {
            $this->fail($field('received_on'), 'payment_received');
        }
        mb_strlen($note) <= 65535 || $this->fail($field('note'), 'note');

        // Desktop: a paid row without a received date records its due date; an unpaid row has none.
        return ['amount' => Money::canonical($amount) ?? '0', 'due_on' => $due, 'is_paid' => $paid, 'received_on' => $paid ? ($received !== '' ? $received : $due) : null, 'note' => $note === '' ? null : $note];
    }

    /** SavePayments_button_Click:1125–1145: a paid last row with less than one and a half instalments left finishes the project (once). */
    private function finishWhenRepaid(object $project, ?object $current, object $loan, int $sender): bool
    {
        if ($current?->state_en !== LifecycleService::FINANCED) {
            return false;
        }
        $fresh = DB::table('loans')->where('id', $loan->id)->first();
        $installments = $this->installments($fresh);
        $model = $this->model($fresh, $installments);
        if (! $model['last_paid'] || ! $model['summary']['near_end'] || ! $this->lifecycle->enterNamed($project, LifecycleService::FINISHED)) {
            return false;
        }
        $last = $installments->last();
        $this->projectNotices->clearance($project, $last->received_on ?: $last->due_on, $sender);

        return true;
    }

    /** Soft-deletes one unpaid instalment (owner decision 2) and its payment notices. */
    public function deleteInstallment(WebAccount $account, int $projectId, int $installmentId, string $version): void
    {
        $this->writable($account, $projectId);
        DB::transaction(function () use ($account, $projectId, $installmentId, $version) {
            [$project, , , , $installments] = $this->begin($projectId, $version);
            $row = $installments->firstWhere('id', $installmentId);
            $row === null && $this->fail('rows', 'row_missing');
            $row->is_paid && $this->fail('rows', 'installment_paid');
            $now = now();
            DB::table('loan_installments')->where('id', $row->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            $this->projectNotices->ofInstallment($row)->delete();
            $position = $installments->search(fn ($r) => $r->id === $row->id) + 1;
            $this->audit->record($account->id, 'finance.installment_deleted', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId,
                details: [['field' => 'installment', 'before' => '#'.$position.' '.$this->describe((array) $row), 'after' => null]]);
        });
    }
}
