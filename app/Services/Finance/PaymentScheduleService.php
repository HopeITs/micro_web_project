<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\ProjectNumberAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The payment schedule across the permitted cities (M4.9, desktop Payments_Form) on the unified tables. Owner decision 4 (21 Nov 2026):
 * it opens on the unpaid instalments due by the end of this month, overdue ones included, with count tiles (overdue, due this month,
 * paid this month) that filter the list; year, month, city, donor group and currency filters; count and sum per currency (never added
 * across currencies). Reading needs `applications.view` + `finance.view` in a city; the export also `finance.export` there.
 */
final class PaymentScheduleService
{
    public const PER_PAGE = 50;

    /** due: unpaid due by the end of this month (the default); overdue; month: unpaid due from today to the month end; paid_month; unpaid; paid. */
    public const VIEWS = ['due', 'overdue', 'month', 'paid_month', 'unpaid', 'paid'];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit) {}

    /** Cities where the account may read finance data. */
    public function cities(WebAccount $account): Collection
    {
        return $this->access->citiesWithAll($account, ['applications.view', 'finance.view']);
    }

    /**
     * @param  array{view?: string, cities?: list<int>, year?: ?int, month?: ?int, group?: ?int, currency?: ?string}  $filters
     * @return array{rows: LengthAwarePaginator, totals: Collection, tiles: array<string, int>, cities: Collection, groups: Collection, canExport: bool}
     */
    public function schedule(WebAccount $account, array $filters, bool $audit = true): array
    {
        $permitted = $audit ? $this->opened($account) : $this->permitted($account, false);
        $ids = $this->selected($filters, $permitted);
        [$query, $dateColumn] = $this->filtered($ids, $filters);
        $totals = (clone $query)->reorder()->selectRaw('l.currency as currency, COUNT(*) as installments, SUM(i.amount) as total')->groupBy('l.currency')->orderBy('l.currency')->get()
            ->map(fn ($row) => (object) ['currency' => $row->currency, 'installments' => (int) $row->installments, 'total' => Money::canonical($row->total) ?? '0']);
        $rows = $query->orderBy($dateColumn)->orderBy('i.id')->paginate(self::PER_PAGE);
        $names = $this->names($rows->getCollection()->pluck('project_id')->unique()->all());
        $rows->setCollection($rows->getCollection()->map(function ($row) use ($names) {
            $row->amount = Money::canonical($row->amount) ?? '0';
            $row->beneficiary = $names[$row->project_id] ?? '';
            $row->number = ProjectNumberAllocator::display($row->city_code, (int) $row->project_number);
            $row->due_on = Fields::day($row->due_on);
            $row->received_on = Fields::day($row->received_on);
            $row->overdue = ! $row->is_paid && $row->due_on !== '' && $row->due_on < now()->toDateString();

            return $row;
        }));

        return [
            'rows' => $rows, 'totals' => $totals, 'tiles' => $this->tiles($ids, $filters), 'cityOptions' => $permitted,
            'groups' => DB::table('donor_groups')->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']),
            'canExport' => $permitted->contains(fn ($city) => $this->access->check($account, (int) $city->id, 'finance.export')),
        ];
    }

    /** The page was opened: refused without finance in any city, else audited once. Returns the permitted cities. */
    public function opened(WebAccount $account): Collection
    {
        $permitted = $this->permitted($account, false);
        $this->audit->record($account->id, 'finance.list', 'succeeded');

        return $permitted;
    }

    /** @return Collection the permitted cities (an export: only those with finance.export); none is a refusal */
    private function permitted(WebAccount $account, bool $export): Collection
    {
        $permitted = $this->cities($account);
        if ($export) {
            $permitted = $permitted->filter(fn ($city) => $this->access->check($account, (int) $city->id, 'finance.export'))->values();
        }
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }

        return $permitted;
    }

    /** @return list<int> */
    private function selected(array $filters, Collection $permitted): array
    {
        $allowed = $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $chosen = array_values(array_intersect(array_map('intval', $filters['cities'] ?? []), $allowed));

        return $chosen !== [] ? $chosen : $allowed;
    }

    /** Joins instalment → loan → project (none deleted) in the cities, with the group, currency and year/month filters. */
    private function base(array $cityIds, array $filters): Builder
    {
        $query = DB::table('loan_installments as i')
            ->join('loans as l', 'l.id', '=', 'i.loan_id')
            ->join('projects as p', 'p.id', '=', 'l.project_id')
            ->join('cities as c', 'c.id', '=', 'p.city_id')
            ->leftJoin('donor_groups as g', 'g.id', '=', 'p.donor_group_id')
            ->whereNull('i.deleted_at')->whereNull('l.deleted_at')->whereNull('p.deleted_at')
            ->whereIn('i.city_id', $cityIds === [] ? [0] : $cityIds);
        if (! empty($filters['group'])) {
            $query->where('p.donor_group_id', (int) $filters['group']);
        }
        if (! empty($filters['currency'])) {
            $query->where('l.currency', $filters['currency']);
        }

        return $query;
    }

    /** @return array{0: Builder, 1: string} */
    private function filtered(array $cityIds, array $filters): array
    {
        $view = in_array($filters['view'] ?? 'due', self::VIEWS, true) ? ($filters['view'] ?? 'due') : 'due';
        $query = $this->base($cityIds, $filters);
        $paid = in_array($view, ['paid', 'paid_month'], true);
        $dateColumn = $paid ? 'i.received_on' : 'i.due_on';
        $this->view($query, $view);
        if (! empty($filters['year'])) {
            $year = (int) $filters['year'];
            $from = ! empty($filters['month']) ? CarbonImmutable::create($year, (int) $filters['month'], 1) : CarbonImmutable::create($year, 1, 1);
            $to = ! empty($filters['month']) ? $from->endOfMonth() : $from->endOfYear();
            $query->whereBetween($dateColumn, [$from->toDateString(), $to->toDateString()]);
        } elseif (! empty($filters['month'])) {
            $expression = DB::connection()->getDriverName() === 'sqlite' ? "CAST(strftime('%m', {$dateColumn}) AS INTEGER)" : "MONTH({$dateColumn})";
            $query->whereRaw($expression.' = ?', [(int) $filters['month']]);
        }
        $query->select(['i.id', 'i.amount', 'i.due_on', 'i.is_paid', 'i.received_on', 'l.currency', 'p.id as project_id', 'p.project_number', 'p.city_id', 'c.code as city_code',
            'c.name_ar as city_ar', 'c.name_en as city_en', 'g.name_ar as group_ar', 'g.name_en as group_en']);

        return [$query, $dateColumn];
    }

    private function view(Builder $query, string $view): void
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        match ($view) {
            'due' => $query->where('i.is_paid', false)->where('i.due_on', '<=', $monthEnd),
            'overdue' => $query->where('i.is_paid', false)->where('i.due_on', '<', $today),
            'month' => $query->where('i.is_paid', false)->whereBetween('i.due_on', [$today, $monthEnd]),
            'paid_month' => $query->where('i.is_paid', true)->whereBetween('i.received_on', [$monthStart, $monthEnd]),
            'unpaid' => $query->where('i.is_paid', false),
            'paid' => $query->where('i.is_paid', true),
        };
    }

    /** Counts of the three tiles, under the city, group and currency filters (not the year/month ones). */
    private function tiles(array $cityIds, array $filters): array
    {
        $out = [];
        foreach (['overdue', 'month', 'paid_month'] as $view) {
            $query = $this->base($cityIds, $filters);
            $this->view($query, $view);
            $out[$view] = $query->count();
        }

        return $out;
    }

    /**
     * Every instalment matching the filters (not one page) for the export, in schedule order. Authorization and the audit record happen
     * here, before the first byte is sent.
     *
     * @return \Generator<int, list<string|int|null>>
     */
    public function exportRows(WebAccount $account, array $filters): \Generator
    {
        $permitted = $this->permitted($account, true);
        [$query, $dateColumn] = $this->filtered($this->selected($filters, $permitted), $filters);
        $this->audit->record($account->id, 'reports.exported', 'succeeded');
        $query->orderBy($dateColumn)->orderBy('i.id');
        $en = app()->getLocale() === 'en';

        return (function () use ($query, $en) {
            foreach ($query->lazy(500)->chunk(500) as $chunk) {
                $names = $this->names($chunk->pluck('project_id')->unique()->all());
                foreach ($chunk as $row) {
                    yield [
                        $en ? $row->city_en : $row->city_ar, ProjectNumberAllocator::display($row->city_code, (int) $row->project_number), $names[$row->project_id] ?? '',
                        ($en && $row->group_en ? $row->group_en : $row->group_ar) ?? '', $row->currency, Money::canonical($row->amount) ?? '0', Fields::day($row->due_on),
                        $row->is_paid ? __('finance.installments.paid') : __('finance.installments.not_paid'), $row->is_paid ? Fields::day($row->received_on) : '',
                    ];
                }
            }
        })();
    }

    /** @return array<int, string> project id → "first last ابن/ة father" of its beneficiary */
    private function names(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }
        $rows = DB::table('project_applicants as a')->join('people as pe', 'pe.id', '=', 'a.person_id')->whereIn('a.project_id', $projectIds)->whereNull('a.deleted_at')
            ->orderByRaw("CASE WHEN a.role = 'مستفيد' THEN 0 ELSE 1 END")->orderBy('a.id')->get(['a.project_id', 'pe.first_name', 'pe.last_name', 'pe.father_name']);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->project_id] ??= trim(trim(($row->first_name ?? '').' '.($row->last_name ?? '')).' ابن/ة '.($row->father_name ?? ''));
        }

        return $out;
    }
}
