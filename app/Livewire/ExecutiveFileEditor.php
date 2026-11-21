<?php

namespace App\Livewire;

use App\Services\Finance\ExecutiveFileService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The executive file of a project (M4.10): create, edit and delete it, with the expiry date shown live. Every write goes through
 * ExecutiveFileService, which rechecks the capabilities and the version of what the page showed.
 */
class ExecutiveFileEditor extends Component
{
    #[Locked]
    public int $projectId;

    /** The file this page shows, null while none exists (a second file for the project is refused by the service). */
    #[Locked]
    public ?int $fileId = null;

    public string $version = '';

    /** @var array{number: string, begin_date: string, current_date: string, months: string, impound_date: string, impound_type: string} */
    public array $form = ['number' => '', 'begin_date' => '', 'current_date' => '', 'months' => '5', 'impound_date' => '', 'impound_type' => ''];

    public string $notice = '';

    /** Shown after a save that found no legal account of the city to notify. */
    public bool $noLegalWarning = false;

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
        $this->load();
    }

    private function service(): ExecutiveFileService
    {
        return app(ExecutiveFileService::class);
    }

    private function load(): void
    {
        $panel = $this->service()->panel(auth()->user(), $this->projectId);
        $file = $panel['file'];
        $this->version = $panel['version'];
        $this->fileId = $file === null ? null : (int) $file->id;
        $this->form = $file === null ? ['number' => '', 'begin_date' => now()->toDateString(), 'current_date' => '', 'months' => '5', 'impound_date' => '', 'impound_type' => '']
            : ['number' => (string) $file->file_number, 'begin_date' => $file->begin_on, 'current_date' => $file->current_on, 'months' => (string) ($file->months ?? 0),
                'impound_date' => $file->impound_on, 'impound_type' => (string) ($file->impound_type ?? '')];
    }

    /** A new file's current date follows the begin date until it is changed (desktop ExeF_BeginDate_dateTimePicker_ValueChanged). */
    public function updatedFormBeginDate(string $value): void
    {
        if ($this->fileId === null) {
            $this->form['current_date'] = $value;
        }
    }

    public function save(): void
    {
        $this->notice = '';
        $this->noLegalWarning = false;
        $this->resetErrorBag();
        $this->validate(['form.*' => ['nullable', 'string', 'max:100']]);
        try {
            $result = $this->service()->save(auth()->user(), $this->projectId, $this->fileId, $this->form, $this->version);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'file' ? 'file' : 'form.'.$field, $messages[0]);
            }

            return;
        }
        $this->load();
        $this->notice = __($result['created'] ? 'executive.created' : 'executive.saved');
        $this->noLegalWarning = $result['created'] && $result['notices'] === 0;
    }

    public function delete(): void
    {
        $this->notice = '';
        $this->resetErrorBag();
        try {
            $this->service()->delete(auth()->user(), $this->projectId, (int) $this->fileId, $this->version);
        } catch (ValidationException $e) {
            $this->addError('file', $e->errors()['file'][0] ?? __('executive.errors.stale'));

            return;
        }
        $this->load();
        $this->noLegalWarning = false;
        $this->notice = __('executive.deleted');
    }

    public function render(ExecutiveFileService $service): View
    {
        $panel = $service->panel(auth()->user(), $this->projectId);
        $expires = ExecutiveFileService::expiry($this->form['current_date'] ?? '', $this->form['months'] ?? '');

        return view('livewire.executive-file-editor', ['panel' => $panel, 'expires' => $expires]);
    }
}
