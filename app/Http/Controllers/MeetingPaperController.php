<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Papers\MeetingPaper;
use App\Services\Papers\MeetingWord;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** M4.18: the printed minutes and survey of a final profession meeting, and the same papers as Word files. */
final class MeetingPaperController
{
    public function show(Request $request, string $locale, int $meeting, string $kind, MeetingPaper $paper): View
    {
        return view('papers.meeting-'.$kind, ['d' => $paper->forPrint($request->user(), $meeting, $kind)]);
    }

    /** Same permission and the same refusal of a draft as the print; audited `papers.printed` with `:word`. */
    public function word(Request $request, string $locale, int $meeting, string $kind, MeetingPaper $paper, MeetingWord $word): Response
    {
        $data = $paper->forPrint($request->user(), $meeting, $kind, 'word');

        return response($word->build($data), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="meeting-'.$kind.'-'.$meeting.'.docx"',
        ]);
    }
}
