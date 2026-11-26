<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Assessments\ChecklistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** M4.7: the checklist page (Livewire ChecklistForm) and its printable version (cl.docx layout). */
final class ChecklistController
{
    public function show(Request $request, string $locale, int $project, ChecklistService $checklists): View
    {
        $row = $checklists->project($request->user(), $project);

        $personId = $this->person($request);
        $checklists->assessee($project, $personId); // 404 for a person who is not an applicant of this project

        return view('checklists.show', ['project' => $row, 'code' => DB::table('cities')->where('id', $row->city_id)->value('code'), 'personId' => $personId]);
    }

    public function print(Request $request, string $locale, int $project, ChecklistService $checklists): View
    {
        return view('checklists.print', $checklists->printable($request->user(), $project, $this->person($request)));
    }

    /** The applicant chosen on the page (`?person=<id>`), or null for the project's beneficiary. */
    private function person(Request $request): ?int
    {
        $value = $request->query('person');
        abort_unless($value === null || (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/', $value) === 1), 404);

        return $value === null ? null : (int) $value;
    }
}
