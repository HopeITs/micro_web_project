<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Finance\FinanceService;
use App\Services\Finance\PaymentScheduleService;
use App\Services\Reports\TableExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** M4.9: a project's finance page (Livewire ProjectFinance), the payment schedule (Livewire PaymentSchedule) and its export. */
final class FinanceController
{
    public function show(Request $request, string $locale, int $project, FinanceService $finance): View
    {
        $row = $finance->opened($request->user(), $project);

        return view('finance.show', ['project' => $row, 'code' => DB::table('cities')->where('id', $row->city_id)->value('code')]);
    }

    public function payments(): View
    {
        return view('finance.payments');
    }

    public function export(Request $request, string $locale, PaymentScheduleService $schedule, TableExport $export): StreamedResponse
    {
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(PaymentScheduleService::VIEWS)], 'cities' => ['sometimes', 'array', 'max:20'], 'cities.*' => ['integer', 'min:1'],
            'year' => ['nullable', 'integer', 'between:2000,2100'], 'month' => ['nullable', 'integer', 'between:1,12'], 'group' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', Rule::in(FinanceService::CURRENCIES)], 'format' => ['nullable', 'in:xlsx,csv'],
        ]);
        $rows = $schedule->exportRows($request->user(), $filters);
        $headings = array_map(fn ($key) => __('finance.payments.columns.'.$key), ['city', 'number', 'beneficiary', 'group', 'currency', 'amount', 'due', 'status', 'received']);

        return $export->stream(TableExport::format($filters['format'] ?? null), 'payments-'.now()->format('Ymd-His'), $headings, $rows, [5], app()->isLocale('ar'));
    }
}
