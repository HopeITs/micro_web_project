<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Papers\MeetingPaper;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** M4.18: the printed minutes and survey of a final profession meeting. */
final class MeetingPaperController
{
    public function show(Request $request, string $locale, int $meeting, string $kind, MeetingPaper $paper): View
    {
        return view('papers.meeting-'.$kind, ['d' => $paper->forPrint($request->user(), $meeting, $kind)]);
    }
}
