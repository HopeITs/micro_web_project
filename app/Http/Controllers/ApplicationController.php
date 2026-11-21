<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Applications\ApplicationService;
use App\Services\Applications\ProjectAnnexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** M4.5: the application form pages; the form itself is the Livewire ApplicationForm, which rechecks every action. */
final class ApplicationController
{
    public function create(Request $request, ApplicationService $applications): View
    {
        $applications->cities($request->user()) !== [] || abort(403);
        $person = $request->integer('person') ?: null;

        $draft = $request->query('draft');
        $draft = is_string($draft) && preg_match('/\A[a-z0-9-]{8,64}\z/', $draft) === 1 ? $draft : null;

        return view('applications.create', ['personId' => $person, 'draft' => $draft]);
    }

    /** M4.6: the agriculture or rehabilitation annex (Livewire ProjectAnnex); 404 for other project types. */
    public function annex(Request $request, string $locale, int $project, ProjectAnnexService $annexes): View
    {
        $row = $annexes->project($request->user(), $project);
        $kind = $annexes->kind($row) ?? abort(404);

        return view('applications.annex', ['project' => $row, 'kind' => $kind, 'code' => DB::table('cities')->where('id', $row->city_id)->value('code')]);
    }

    public function edit(Request $request, string $locale, int $project, ApplicationService $applications): View
    {
        $row = $applications->project($request->user(), $project);
        $applications->canWrite($request->user(), (int) $row->city_id) || abort(403);

        return view('applications.edit', ['project' => $row, 'code' => DB::table('cities')->where('id', $row->city_id)->value('code')]);
    }
}
