<?php

namespace App\Livewire;

use App\Services\Field\ProfessionMeetingService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The meeting form (M4.15): header, the 22 survey questions in their five axes, the attendees and the minutes. "Save draft" keeps an
 * incomplete meeting; "Save as final" needs every answer and locks it (an administrator reopens). The service rechecks everything.
 */
class ProfessionMeetingForm extends Component
{
    #[Locked]
    public ?int $meetingId = null;

    #[Locked]
    public int $city = 0;

    #[Locked]
    public string $version = '';

    #[Locked]
    public bool $locked = false;

    /** @var array<string, mixed> */
    public array $state = [];

    public ?string $notice = null;

    public function mount(?int $meetingId = null, int $city = 0): void
    {
        $service = app(ProfessionMeetingService::class);
        if ($meetingId !== null) {
            $page = $service->form(auth()->user(), $meetingId);
            $this->meetingId = $meetingId;
            $this->city = (int) $page['meeting']->city_id;
            $this->state = $page['state'];
            $this->version = $page['version'];
            $this->locked = $page['locked'];
        } else {
            $service->create(auth()->user(), $city);
            $this->city = $city;
            $this->state = $service->blank();
        }
    }

    public function toggleCareer(int $career): void
    {
        $ids = array_map('intval', $this->state['careerIds'] ?? []);
        $this->state['careerIds'] = in_array($career, $ids, true) ? array_values(array_diff($ids, [$career])) : [...$ids, $career];
    }

    /** Adds an empty row to a table of the form (`part` takes the question id). */
    public function addRow(string $table, ?int $question = null): void
    {
        $rows = ['seasons' => ['n' => '', 'from' => '', 'to' => '', 'reason' => ''], 'equipment' => ['spec' => '', 'items' => ''], 'challenges' => ['name' => '', 'n' => '', 'solution' => ''],
            'attendees' => ['project' => '', 'name' => '', 'speciality' => ''], 'part' => ['project' => '', 'name' => '', 'speciality' => '']];
        abort_unless(isset($rows[$table]) && ! $this->locked, 422);
        if ($table === 'part') {
            abort_if($question === null, 422);
            $this->state['part'][$question][] = $rows['part'];
        } else {
            $this->state[$table][] = $rows[$table];
        }
    }

    public function removeRow(string $table, int $index, ?int $question = null): void
    {
        abort_unless(in_array($table, ['seasons', 'equipment', 'challenges', 'attendees', 'part'], true) && ! $this->locked, 422);
        if ($table === 'part') {
            unset($this->state['part'][$question][$index]);
            $this->state['part'][$question] = array_values($this->state['part'][$question] ?? []);
        } else {
            unset($this->state[$table][$index]);
            $this->state[$table] = array_values($this->state[$table]);
        }
    }

    /** Eligibility check: adds a beneficiary of the meeting's professions to the attendees. */
    public function addAttendee(string $project, string $name): void
    {
        abort_if($this->locked, 422);
        foreach ($this->state['attendees'] ?? [] as $row) {
            if (($row['project'] ?? '') === $project) {
                return;
            }
        }
        $this->state['attendees'][] = ['project' => $project, 'name' => $name, 'speciality' => ''];
    }

    public function saveDraft(): void
    {
        $this->persist(false);
    }

    public function saveFinal(): void
    {
        $this->persist(true);
    }

    private function persist(bool $final): void
    {
        $this->resetErrorBag();
        $id = app(ProfessionMeetingService::class)->save(auth()->user(), $this->meetingId, $this->meetingId === null ? $this->city : null, $this->state, $final, $this->version);
        session()->flash('status', __($final ? 'field.meeting.saved_final' : 'field.meeting.saved_draft'));
        $this->redirectRoute('meetings.show', ['locale' => app()->getLocale(), 'meeting' => $id], navigate: false);
    }

    public function reopen(): void
    {
        abort_if($this->meetingId === null, 404);
        app(ProfessionMeetingService::class)->reopen(auth()->user(), $this->meetingId);
        session()->flash('status', __('field.meeting.reopened'));
        $this->redirectRoute('meetings.show', ['locale' => app()->getLocale(), 'meeting' => $this->meetingId], navigate: false);
    }

    public function delete(): void
    {
        abort_if($this->meetingId === null, 404);
        app(ProfessionMeetingService::class)->delete(auth()->user(), $this->meetingId, $this->version);
        session()->flash('status', __('field.deleted'));
        $this->redirectRoute('meetings.index', ['locale' => app()->getLocale()], navigate: false);
    }

    public function render(ProfessionMeetingService $service): View
    {
        $account = auth()->user();
        $access = app(\App\Services\AccountAccess::class);
        $careerIds = array_map('intval', $this->state['careerIds'] ?? []);

        return view('livewire.profession-meeting-form', [
            'catalog' => $service->catalog(), 'careers' => $service->careers(), 'levels' => ProfessionMeetingService::LEVELS,
            'cityRow' => \Illuminate\Support\Facades\DB::table('cities')->where('id', $this->city)->first(),
            'canManage' => $access->check($account, $this->city, 'profession_meetings.manage') && ! $this->locked,
            'canDelete' => $this->meetingId !== null && $access->check($account, $this->city, 'profession_meetings.delete') && (! $this->locked || $access->check($account, $this->city, 'accounts.manage')),
            'canReopen' => $this->locked && $access->check($account, $this->city, 'accounts.manage'),
            'beneficiaries' => $service->beneficiaries($account, $this->city, $careerIds),
        ]);
    }
}
