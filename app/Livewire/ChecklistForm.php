<?php

namespace App\Livewire;

use App\Services\Assessments\ChecklistService;
use App\Services\Lookups\LookupAdminService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The checklist and assessment of an application (M4.7): one form, one save. */
class ChecklistForm extends Component
{
    #[Locked]
    public int $projectId;

    /** @var array<string, mixed> header, members (k/t/c rows), removed, exists, person_id */
    public array $form = [];

    public string $message = '';

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
        $this->form = app(ChecklistService::class)->load(auth()->user(), $projectId);
    }

    public function addMember(string $location): void
    {
        in_array($location, ChecklistService::LOCATIONS, true) || abort(404);
        $this->form['members'][$location][] = ['id' => null, 'checklist_member_id' => '', 'checklist_opinion_id' => '', 'notes' => '', 'name_notes' => '', 'role_notes' => ''];
    }

    public function removeMember(string $location, int $index): void
    {
        $row = $this->form['members'][$location][$index] ?? null;
        if ($row === null) {
            return;
        }
        if (! empty($row['id'])) {
            $this->form['removed'][] = (int) $row['id'];
        }
        unset($this->form['members'][$location][$index]);
        $this->form['members'][$location] = array_values($this->form['members'][$location]);
    }

    public function updated(string $property): void
    {
        // Answering "Yes" to the rejection message: the sender defaults to whoever records it.
        if ($property === 'form.header.rejection_message' && $this->form['header']['rejection_message'] === '1' && $this->form['header']['rejection_message_sender_account_id'] === '') {
            $this->form['header']['rejection_message_sender_account_id'] = (string) auth()->id();
            $this->form['header']['rejection_message_date'] = $this->form['header']['rejection_message_date'] ?: now()->format('Y-m-d');
        }
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $this->message = '';
        try {
            app(ChecklistService::class)->save(auth()->user(), $this->projectId, $this->form);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('form.'.$field, $messages[0]);
            }
            $this->dispatch('form-errors');

            return;
        }
        $this->form = app(ChecklistService::class)->load(auth()->user(), $this->projectId);
        $this->message = __('checklists.saved');
    }

    public function remove(): void
    {
        app(ChecklistService::class)->delete(auth()->user(), $this->projectId);
        $this->form = app(ChecklistService::class)->load(auth()->user(), $this->projectId);
        $this->message = __('checklists.deleted');
    }

    public function render(ChecklistService $service): View
    {
        $project = $service->project(auth()->user(), $this->projectId);
        $city = (int) $project->city_id;
        $keep = fn (string $location) => array_column($this->form['members'][$location] ?? [], 'checklist_member_id');
        $headerKeep = array_values(array_filter([$this->form['header']['home_visitor_1_id'], $this->form['header']['home_visitor_2_id'], $this->form['header']['project_visitor_1_id'],
            $this->form['header']['project_visitor_2_id'], $this->form['header']['professional_tester_id']]));

        return view('livewire.checklist-form', [
            'project' => $project, 'editable' => $service->canManage(auth()->user(), $project),
            'memberOptions' => ['k' => $service->members($city, 'k', $keep('k')), 't' => $service->members($city, 't', [...$keep('t'), ...$headerKeep]), 'c' => $service->members($city, 'c', $keep('c'))],
            'allMembers' => $service->members($city, null, $headerKeep), 'opinions' => $service->opinions(), 'need' => $service->need($this->projectId), 'senders' => $service->senders($city),
            'manageMembers' => app(LookupAdminService::class)->canEdit(auth()->user(), 'checklist_members', $city),
            'person' => $this->form['person_id'] ? DB::table('people')->where('id', $this->form['person_id'])->first() : null,
        ]);
    }
}
