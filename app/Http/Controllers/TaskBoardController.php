<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Reports\TableExport;
use App\Services\Staff\TaskBoardService;
use App\Support\TableTools\ExportSnapshot;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** M4.16 task board: the page is the Livewire `TaskBoard`; the export of the grid as shown is here. The service rechecks every capability per city. */
final class TaskBoardController
{
    public function index(Request $request, TaskBoardService $board): View
    {
        abort_if($board->cities($request->user())->isEmpty(), 403);

        return view('tasks.index');
    }

    /** Every project of the board's current filters (all pages), one column per task, as an Excel workbook or CSV. */
    public function export(Request $request, string $locale, TaskBoardService $board, TableExport $export): StreamedResponse
    {
        // While the grid is searched the filters arrive through a single-use token (the text stays out of the address).
        $held = ExportSnapshot::pull($request, 'task-board');
        $filters = $request->validate([
            'sort' => ['nullable', 'string', 'max:40'], 'dir' => ['nullable', 'in:asc,desc'],
            'kind' => ['nullable', 'in:f,a'], 'cities' => ['sometimes', 'array', 'max:20'], 'cities.*' => ['integer', 'min:1'], 'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'integer', 'min:1'], 'donor' => ['nullable', 'integer', 'min:1'], 'type' => ['nullable', 'integer', 'min:1'],
            'tasks' => ['sometimes', 'array', 'max:60'], 'tasks.*' => ['nullable', 'in:0,1'], 'format' => ['nullable', 'in:xlsx,csv'],
        ]);
        $filters = $held === null ? $filters : $held + ['format' => $filters['format'] ?? null];
        $filters['tasks'] = array_filter($filters['tasks'] ?? [], fn ($flag) => $flag !== null);
        [$taskColumns, $rows, $kind] = $board->exportRows($request->user(), $filters);
        $headings = array_merge(array_map(fn ($k) => __('staff.tasks.'.$k), ['city', 'project', 'beneficiary', 'phone', 'name', 'state', 'donor', 'type']), $kind === 'f' ? [__('staff.tasks.loan_date')] : [], $taskColumns);

        return $export->stream(TableExport::format($filters['format'] ?? null), ($kind === 'f' ? 'task-board-funded-' : 'task-board-applications-').now()->format('Ymd-His'), $headings, $rows, [1], app()->isLocale('ar'));
    }
}
