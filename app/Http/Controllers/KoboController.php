<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Kobo\KoboReadService;
use App\Services\Kobo\KoboResultsService;
use App\Services\Reports\TableExport;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** M4.14 Kobo page (read and review only) and the answers export. Downloading from Kobo stays on the desktop until M4.14b. */
final class KoboController
{
    private const DATE = ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'];

    public function index(Request $request, KoboReadService $kobo): View
    {
        $kobo->opened($request->user());

        return view('kobo.index');
    }

    public function export(Request $request, string $locale, KoboResultsService $results, AccountAccess $access, AccountAuditTrail $audit, TableExport $export): StreamedResponse
    {
        $input = $request->validate([
            'city' => ['nullable', 'array'], 'city.*' => ['integer', 'min:1'], 'form' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
            'from' => self::DATE, 'to' => self::DATE, 'last' => ['nullable', 'boolean'], 'format' => ['nullable', 'in:xlsx,csv'],
        ]);
        [$headings, $rows] = $results->export($request->user(), [
            'cities' => $input['city'] ?? [], 'form' => $input['form'] ?? '', 'from' => $input['from'] ?? null, 'to' => $input['to'] ?? null, 'last' => (bool) ($input['last'] ?? false),
        ], $access, $audit);

        return $export->stream(TableExport::format($input['format'] ?? null), 'kobo-answers-'.now()->format('Ymd-His'), $headings, $rows, [], app()->isLocale('ar'));
    }
}
