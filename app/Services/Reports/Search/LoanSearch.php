<?php

declare(strict_types=1);

namespace App\Services\Reports\Search;

use App\Services\Finance\Money;
use App\Services\Reports\ProjectReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Loans and payments" (desktop `Search_Form.Loan_bind`, `Select.BuildLoanReportQuery` and `BuildLoanSummaryQuery`): one row per loan. Every figure
 * is per loan and in the loan's currency; the SYP and USD columns convert that single loan by its own rate. The summary panel keeps the two
 * currencies apart (no conversion, never added together).
 *
 * Per loan: paid = sum of the paid instalments; remaining = max(0, amount to return − paid); remaining instalments = max(0, amount × rate % −
 * paid) ÷ instalment (the desktop uses the stored percentage there); months late = 0 when nothing remains, else whole months from the latest
 * unpaid due date to today + 1 month (blank for cancelled or closed projects and when no instalment is unpaid).
 */
final class LoanSearch extends SearchMode
{
    public function key(): string
    {
        return 'loans';
    }

    public function group(): string
    {
        return 'money';
    }

    public function capabilities(): array
    {
        return ['finance.view', 'applications.view'];
    }

    public function exportCapabilities(): array
    {
        return ['reports.export', 'finance.export'];
    }

    public function columns(): array
    {
        return ['number', 'beneficiaries', 'mobile', 'career', 'category', 'subcategory', 'state', 'loan_date', 'currency', 'exchange_rate', 'loan_sp', 'loan_usd', 'return_rate',
            'to_return', 'installment', 'paid', 'remaining', 'total_installments', 'paid_installments', 'remaining_installments', 'first_due', 'last_paid', 'due_date',
            'months_late', 'unpaid_installments', 'note', 'donor', 'group', 'receipt'];
    }

    public function numeric(): array
    {
        return [1, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 24, 25];
    }

    public function sensitive(): array
    {
        return [3];
    }

    public function target(object $row): ?array
    {
        return ['finance', (int) $row->project_id];
    }

    public function query(array $cityIds, array $filters): Builder
    {
        $q = $this->projectBase($cityIds)->join('loans as l', fn ($j) => $j->on('l.project_id', '=', 'p.id')->whereNull('l.deleted_at'))
            ->addSelect([...$this->baseColumns(), 'l.id as loan_id', 'l.currency', 'l.exchange_rate', 'l.amount', 'l.taken_on', 'l.rate', 'l.returned_amount', 'l.installment_amount', 'l.note',
                'l.receipt_number', 'st.name_en as state_key', 'st.name_ar as state_ar', 'st.name_en as state_en']);
        $this->lookup($q, 'careers', 'cr', 'p.career_id', 'career');
        $this->lookup($q, 'project_categories', 'pc', 'p.project_category_id', 'category');
        $this->lookup($q, 'project_subcategories', 'ps', 'p.project_subcategory_id', 'subcategory');
        $this->lookup($q, 'donors', 'dn', 'p.donor_id', 'donor');
        $this->lookup($q, 'donor_groups', 'dg', 'p.donor_group_id', 'dgroup');

        return ProjectReportFilters::apply($q, $filters);
    }

    public function order(Builder $query): Builder
    {
        return $query->orderBy('p.project_number')->orderBy('p.city_id')->orderBy('l.id');
    }

    /** Loans, repayments, returned, net, repayment loans and the repayments balance, in SYP and USD apart. */
    public function summary(Builder $query): ?array
    {
        $norm = fn ($c) => in_array($c, ['SYP', 'SP'], true) ? 'SYP' : (string) $c;
        $zero = fn () => ['loans' => '0', 'repayments' => '0', 'returned' => '0', 'net' => '0', 'repayment_loans' => '0', 'balance' => '0', 'count' => 0];
        $out = ['SYP' => $zero(), 'USD' => $zero()];
        $add = function (string $cur, string $key, mixed $v) use (&$out) {
            $out[$cur][$key] = Money::sum([$out[$cur][$key], Money::canonical($v) ?? '0']);
        };
        foreach ($this->bare($query)->groupBy('l.currency')->selectRaw('l.currency as cur, COUNT(*) as n, COALESCE(SUM(l.amount), 0) as total')->get() as $r) {
            if (isset($out[$norm($r->cur)])) {
                $add($norm($r->cur), 'loans', $r->total);
                $out[$norm($r->cur)]['count'] += (int) $r->n;
            }
        }
        $paid = $this->bare($query)->join('loan_installments as i', fn ($j) => $j->on('i.loan_id', '=', 'l.id')->where('i.is_paid', true)->whereNull('i.deleted_at'))
            ->groupBy('l.currency')->selectRaw('l.currency as cur, COALESCE(SUM(CASE WHEN i.amount >= 0 THEN i.amount ELSE 0 END), 0) as pos, COALESCE(SUM(CASE WHEN i.amount < 0 THEN i.amount ELSE 0 END), 0) as neg')->get();
        foreach ($paid as $r) {
            if (isset($out[$norm($r->cur)])) {
                $add($norm($r->cur), 'repayments', $r->pos);
                $add($norm($r->cur), 'returned', $r->neg);
            }
        }
        $installment = $this->bare($query)->where(fn ($w) => $w->where('dg.name_ar', 'like', '%Installment%')->orWhere('dg.name_en', 'like', '%Installment%'))
            ->groupBy('l.currency')->selectRaw('l.currency as cur, COALESCE(SUM(l.amount), 0) as total')->get();
        foreach ($installment as $r) {
            isset($out[$norm($r->cur)]) && $add($norm($r->cur), 'repayment_loans', $r->total);
        }
        foreach ($out as $cur => $v) {
            $out[$cur]['net'] = Money::sum([$v['repayments'], $v['returned']]);
            $out[$cur]['balance'] = Money::sum([$out[$cur]['net'], '-'.ltrim($v['repayment_loans'], '-')]);
        }

        return $out;
    }

    public function lines(Collection $rows): array
    {
        $payments = DB::table('loan_installments')->whereIn('loan_id', $rows->pluck('loan_id')->all() ?: [0])->whereNull('deleted_at')->orderBy('id')
            ->get(['loan_id', 'amount', 'is_paid', 'due_on', 'received_on'])->groupBy('loan_id');
        $people = $this->applicants($rows->pluck('project_id')->all());
        $now = CarbonImmutable::now();

        return $rows->map(function ($r) use ($payments, $people, $now) {
            $list = $payments->get($r->loan_id, collect());
            $cur = in_array($r->currency, ['SYP', 'SP'], true) ? 'SYP' : (string) $r->currency;
            $amount = Money::canonical($r->amount) ?? '0';
            $rate = Money::canonical($r->exchange_rate) ?? '0';
            $returned = Money::canonical($r->returned_amount) ?? '0';
            $instalment = Money::canonical($r->installment_amount) ?? '0';
            $paidRows = $list->filter(fn ($i) => (bool) $i->is_paid);
            $paid = Money::sum($paidRows->pluck('amount')->map(fn ($a) => Money::canonical($a) ?? '0')->all());
            $remaining = bccomp(bcsub($returned, $paid, 8), '0', 8) < 0 ? '0' : (Money::canonical(bcsub($returned, $paid, 8)) ?? '0');
            $sp = $cur === 'SYP' ? $amount : ($cur === 'USD' ? Money::round(bcmul($amount, $rate, 12), 2) : '');
            $usd = $cur === 'USD' ? $amount : ($cur === 'SYP' && ! Money::isZero($rate) ? Money::round(bcdiv($amount, $rate, 12), 2) : '');
            $byRate = bcsub(bcdiv(bcmul($amount, Money::canonical($r->rate) ?? '0', 8), '100', 8), $paid, 8);
            $remainingCount = Money::isZero($instalment) ? '' : Money::round(bcdiv(bccomp($byRate, '0', 8) < 0 ? '0' : $byRate, $instalment, 12), 2);
            $unpaid = $list->filter(fn ($i) => ! $i->is_paid);
            $lastDue = $unpaid->pluck('due_on')->filter()->map(fn ($d) => $this->day($d))->max();
            $closed = in_array($r->state_key, ApplicationSearch::CLOSED, true);
            $late = Money::isZero($remaining) ? 0 : ($lastDue === null || $closed ? '' : (int) CarbonImmutable::parse($lastDue)->diffInMonths($now->addMonth(), false));
            $first = $people[$r->project_id] ?? null;

            return [
                $this->number($r), $first['all'] ?? '', (string) ($first['first']->mobile ?? ''), $this->name($r, 'career'), $this->name($r, 'category'), $this->name($r, 'subcategory'),
                $this->name($r, 'state'), $this->day($r->taken_on), $cur, $rate, $sp, $usd, Money::isZero($amount) ? '' : Money::round(bcdiv(bcmul($returned, '100', 8), $amount, 12), 2),
                $returned, $instalment, $paid, $remaining, Money::isZero($instalment) ? '' : Money::round(bcdiv($returned, $instalment, 12), 2), $paidRows->count(), $remainingCount,
                $list->pluck('due_on')->filter()->map(fn ($d) => $this->day($d))->min() ?? '', $paidRows->pluck('received_on')->filter()->map(fn ($d) => $this->day($d))->max() ?? '',
                $r->state_key === 'Financed' ? ($lastDue ?? '') : '', $late, $unpaid->count(), (string) $r->note, $this->name($r, 'donor'), $this->name($r, 'dgroup'), (string) $r->receipt_number,
            ];
        })->all();
    }
}
