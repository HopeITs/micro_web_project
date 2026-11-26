<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AccountAuditTrail;
use App\Services\Field\MonitoringVisitService;
use App\Services\Field\VisitKinds;
use App\Services\Field\VisitorStatisticsService;
use App\Services\Field\VisitResultsService;
use App\Services\Field\VisitScheduleService;
use App\Services\Field\VisitService;
use App\Services\Reports\TableExport;
use App\Support\TableTools\ExportSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * M4.12 visit pages: a project's visits (Livewire ProjectVisits), the opening / closing / rehabilitation questionnaire (VisitForm), the
 * M&E visit (MeVisitForm), the schedule, the results report and the visitor statistics with their exports. The services recheck every
 * capability per city; the page visit is audited here once.
 */
final class VisitController
{
    private function code(object $project): string
    {
        return (string) DB::table('cities')->where('id', $project->city_id)->value('code');
    }

    public function hub(Request $request, string $locale, int $project, VisitService $visits): View
    {
        $hub = $visits->hub($request->user(), $project, opened: true);

        return view('visits.hub', ['project' => $hub['project'], 'code' => $this->code($hub['project'])]);
    }

    public function show(Request $request, string $locale, int $project, string $kind, VisitService $visits): View
    {
        VisitKinds::has($kind) || abort(404);
        $form = $visits->form($request->user(), $project, $kind);
        app(AccountAuditTrail::class)->record($request->user()->id, 'visits.view', 'succeeded', (int) $form['project']->city_id, subjectType: 'application_entity', subjectId: $project);

        return view('visits.form', ['project' => $form['project'], 'code' => $this->code($form['project']), 'kind' => $kind, 'exists' => $form['visit'] !== null]);
    }

    public function meCreate(Request $request, string $locale, int $project, int $person, MonitoringVisitService $me): View
    {
        $form = $me->form($request->user(), $project, $person, null);
        app(AccountAuditTrail::class)->record($request->user()->id, 'me_visits.view', 'succeeded', (int) $form['project']->city_id, subjectType: 'application_entity', subjectId: $project);

        return view('visits.me', ['project' => $form['project'], 'code' => $this->code($form['project']), 'person' => $person, 'visit' => null, 'number' => $request->query('number')]);
    }

    public function meShow(Request $request, string $locale, int $project, int $visit, MonitoringVisitService $me): View
    {
        $form = $me->form($request->user(), $project, null, $visit);
        app(AccountAuditTrail::class)->record($request->user()->id, 'me_visits.view', 'succeeded', (int) $form['project']->city_id, subjectType: 'application_entity', subjectId: $project);

        return view('visits.me', ['project' => $form['project'], 'code' => $this->code($form['project']), 'person' => (int) $form['person']->id, 'visit' => $visit, 'number' => null]);
    }

    public function schedule(Request $request, VisitScheduleService $schedule): View
    {
        $schedule->opened($request->user());

        return view('visits.schedule');
    }

    public function scheduleExport(Request $request, string $locale, VisitScheduleService $schedule, TableExport $export): StreamedResponse
    {
        // While the schedule is searched the filters arrive through a single-use token (the text stays out of the address).
        $held = ExportSnapshot::pull($request, 'visit-schedule');
        $filters = $request->validate([
            'sort' => ['nullable', 'string', 'max:40'], 'dir' => ['nullable', 'in:asc,desc'],
            'cities' => ['sometimes', 'array', 'max:20'], 'cities.*' => ['integer', 'min:1'], 'year' => ['nullable', 'integer', 'between:2000,2100'],
            'months' => ['sometimes', 'array', 'max:12'], 'months.*' => ['integer', 'between:1,12'], 'kind' => ['nullable', Rule::in(['V', 'O'])],
            'visit' => ['nullable', Rule::in(VisitScheduleService::VISITS)], 'street' => ['nullable', 'integer', 'min:1'], 'type' => ['nullable', 'integer', 'min:1'],
            'hidden' => ['nullable', 'boolean'], 'q' => ['nullable', 'string', 'max:100'], 'format' => ['nullable', 'in:xlsx,csv'],
        ]);
        $filters = $held === null ? $filters : $held + ['format' => $filters['format'] ?? null];
        [$headings, $rows] = $schedule->export($request->user(), $filters);

        return $export->stream(TableExport::format($filters['format'] ?? null), 'visit-schedule-'.now()->format('Ymd-His'), $headings, $rows, [], app()->isLocale('ar'));
    }

    public function results(Request $request, VisitResultsService $results): View
    {
        $results->opened($request->user());

        return view('visits.results');
    }

    public function resultsExport(Request $request, string $locale, VisitResultsService $results, TableExport $export): StreamedResponse
    {
        $filters = VisitResultsService::clean(ExportSnapshot::pull($request, 'visit-results') ?? $request->query());
        [$headings, $rows] = $results->export($request->user(), $filters);

        return $export->stream(TableExport::format($request->query('format')), 'visit-results-'.now()->format('Ymd-His'), $headings, $rows, [], app()->isLocale('ar'));
    }

    public function statistics(Request $request, VisitorStatisticsService $statistics): View
    {
        $statistics->opened($request->user());

        return view('visits.statistics');
    }

    public function statisticsExport(Request $request, string $locale, VisitorStatisticsService $statistics, TableExport $export): StreamedResponse
    {
        try {
            $filters = VisitorStatisticsService::clean($request->query());
        } catch (ValidationException) {
            abort(422);
        }
        [$headings, $rows] = $statistics->export($request->user(), $filters);

        return $export->stream(TableExport::format($request->query('format')), 'visitor-statistics-'.now()->format('Ymd-His'), $headings, $rows, [1, 2, 3, 4, 5, 6, 7, 8], app()->isLocale('ar'));
    }
}
