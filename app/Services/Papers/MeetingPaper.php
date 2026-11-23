<?php

declare(strict_types=1);

namespace App\Services\Papers;

use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Field\ProfessionMeetingService;
use Illuminate\Support\Facades\DB;

/**
 * M4.18: the printed profession meeting papers, the minutes (محضر اجتماع لقاء المهن) and the survey (استبيان لقاء أصحاب المهن), filled from the
 * saved meeting. Only a final meeting prints (the desktop refused drafts). The reader needs `profession_meetings.view` in the meeting's city
 * (ProfessionMeetingService::form checks and audits the view); each print is audited `papers.printed`.
 */
final class MeetingPaper
{
    public const KINDS = ['minutes', 'survey'];

    public function __construct(private ProfessionMeetingService $meetings, private AccountAuditTrail $audit) {}

    /** @return array<string, mixed> */
    public function forPrint(WebAccount $account, int $meetingId, string $kind): array
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $page = $this->meetings->form($account, $meetingId);
        $meeting = $page['meeting'];
        if ($meeting->record_state !== 'final') {
            abort(409, __('papers.meeting_draft'));
        }
        $en = app()->isLocale('en');
        $state = $page['state'];
        $catalog = $this->meetings->catalog();
        $careers = $this->meetings->careers()->whereIn('id', $state['careerIds'])->pluck('label')->all();
        $registrar = fn ($id) => $id ? (string) DB::table('web_accounts')->where('id', $id)->value('username') : '';
        $city = $page['city'];
        $this->audit->record($account->id, 'papers.printed', 'succeeded', (int) $city->id, subjectType: 'meeting_entity', subjectId: $meetingId, decision: 'paper:MEETING_'.strtoupper($kind));

        $invited = (int) ($state['invited'] === '' ? 0 : $state['invited']);
        $attendance = (int) ($state['attendance'] === '' ? 0 : $state['attendance']);

        return [
            'kind' => $kind, 'meeting' => (int) $meeting->id, 'city' => ['id' => (int) $city->id, 'code' => (string) $city->code, 'name' => $en ? $city->name_en : $city->name_ar],
            'careers' => $careers, 'date' => (string) $state['date'], 'place' => (string) $state['place'], 'invited' => $state['invited'], 'attendance' => $state['attendance'],
            'absence' => $invited >= $attendance && $state['invited'] !== '' ? $invited - $attendance : '', 'paper_registrar' => $registrar($meeting->paper_registrar_account_id),
            'program_registrar' => $registrar($meeting->program_registrar_account_id), 'state' => $state, 'sections' => $catalog['sections'], 'en' => $en,
        ];
    }
}
