<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Field\ProfessionMeetingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** M4.15 profession meetings: list, the form (Livewire `ProfessionMeetingForm`) and the statistics / interests report. The service rechecks every capability per city. */
final class ProfessionMeetingController
{
    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'cities' => ['sometimes', 'array', 'max:20'], 'cities.*' => ['integer', 'min:1'], 'year' => ['nullable', 'integer', 'between:2000,'.(now()->year + 5)],
            'career' => ['nullable', 'integer', 'min:1'], 'state' => ['nullable', 'in:draft,final'],
        ]);
    }

    public function index(Request $request, string $locale, ProfessionMeetingService $meetings): View
    {
        $filters = $this->filters($request);

        return view('meetings.index', $meetings->index($request->user(), $filters) + ['filters' => $filters]);
    }

    public function create(Request $request, string $locale, ProfessionMeetingService $meetings): View
    {
        $city = filter_var($request->query('city'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($city === false, 404);
        $meetings->create($request->user(), $city);

        return view('meetings.form', ['meetingId' => null, 'city' => $city]);
    }

    /** The form mounts through `ProfessionMeetingForm`, which checks the permission and records the visit. */
    public function show(Request $request, string $locale, int $meeting): View
    {
        return view('meetings.form', ['meetingId' => $meeting, 'city' => 0]);
    }

    public function report(Request $request, string $locale, ProfessionMeetingService $meetings): View
    {
        $filters = $this->filters($request);

        return view('meetings.report', $meetings->report($request->user(), $filters) + ['filters' => $filters]);
    }
}
