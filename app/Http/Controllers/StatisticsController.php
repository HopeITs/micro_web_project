<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AccountAuditTrail;
use App\Services\Reports\StatisticsService;
use App\Services\Reports\TableExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** M4.17 statistics: the page (Livewire StatisticsPage) and its Excel/CSV from a single-use token; StatisticsService authorises and audits it. */
final class StatisticsController
{
    public function index(Request $request, StatisticsService $statistics, AccountAuditTrail $audit): View
    {
        if ($statistics->cities($request->user())->isEmpty()) {
            $audit->record($request->user()->id, 'access.denied', 'denied');
            abort(403);
        }

        return view('statistics.index');
    }

    public function export(Request $request, string $locale, string $token, StatisticsService $statistics, TableExport $export): StreamedResponse
    {
        $payload = preg_match('/\A[A-Za-z0-9]{40}\z/', $token) === 1 ? Cache::pull('statistics-export:'.$token) : null;
        abort_if(! is_array($payload) || ($payload['account'] ?? null) !== $request->user()->id, 404);

        [$headings, $rows, $numeric] = $statistics->export($request->user(), (string) ($payload['axis'] ?? ''), (array) ($payload['state'] ?? []), (array) ($payload['view'] ?? []));

        return $export->stream(TableExport::format($payload['format'] ?? null), 'statistics-'.$payload['axis'].'-'.now()->format('Ymd-His'), $headings, $rows, $numeric, app()->isLocale('ar'));
    }
}
