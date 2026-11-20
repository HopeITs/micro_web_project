<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Lookups\LookupAdminService;
use App\Services\Lookups\LookupCatalog;
use App\Services\Reports\TableExport;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** M4.2: the lists overview, one list's editor (Livewire `LookupEditor`) and its Excel/CSV export. */
final class LookupController
{
    public function index(Request $request, LookupAdminService $lookups): View
    {
        return view('lookups.index', ['lists' => collect($lookups->overview($request->user()))->groupBy('group')]);
    }

    public function show(Request $request, string $locale, string $list, LookupAdminService $lookups): View
    {
        abort_unless(LookupCatalog::exists($list), 404);
        $lookups->cities($request->user())->isNotEmpty() || abort(403);

        return view('lookups.show', ['list' => $list, 'definition' => LookupCatalog::get($list)]);
    }

    public function export(Request $request, string $locale, string $list, LookupAdminService $lookups, TableExport $export): StreamedResponse
    {
        abort_unless(LookupCatalog::exists($list), 404);
        $data = $request->validate(['city' => ['nullable', 'integer', 'min:1'], 'format' => ['nullable', 'in:xlsx,csv']]);
        [$headings, $rows] = $lookups->export($request->user(), $list, LookupCatalog::get($list)['city'] ? (int) ($data['city'] ?? 0) : null);

        return $export->stream(TableExport::format($data['format'] ?? null), 'list-'.$list.'-'.now()->format('Ymd-His'), $headings, $rows, [0, count($headings) - 2], app()->isLocale('ar'));
    }
}
