<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Papers\ApplicationPaper;
use App\Services\Papers\FilePaper;
use App\Services\Papers\ProjectPaper;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** M4.20: the printed official papers of one project (the application form first; the other papers join as their modules land). */
final class PaperPrintController
{
    public function application(Request $request, string $locale, int $project, ApplicationPaper $paper): View
    {
        $data = $paper->forPrint($request->user(), $project);

        return view($data['preliminary'] ? 'papers.application-preliminary' : 'papers.application', ['d' => $data, 'projectId' => $project]);
    }

    /** M4.18: the whole beneficiary file in procedure order (cover, application form, checklist). */
    public function file(Request $request, string $locale, int $project, FilePaper $paper): View
    {
        return view('papers.file', $paper->forPrint($request->user(), $project));
    }

    /** M4.20 wave 1: the audio consent (F04), Special Case (F05, optionally one case) and the experience test (F06) of a project. */
    public function project(Request $request, string $locale, int $project, string $paper, ProjectPaper $papers): View
    {
        $case = $request->filled('case') ? (int) $request->query('case') : null;

        return view('papers.'.$paper, ['d' => $papers->forPrint($request->user(), $project, $paper, $case)]);
    }
}
