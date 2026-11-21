<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Finance\ExecutiveFileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** M4.10: a project's executive file page (Livewire ExecutiveFileEditor); the service rechecks the capabilities on every action. */
final class ExecutiveFileController
{
    public function show(Request $request, string $locale, int $project, ExecutiveFileService $files): View
    {
        $row = $files->opened($request->user(), $project);

        return view('finance.executive', ['project' => $row, 'code' => DB::table('cities')->where('id', $row->city_id)->value('code')]);
    }
}
