<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AccountAccess;
use App\Services\Reports\TableExport;
use App\Services\Staff\NotificationInboxService;
use App\Support\TableTools\PrivateSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class NotificationController
{
    public function index(Request $request, string $locale, NotificationInboxService $inbox): View
    {
        $filters = $request->validate(['cities' => ['sometimes', 'array', 'max:20'], 'cities.*' => ['integer', 'min:1'], 'kind' => ['nullable', 'in:message,visit,clearance,payment,trophy,executive_file,state,purchases'],
            'sort' => ['nullable', 'string', 'max:20'], 'dir' => ['nullable', 'in:asc,desc']]);
        // The search text may be a name: it is kept in the session, never in the address.
        $filters['q'] = PrivateSearch::get('notifications');

        return view('staff.notifications', $inbox->inbox($request->user(), $filters) + ['filters' => $filters]);
    }

    /** Everything in the box, as an Excel workbook or CSV (the box's own authorization and city scope apply). */
    public function export(Request $request, string $locale, NotificationInboxService $inbox, TableExport $export): StreamedResponse
    {
        $filters = $request->validate(['cities' => ['sometimes', 'array', 'max:20'], 'cities.*' => ['integer', 'min:1'], 'kind' => ['nullable', 'in:message,visit,clearance,payment,trophy,executive_file,state,purchases'], 'format' => ['nullable', 'in:xlsx,csv'],
            'sort' => ['nullable', 'string', 'max:20'], 'dir' => ['nullable', 'in:asc,desc']]);
        $rows = $inbox->exportRows($request->user(), ['q' => PrivateSearch::get('notifications')] + $filters);

        return $export->stream(TableExport::format($filters['format'] ?? null), 'notifications-'.now()->format('Ymd-His'), ['City', 'Date', 'Type', 'Project number', 'Title', 'Message', 'Sender'], $rows, [3], app()->isLocale('ar'));
    }

    public function seen(Request $request, string $locale, NotificationInboxService $inbox): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer', 'min:1']]);
        $count = $inbox->markSeen($request->user(), array_map('intval', $data['ids']));

        return redirect()->route('notifications.index', ['locale' => $locale])->with('status', __('staff.notifications.marked', ['count' => $count]));
    }

    public function create(Request $request, string $locale, NotificationInboxService $inbox): View
    {
        $cities = $inbox->sendCities($request->user());
        abort_if($cities->isEmpty(), 403);
        $city = (int) $request->integer('city', (int) $cities->first()->id);
        abort_unless($cities->contains('id', $city), 403);

        return view('staff.message', ['cities' => $cities, 'city' => $city, 'recipients' => $inbox->recipients($request->user(), $city), 'admin' => app(AccountAccess::class)->check($request->user(), $city, 'accounts.manage')]);
    }

    public function send(Request $request, string $locale, NotificationInboxService $inbox): RedirectResponse
    {
        $data = $request->validate([
            'city' => ['required', 'integer', 'min:1'], 'to' => ['required', 'string', 'max:20'], 'date' => ['nullable', 'string', 'max:10'],
            'title' => ['nullable', 'string', 'max:255'], 'body' => ['nullable', 'string', 'max:65535'], 'project' => ['nullable', 'string', 'max:19'], 'email_copy' => ['nullable', 'in:1'],
        ]);
        $result = $inbox->send($request->user(), $data);

        return redirect()->route('notifications.create', ['locale' => $locale, 'city' => $data['city']])->with('status', __('staff.notifications.sent', ['count' => $result['sent']]).($result['emailed'] > 0 ? ' '.__('staff.notifications.sent_mail', ['count' => $result['emailed']]) : '').($result['failed'] > 0 ? ' '.__('staff.notifications.mail_failed', ['count' => $result['failed']]) : ''));
    }
}
