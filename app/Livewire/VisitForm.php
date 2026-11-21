<?php

namespace App\Livewire;

use App\Services\Field\VisitKinds;
use App\Services\Field\VisitService;
use App\Services\Finance\Fields;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The opening, closing or rehabilitation closing questionnaire of a project (M4.12). Every write goes through VisitService, which rechecks
 * the capabilities, the project kind and the version of what the page showed.
 */
class VisitForm extends Component
{
    #[Locked]
    public int $projectId;

    #[Locked]
    public string $kind;

    public string $version = '';

    public string $date = '';

    public string $vehicle = '';

    public string $street_id = '';

    public string $place_note = '';

    public string $indicators = '';

    public string $office_comments = '';

    /** @var array<int, array{value: string, text: string}> */
    public array $q = [];

    /** @var array<int, array{value: string, text: string}> */
    public array $office = [];

    /** @var list<string> */
    public array $visitors = ['', '', '', ''];

    public string $notice = '';

    public function mount(int $projectId, string $kind): void
    {
        $this->projectId = $projectId;
        $this->kind = $kind;
        $this->load();
    }

    private function load(): void
    {
        $form = app(VisitService::class)->form(auth()->user(), $this->projectId, $this->kind);
        $visit = $form['visit'];
        $project = $form['project'];
        $this->version = $form['version'];
        $this->date = $visit ? Fields::day($visit->visit_date) : now()->toDateString();
        $this->vehicle = (string) ($visit->vehicle_number ?? '');
        $this->street_id = (string) ($project->street_id ?? '');
        $this->place_note = (string) ($project->address_after_fund ?? '');
        $this->indicators = (string) ($visit->indicators ?? '');
        $this->office_comments = (string) ($visit->office_comments ?? '');
        $slots = $this->kind === VisitKinds::REHAB ? array_keys(VisitKinds::REHAB_QUESTIONS) : array_keys($form['definition']['questions']);
        $form_name = $this->kind === VisitKinds::REHAB ? 'rehab' : 'visit';
        $this->q = [];
        foreach ($slots as $slot) {
            $row = $form['answers'][$form_name][$slot] ?? null;
            $this->q[$slot] = ['value' => $row === null ? '' : (string) ($row->answer_value ?? ''), 'text' => (string) ($row->answer_text ?? '')];
            if ($this->kind !== VisitKinds::REHAB && $this->q[$slot]['value'] === (string) VisitKinds::UNSET) {
                $this->q[$slot]['value'] = '';
            }
        }
        $this->office = [];
        for ($slot = 1; $slot <= ($form['definition']['office'] ?? 0); $slot++) {
            $row = $form['answers']['office_monitoring'][$slot] ?? null;
            $value = $row === null ? '' : (string) ($row->answer_value ?? '');
            $this->office[$slot] = ['value' => $value === (string) VisitKinds::UNSET ? '' : $value, 'text' => (string) ($row->answer_text ?? '')];
        }
        $this->visitors = array_pad(array_map('strval', $form['visitors']), VisitKinds::MAX_VISITORS, '');
    }

    public function save(): void
    {
        $this->notice = '';
        $this->resetErrorBag();
        $this->validate(['date' => ['nullable', 'string', 'max:10'], 'vehicle' => ['nullable', 'string', 'max:100'], 'street_id' => ['nullable', 'string', 'max:20'], 'place_note' => ['nullable', 'string', 'max:1000'],
            'indicators' => ['nullable', 'string', 'max:65535'], 'office_comments' => ['nullable', 'string', 'max:65535'], 'q' => ['array', 'max:15'], 'q.*.value' => ['nullable', 'string', 'max:3'],
            'q.*.text' => ['nullable', 'string', 'max:65535'], 'office' => ['array', 'max:6'], 'office.*.value' => ['nullable', 'string', 'max:3'], 'office.*.text' => ['nullable', 'string', 'max:65535'],
            'visitors' => ['array', 'max:'.VisitKinds::MAX_VISITORS], 'visitors.*' => ['nullable', 'string', 'max:20']]);
        $input = ['date' => $this->date, 'vehicle' => $this->vehicle, 'street_id' => $this->street_id, 'place_note' => $this->place_note, 'indicators' => $this->indicators,
            'office_comments' => $this->office_comments, 'q' => $this->q, 'office' => $this->office, 'visitors' => $this->visitors];
        try {
            app(VisitService::class)->save(auth()->user(), $this->projectId, $this->kind, $input, $this->version);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->load();
        $this->notice = __('visits.saved');
    }

    public function delete(): void
    {
        $this->resetErrorBag();
        try {
            app(VisitService::class)->delete(auth()->user(), $this->projectId, $this->kind, $this->version);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        session()->flash('status', __('visits.deleted'));
        $this->skipRender();
        $this->redirectRoute('visits.hub', ['locale' => app()->getLocale(), 'project' => $this->projectId]);
    }

    public function render(VisitService $visits): View
    {
        return view('livewire.visit-form', ['form' => $visits->form(auth()->user(), $this->projectId, $this->kind)]);
    }
}
