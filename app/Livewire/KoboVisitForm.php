<?php

namespace App\Livewire;

use App\Services\Kobo\KoboCatalog;
use App\Services\Kobo\KoboVisitService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One Kobo visit form on the web (M4.14b): new (submissionId null, the form chosen first) or stored. Answers are bound by the field's
 * position in the form (`a.{index}`, `r.{group}.{row}.{index}`) because Kobo paths hold characters Livewire keys cannot. Writes go
 * through KoboVisitService, which checks the capabilities again.
 */
class KoboVisitForm extends Component
{
    #[Locked]
    public int $projectId;

    #[Locked]
    public ?int $submissionId = null;

    public string $formUid = '';

    public string $person = '';

    public string $date = '';

    /** @var array<int, mixed> field index => answer (a list of codes for select_multiple) */
    public array $a = [];

    /** @var array<int, list<array<int, mixed>>> repeat group => rows of field index => answer */
    public array $r = [];

    /** @var list<string> */
    public array $visitors = ['', '', '', ''];

    public string $version = '';

    public string $notice = '';

    public function mount(int $projectId, ?int $submissionId = null, ?string $start = null): void
    {
        $this->projectId = $projectId;
        $this->submissionId = $submissionId;
        $this->formUid = (string) $start;
        $this->date = now()->toDateString();
        $this->load();
    }

    private function service(): KoboVisitService
    {
        return app(KoboVisitService::class);
    }

    public function updatedFormUid(): void
    {
        $this->resetErrorBag();
        $this->a = [];
        $this->r = [];
        $this->load();
    }

    /** @return list<string> */
    private function codes(mixed $value): array
    {
        return is_array($value) ? array_map('strval', $value) : (preg_split('/\s+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private function answer(array $field, mixed $value): mixed
    {
        return $field['kind'] === 'select_multiple' ? $this->codes($value) : (is_array($value) ? implode(' | ', $value) : (string) ($value ?? ''));
    }

    private function load(): void
    {
        $form = $this->service()->form(auth()->user(), $this->projectId, $this->submissionId, $this->formUid);
        $this->version = $form['version'];
        $submission = $form['submission'];
        if ($submission !== null) {
            $this->formUid = (string) $submission->form_uid;
            $this->date = $form['date'] !== '' ? $form['date'] : $this->date;
        }
        $this->person = $form['record'] ? (string) $form['record']->person_id : ($this->person !== '' ? $this->person : (string) ($form['people']->first()->id ?? ''));
        $visitors = $form['visitors']->pluck('account_id')->filter()->map(fn ($id) => (string) $id)->all();
        $this->visitors = array_pad($visitors ?: $this->visitors, KoboVisitService::MAX_VISITORS, '');
        if ($form['spec'] === null || $submission === null) {
            return;
        }
        $raw = $form['raw'];
        foreach ($form['spec']['fields'] as $field) {
            if ($field['group'] === null && ! in_array($field['role'], ['project', 'person', 'visitor'], true)) {
                $this->a[$field['index']] = $this->answer($field, KoboCatalog::lookup($raw, $field['path']));
            }
        }
        foreach ($form['spec']['groups'] as $group => $g) {
            $rows = [];
            foreach ((array) ($raw[$group] ?? []) as $repetition) {
                $row = [];
                foreach ($form['spec']['fields'] as $field) {
                    if ($field['group'] === $group) {
                        $row[$field['index']] = $this->answer($field, KoboCatalog::lookup((array) $repetition, $field['path']));
                    }
                }
                $rows[] = $row;
            }
            $this->r[$g] = $rows;
        }
    }

    public function addRow(int $group): void
    {
        count($this->r[$group] ?? []) < 30 && $this->r[$group][] = [];
    }

    public function removeRow(int $group, int $row): void
    {
        unset($this->r[$group][$row]);
        $this->r[$group] = array_values($this->r[$group] ?? []);
    }

    private function errors(ValidationException $e): void
    {
        foreach ($e->errors() as $field => $messages) {
            $this->addError($field, $messages[0]);
        }
    }

    public function save(): void
    {
        $this->notice = '';
        $this->resetErrorBag();
        $this->validate(['formUid' => ['required', 'string', 'max:64'], 'person' => ['nullable', 'string', 'max:20'], 'date' => ['nullable', 'string', 'max:10'], 'a' => ['array', 'max:200'],
            'r' => ['array', 'max:10'], 'visitors' => ['array', 'max:'.KoboVisitService::MAX_VISITORS], 'visitors.*' => ['nullable', 'string', 'max:20']]);
        $input = ['form' => $this->formUid, 'person' => $this->person, 'date' => $this->date, 'a' => $this->a, 'r' => $this->r, 'visitors' => $this->visitors];
        try {
            $id = $this->service()->save(auth()->user(), $this->projectId, $this->submissionId, $input, $this->version);
        } catch (ValidationException $e) {
            $this->errors($e);

            return;
        }
        $this->dispatch('kobo-saved');
        if ($this->submissionId === null) {
            session()->flash('status', __('kobo.saved'));
            $this->skipRender();
            $this->redirectRoute('kobo.visit.show', ['locale' => app()->getLocale(), 'submission' => $id]);

            return;
        }
        $this->load();
        $this->notice = __('kobo.saved');
    }

    public function delete(): void
    {
        $this->resetErrorBag();
        try {
            $this->service()->delete(auth()->user(), $this->projectId, (int) $this->submissionId, $this->version);
        } catch (ValidationException $e) {
            $this->errors($e);

            return;
        }
        session()->flash('status', __('kobo.deleted'));
        $this->skipRender();
        $this->redirectRoute('kobo.index', ['locale' => app()->getLocale()]);
    }

    public function render(): View
    {
        $form = $this->service()->form(auth()->user(), $this->projectId, $this->submissionId, $this->formUid);

        return view('livewire.kobo-visit-form', ['form' => $form, 'hidden' => $form['spec'] === null ? ['flat' => [], 'rows' => []] : $this->service()->hidden($form['spec'], $this->a, $this->r)]);
    }
}
