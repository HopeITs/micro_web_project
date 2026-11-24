<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Papers\PaperException;
use App\Services\Papers\PaperLibrary;
use App\Services\Papers\PaperManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** M4.20: the official papers library (blank papers per city and version, for download). */
final class PaperLibraryController
{
    public function index(Request $request, string $locale, PaperLibrary $library): View
    {
        $filters = $request->validate([
            'city' => ['nullable', 'string', 'in:ALP,HMS,DAM,LAT,WAD'], 'variant' => ['nullable', 'in:syrian,non_syrian,generic'], 'q' => ['nullable', 'string', 'max:100'],
        ]);

        return view('papers.index', $library->index($request->user(), $filters) + ['filters' => $filters]);
    }

    /** @return array<string, mixed> */
    private function fileParams(Request $request): array
    {
        return $request->validate([
            'form' => ['required', 'regex:/\A(F|C)\d{2}\z/'], 'city' => ['required', 'in:ALP,HMS,DAM,LAT,WAD'], 'place' => ['nullable', 'in:IDL'],
            'variant' => ['required', 'in:syrian,non_syrian,generic'], 'format' => ['required', 'in:pdf,docx'],
        ]);
    }

    public function download(Request $request, string $locale, PaperLibrary $library): StreamedResponse
    {
        $data = $this->fileParams($request);

        return $library->download($request->user(), $data['form'], $data['city'], $data['place'] ?? null, $data['variant'], $data['format']);
    }

    /** The original paper shown inside the review page (a PDF, or the background picture of a Word paper). */
    public function original(Request $request, string $locale, PaperLibrary $library): Response
    {
        $data = $this->fileParams($request);

        return $library->original($request->user(), $data['form'], $data['city'], $data['place'] ?? null, $data['variant'], $data['format']);
    }

    /** A redrawn paper beside its original, for the legal and staff review of the wording and layout. */
    public function review(Request $request, string $locale, string $form, PaperLibrary $library): View
    {
        abort_unless(preg_match('/\AF\d{2}\z/', $form) === 1, 404);
        $filters = $request->validate(['city' => ['nullable', 'in:ALP,HMS,DAM,LAT,WAD'], 'project' => ['nullable', 'integer', 'min:1']]);

        return view('papers.review', $library->review($request->user(), $form, $filters) + ['filters' => $filters]);
    }

    /** Adds a file to a paper (or replaces the file of the same city, version and format); `form` = "new" adds a new paper with the given names. */
    public function upload(Request $request, string $locale, PaperManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'form' => ['required', 'regex:/\A(F\d{2}|C\d{2}|new)\z/'], 'city' => ['required', 'in:ALP,HMS,DAM,LAT,WAD'], 'place' => ['nullable', 'in:IDL'],
            'variant' => ['required', 'in:syrian,non_syrian,generic'], 'file' => ['required', 'file', 'max:15360'],
            'name_ar' => ['required_if:form,new', 'nullable', 'string', 'max:150'], 'name_en' => ['nullable', 'string', 'max:150'],
            'group' => ['required_if:form,new', 'nullable', 'in:'.implode(',', PaperLibrary::GROUPS)],
        ]);
        try {
            $manager->upload($request->user(), $data['form'] === 'new' ? null : $data['form'], $data['form'] === 'new' ? ['name_ar' => trim($data['name_ar']), 'name_en' => ($data['name_en'] ?? '') !== '' ? trim($data['name_en']) : null, 'group' => $data['group']] : null,
                $data['city'], $data['place'] ?? null, $data['variant'], $request->file('file'));
        } catch (PaperException $e) {
            return back()->withInput()->withErrors(['file' => __($e->getMessage())]);
        }

        return back()->with('status', __('papers.saved'));
    }

    /** Takes a file off the library. */
    public function remove(Request $request, string $locale, PaperManager $manager): RedirectResponse
    {
        $data = $this->fileParams($request);
        try {
            $manager->remove($request->user(), $data['form'], $data['city'], $data['place'] ?? null, $data['variant'], $data['format']);
        } catch (PaperException $e) {
            abort(404);
        }

        return back()->with('status', __('papers.removed'));
    }

    /** Brings back an original file that was removed or replaced. */
    public function restore(Request $request, string $locale, PaperManager $manager): RedirectResponse
    {
        $data = $this->fileParams($request);
        try {
            $manager->restore($request->user(), $data['form'], $data['city'], $data['place'] ?? null, $data['variant'], $data['format']);
        } catch (PaperException $e) {
            abort(404);
        }

        return back()->with('status', __('papers.restored'));
    }
}
