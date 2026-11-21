<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AccountAccess;
use App\Services\Attachments\AttachmentService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/** M4.11: the attachments page and the private file response (AttachmentService rechecks the city and category grants). */
final class AttachmentFileController
{
    public function index(Request $request, AccountAccess $access): View
    {
        $access->cities($request->user(), 'attachments.download')->isNotEmpty() || abort(403);

        return view('attachments.index');
    }

    /** Shows a checked image or PDF in the browser, or sends any file as a download (?download=1, recorded in the audit log). */
    public function show(Request $request, string $locale, int $attachment, AttachmentService $attachments): BinaryFileResponse
    {
        $row = $attachments->find($request->user(), $attachment);
        $path = $attachments->path($row);
        abort_if($path === null, 404);
        $mime = AttachmentService::mime($row);
        $download = $request->boolean('download') || $mime === null;
        if ($download) {
            $attachments->audit($request->user(), $row, 'attachments.view');
        }
        $name = (string) ($row->original_name ?: 'attachment-'.$row->id.'.'.$row->extension);
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $mime ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'Content-Security-Policy' => $mime === 'application/pdf' ? "default-src 'none'; object-src 'self'" : "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], false, null, false, false);
        $fallback = 'attachment-'.$row->id.($row->extension ? '.'.preg_replace('/[^a-z0-9]/', '', strtolower((string) $row->extension)) : '');

        return $response->setContentDisposition($download ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE, $name, $fallback);
    }
}
