<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AccountAuditTrail;
use App\Services\Reports\Search\SearchService;
use App\Services\Reports\TableExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * M4.17 search: the page (Livewire SearchPage) and its Excel/CSV export. The export comes from the single-use token the page left server-side
 * (no search text in an address); SearchService authorises it per city (reports.export, plus finance.export for loans) and audits it before
 * the first byte.
 */
final class SearchController
{
    public function index(Request $request, SearchService $search, AccountAuditTrail $audit): View
    {
        if ($search->available($request->user()) === []) {
            $audit->record($request->user()->id, 'access.denied', 'denied');
            abort(403);
        }

        return view('search.index');
    }

    public function export(Request $request, string $locale, string $token, SearchService $search, TableExport $export): StreamedResponse
    {
        $payload = preg_match('/\A[A-Za-z0-9]{40}\z/', $token) === 1 ? Cache::pull('search-export:'.$token) : null;
        abort_if(! is_array($payload) || ($payload['account'] ?? null) !== $request->user()->id, 404);

        [$headings, $rows, $numeric, $mode] = $search->export($request->user(), (string) ($payload['mode'] ?? ''), (array) ($payload['state'] ?? []));

        return $export->stream(TableExport::format($payload['format'] ?? null), 'search-'.$mode->key().'-'.now()->format('Ymd-His'), $headings, $rows, $numeric, app()->isLocale('ar'));
    }
}
