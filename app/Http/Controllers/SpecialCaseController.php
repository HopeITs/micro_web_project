<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Field\SpecialCaseListService;
use App\Services\Field\SpecialCaseService;
use App\Services\Reports\TableExport;
use App\Support\TableTools\ExportSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * M4.13 special cases: a project's cases (Livewire ProjectSpecialCases) and the list of all cases (SpecialCaseList) with its export.
 * The services recheck every capability per city; the page visit is audited here once.
 */
final class SpecialCaseController
{
    public function project(Request $request, string $locale, int $project, SpecialCaseService $cases): View
    {
        $page = $cases->page($request->user(), $project, opened: true);

        return view('special-cases.project', ['project' => $page['project'], 'code' => (string) DB::table('cities')->where('id', $page['project']->city_id)->value('code')]);
    }

    public function index(Request $request, SpecialCaseListService $list): View
    {
        $list->opened($request->user());

        return view('special-cases.index');
    }

    public function export(Request $request, string $locale, SpecialCaseListService $list, TableExport $export): StreamedResponse
    {
        // While the list is searched the filters arrive through a single-use token (the text stays out of the address).
        $held = ExportSnapshot::pull($request, 'special-cases');
        try {
            $filters = SpecialCaseListService::clean($held ?? $request->query());
        } catch (ValidationException) {
            abort(422);
        }
        [$headings, $rows] = $list->export($request->user(), $filters);

        return $export->stream(TableExport::format($request->query('format')), 'special-cases-'.now()->format('Ymd-His'), $headings, $rows, [], app()->isLocale('ar'));
    }
}
