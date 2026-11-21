<?php

namespace App\Livewire;

use App\Services\Lifecycle\LifecycleService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The state card of the project page (M4.8): current state, the administrator's state change (Accepted asks for the donor in the same
 * step) and the reason/note of the state in force, and the history. A change reloads the page, so the badge and stage bar follow.
 */
class ProjectLifecycle extends Component
{
    #[Locked]
    public int $projectId;

    /** @var array{state_id: string, reason_id: string, note: string, donor_id: string} */
    public array $change = ['state_id' => '', 'reason_id' => '', 'note' => '', 'donor_id' => ''];

    /** @var array{reason_id: string, note: string} */
    public array $currentForm = ['reason_id' => '', 'note' => ''];

    public string $version = '';

    public string $notice = '';

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
        $this->load();
    }

    private function load(): void
    {
        $panel = app(LifecycleService::class)->panel(auth()->user(), $this->projectId);
        $this->version = $panel['version'];
        $this->currentForm = ['reason_id' => (string) ($panel['current']->state_reason_id ?? ''), 'note' => (string) ($panel['current']->note ?? '')];
        $this->change['donor_id'] = (string) ($panel['project']->donor_id ?? '');
    }

    private function optionalId(string $value): ?int
    {
        return $value === '' ? null : (int) $value;
    }

    public function changeState(): void
    {
        $this->resetErrorBag();
        $this->validate([
            'change.state_id' => ['required', 'integer', 'min:1'], 'change.reason_id' => ['nullable', 'integer', 'min:1'],
            'change.note' => ['nullable', 'string', 'max:5000'], 'change.donor_id' => ['nullable', 'integer', 'min:1'],
        ], attributes: ['change.state_id' => __('lifecycle.new_state')]);
        if (! $this->save(fn (LifecycleService $s) => $s->transition(auth()->user(), $this->projectId, (int) $this->change['state_id'], $this->optionalId($this->change['reason_id']),
            trim($this->change['note']) === '' ? null : trim($this->change['note']), $this->optionalId($this->change['donor_id']), $this->version), 'change')) {
            return;
        }
        session()->flash('status', __('lifecycle.saved'));
        $this->redirectRoute('projects.show', ['locale' => app()->getLocale(), 'project' => $this->projectId]);
    }

    public function saveCurrent(): void
    {
        $this->resetErrorBag();
        $this->validate(['currentForm.reason_id' => ['nullable', 'integer', 'min:1'], 'currentForm.note' => ['nullable', 'string', 'max:5000']]);
        if (! $this->save(fn (LifecycleService $s) => $s->updateCurrent(auth()->user(), $this->projectId, $this->optionalId($this->currentForm['reason_id']),
            trim($this->currentForm['note']) === '' ? null : trim($this->currentForm['note']), $this->version), 'currentForm')) {
            return;
        }
        $this->load();
        $this->notice = __('lifecycle.reason_saved');
    }

    private function save(callable $action, string $form): bool
    {
        $this->notice = '';
        try {
            $action(app(LifecycleService::class));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'version' ? 'version' : $form.'.'.$field, $messages[0]);
            }

            return false;
        }

        return true;
    }

    public function render(LifecycleService $service): View
    {
        return view('livewire.project-lifecycle', ['panel' => $service->panel(auth()->user(), $this->projectId), 'accepted' => $this->acceptedId()]);
    }

    /** The Accepted state's id, so the donor field shows only when it is chosen. */
    private function acceptedId(): ?string
    {
        $id = DB::table('project_states')->where('name_en', LifecycleService::ACCEPTED)->value('id');

        return $id === null ? null : (string) $id;
    }
}
