<?php

namespace App\Livewire;

use App\Services\Field\SpecialCaseService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The special cases of a project (M4.13): the cases newest first and the form (desktop field order: date, recorder, type, reason,
 * description, action) for a new case or the one being edited. Every write goes through SpecialCaseService, which rechecks the
 * capabilities and the version of what the page showed.
 */
class ProjectSpecialCases extends Component
{
    #[Locked]
    public int $projectId;

    /** The case being edited; null for a new one. */
    #[Locked]
    public ?int $editing = null;

    public string $version = '';

    public string $date = '';

    public string $recorder = '';

    public string $type = '';

    public string $reason = '';

    public string $description = '';

    public string $action = '';

    public string $notice = '';

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
        $this->fresh(app(SpecialCaseService::class)->page(auth()->user(), $projectId));
    }

    /** An empty form with the defaults (today, the signed-in account, the first type), and the current version. */
    private function fresh(array $page): void
    {
        $this->editing = null;
        $this->version = $page['version'];
        $this->date = $page['defaults']['date'];
        $this->recorder = $page['defaults']['recorder'];
        $this->type = $page['defaults']['type'];
        $this->reason = $this->description = $this->action = '';
    }

    public function startNew(): void
    {
        $this->resetErrorBag();
        $this->notice = '';
        $this->fresh(app(SpecialCaseService::class)->page(auth()->user(), $this->projectId));
    }

    public function edit(int $id): void
    {
        $this->resetErrorBag();
        $this->notice = '';
        $page = app(SpecialCaseService::class)->page(auth()->user(), $this->projectId);
        $case = $page['cases']->firstWhere('id', $id);
        abort_if($case === null || ! $page['canManage'], 404);
        $this->editing = $id;
        $this->version = $page['version'];
        $this->date = $case->date;
        $this->recorder = (string) ($case->recorder_id ?? '');
        $this->type = (string) ($case->type_id ?? '');
        $this->reason = $case->reason;
        $this->description = $case->description;
        $this->action = $case->action;
    }

    public function save(): void
    {
        $this->notice = '';
        $this->resetErrorBag();
        $this->validate(['date' => ['nullable', 'string', 'max:10'], 'recorder' => ['nullable', 'string', 'max:20'], 'type' => ['nullable', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:'.(SpecialCaseService::REASON_MAX + 200)], 'description' => ['nullable', 'string', 'max:'.SpecialCaseService::TEXT_MAX],
            'action' => ['nullable', 'string', 'max:'.SpecialCaseService::TEXT_MAX]]);
        $service = app(SpecialCaseService::class);
        try {
            $service->save(auth()->user(), $this->projectId, $this->editing, ['date' => $this->date, 'recorder' => $this->recorder, 'type' => $this->type,
                'reason' => $this->reason, 'description' => $this->description, 'action' => $this->action], $this->version);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->fresh($service->page(auth()->user(), $this->projectId));
        $this->notice = __('special_cases.saved');
    }

    public function delete(int $id): void
    {
        $this->resetErrorBag();
        $this->notice = '';
        $service = app(SpecialCaseService::class);
        try {
            $service->delete(auth()->user(), $this->projectId, $id, $this->version);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->fresh($service->page(auth()->user(), $this->projectId));
        $this->notice = __('special_cases.deleted');
    }

    public function render(SpecialCaseService $cases): View
    {
        return view('livewire.project-special-cases', ['page' => $cases->page(auth()->user(), $this->projectId)]);
    }
}
