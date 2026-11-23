<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Papers\ApplicationPaper;
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
}
