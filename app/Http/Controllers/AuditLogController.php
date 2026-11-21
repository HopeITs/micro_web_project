<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Reports\TableExport;
use App\Services\Staff\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** M4.19 audit log: the website trail and the desktop history of the administrator's cities, on screen and as Excel/CSV. Read only. */
final class AuditLogController
{
    private const DATE = ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'];

    /** @return array<string, mixed> */
    private function webFilters(Request $request): array
    {
        return $request->validate([
            'city' => ['nullable', 'integer', 'min:1'], 'from' => self::DATE, 'to' => self::DATE,
            'kind' => ['nullable', 'in:change,view,security,denied'], 'area' => ['nullable', 'in:'.implode(',', array_keys(AuditLogService::AREAS))],
            'actor' => ['nullable', 'string', 'max:100'], 'format' => ['nullable', 'in:xlsx,csv'],
        ]);
    }

    /** @return array<string, mixed> */
    private function desktopFilters(Request $request): array
    {
        return $request->validate([
            'city' => ['nullable', 'integer', 'min:1'], 'from' => self::DATE, 'to' => self::DATE,
            'table' => ['nullable', 'string', 'max:100'], 'user' => ['nullable', 'string', 'max:100'], 'q' => ['nullable', 'string', 'max:100'],
            'format' => ['nullable', 'in:xlsx,csv'],
        ]);
    }

    public function index(Request $request, string $locale, AuditLogService $log): View
    {
        $filters = $this->webFilters($request);

        return view('admin.audit', $log->web($request->user(), $filters) + ['filters' => $filters]);
    }

    public function export(Request $request, string $locale, AuditLogService $log, TableExport $export): StreamedResponse
    {
        $filters = $this->webFilters($request);
        $rows = $log->webExport($request->user(), $filters);

        return $export->stream(TableExport::format($filters['format'] ?? null), 'audit-log-web-'.now()->format('Ymd-His'), AuditLogService::webHeadings(), $rows, [0], app()->isLocale('ar'));
    }

    public function desktop(Request $request, string $locale, AuditLogService $log): View
    {
        $filters = $this->desktopFilters($request);

        return view('admin.audit-desktop', $log->desktop($request->user(), $filters) + ['filters' => $filters]);
    }

    public function desktopExport(Request $request, string $locale, AuditLogService $log, TableExport $export): StreamedResponse
    {
        $filters = $this->desktopFilters($request);
        $rows = $log->desktopExport($request->user(), $filters);

        return $export->stream(TableExport::format($filters['format'] ?? null), 'audit-log-desktop-'.now()->format('Ymd-His'), AuditLogService::desktopHeadings(), $rows, [0], app()->isLocale('ar'));
    }
}
