<?php

namespace App\Livewire;

use App\Services\Field\MonitoringVisitService;
use App\Services\Field\VisitKinds;
use App\Services\Finance\Fields;
use App\Services\Finance\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** One M&E visit of a person of the project (M4.12): new (visitId null) or stored. Writes go through MonitoringVisitService. */
class MeVisitForm extends Component
{
    #[Locked]
    public int $projectId;

    #[Locked]
    public int $personId;

    #[Locked]
    public ?int $visitId = null;

    public string $version = '';

    public string $number = '';

    public string $date = '';

    public string $profit = '';

    public string $answer_1 = '';

    public string $indicators = '';

    public string $notes = '';

    /** @var array<int, string> question id => answer id */
    public array $a = [];

    /** @var array<int, string> question id => note */
    public array $n = [];

    /** @var list<array{account: string, mark: string}> */
    public array $visitors = [['account' => '', 'mark' => ''], ['account' => '', 'mark' => '']];

    public string $notice = '';

    public function mount(int $projectId, int $personId, ?int $visitId = null, ?string $suggested = null): void
    {
        $this->projectId = $projectId;
        $this->personId = $personId;
        $this->visitId = $visitId;
        $this->load();
        if ($visitId === null && $suggested !== null && preg_match('/\A[1-9]\z/', $suggested) === 1) {
            $this->number = $suggested;
        }
    }

    private function service(): MonitoringVisitService
    {
        return app(MonitoringVisitService::class);
    }

    private function load(): void
    {
        $form = $this->service()->form(auth()->user(), $this->projectId, $this->visitId === null ? $this->personId : null, $this->visitId);
        $visit = $form['visit'];
        $this->version = $form['version'];
        $this->number = $visit ? (string) $visit->visit_number : (string) $form['next'];
        $this->date = $visit ? Fields::day($visit->visit_date) : now()->toDateString();
        $this->profit = $visit ? (Money::canonical($visit->profit) ?? '') : '';
        $this->answer_1 = (string) ($visit->answer_1 ?? '');
        $this->indicators = (string) ($visit->indicators ?? '');
        $this->notes = (string) ($visit->notes ?? '');
        $this->a = [];
        $this->n = [];
        foreach ($form['sections'] as $section) {
            foreach ($section->questions as $question) {
                $given = $form['given'][$question->id] ?? null;
                $this->a[$question->id] = $given === null ? '' : (string) ($given->me_answer_id ?? '');
                $this->n[$question->id] = (string) ($given->notes ?? '');
            }
        }
        $this->visitors = array_pad(array_map(fn ($r) => ['account' => (string) $r->account_id, 'mark' => (string) ($r->mark ?? '')], $form['visitors']), VisitKinds::MAX_ME_VISITORS, ['account' => '', 'mark' => '']);
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
        $this->validate(['number' => ['nullable', 'string', 'max:3'], 'date' => ['nullable', 'string', 'max:10'], 'profit' => ['nullable', 'string', 'max:30'],
            'answer_1' => ['nullable', 'string', 'max:65535'], 'indicators' => ['nullable', 'string', 'max:65535'], 'notes' => ['nullable', 'string', 'max:65535'],
            'a' => ['array', 'max:80'], 'a.*' => ['nullable', 'string', 'max:20'], 'n' => ['array', 'max:80'], 'n.*' => ['nullable', 'string', 'max:65535'],
            'visitors' => ['array', 'max:'.VisitKinds::MAX_VISITORS], 'visitors.*.account' => ['nullable', 'string', 'max:20'], 'visitors.*.mark' => ['nullable', 'string', 'max:20']]);
        $input = ['number' => $this->number, 'date' => $this->date, 'profit' => $this->profit, 'answer_1' => $this->answer_1, 'indicators' => $this->indicators, 'notes' => $this->notes,
            'a' => $this->a, 'n' => $this->n, 'visitors' => $this->visitors];
        try {
            $id = $this->service()->save(auth()->user(), $this->projectId, $this->personId, $this->visitId, $input, $this->version);
        } catch (ValidationException $e) {
            $this->errors($e);

            return;
        }
        if ($this->visitId === null) {
            // The desktop's Partners_MessageBox: offer the same visit number for a partner who has none yet.
            $project = DB::table('projects')->where('id', $this->projectId)->first();
            $partner = $this->service()->partnersWithout($project, $this->personId, $this->number)->first();
            session()->flash('status', __('visits.saved'));
            if ($partner !== null) {
                session()->flash('partner', ['id' => $partner->id, 'name' => $partner->name, 'number' => $this->number]);
            }
            $this->skipRender();
            $this->redirectRoute('visits.me.show', ['locale' => app()->getLocale(), 'project' => $this->projectId, 'visit' => $id]);

            return;
        }
        $this->load();
        $this->notice = __('visits.saved');
    }

    public function delete(): void
    {
        $this->resetErrorBag();
        try {
            $this->service()->delete(auth()->user(), $this->projectId, (int) $this->visitId, $this->version);
        } catch (ValidationException $e) {
            $this->errors($e);

            return;
        }
        session()->flash('status', __('visits.deleted'));
        $this->skipRender();
        $this->redirectRoute('visits.hub', ['locale' => app()->getLocale(), 'project' => $this->projectId]);
    }

    /** The result as the current answers and profit would give it (preview; the stored result is computed again on save). */
    private function preview(array $form): ?int
    {
        $profit = Money::normalize(trim($this->profit));
        if (preg_match('/\A-?\d{1,13}(?:\.\d{1,2})?\z/', $profit) !== 1) {
            return null;
        }
        $mark = DB::table('me_profit_marks')->where('is_active', true)->where('min_value', '<=', $profit)->where('max_value', '>=', $profit)->orderBy('sort_order')->value('mark');
        $sum = (int) $mark;
        foreach ($form['sections'] as $section) {
            foreach ($section->questions as $question) {
                $answer = $question->answers->firstWhere('id', (int) ($this->a[$question->id] ?? 0));
                if ($answer === null) {
                    return null;
                }
                $sum += $answer->mark;
            }
        }

        return $mark === null ? null : $sum;
    }

    public function render(MonitoringVisitService $me): View
    {
        $form = $me->form(auth()->user(), $this->projectId, $this->visitId === null ? $this->personId : null, $this->visitId);
        $preview = $this->preview($form);

        return view('livewire.me-visit-form', ['form' => $form, 'preview' => $preview, 'previewEvaluation' => $me->evaluation($preview)]);
    }
}
