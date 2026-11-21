<?php

namespace App\Livewire;

use App\Services\Applications\ProjectAnnexService;
use App\Services\People\PeopleService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The agriculture or rehabilitation annex of a project (M4.6). A single-answer question holds one answer id (a string); a
 * multiple-answer question, or an old record that already holds several answers, holds a list.
 */
class ProjectAnnex extends Component
{
    #[Locked]
    public int $projectId;

    #[Locked]
    public string $kind = '';

    /** @var array<int|string, string|list<string>> agriculture: question => answer(s) */
    public array $answers = [];

    /** @var array<string, string> rehabilitation header */
    public array $header = [];

    /** @var array<int|string, array<string, mixed>> rehabilitation: question => answers, note, quantity, price, result */
    public array $items = [];

    public bool $exists = false;

    public string $message = '';

    public function mount(int $projectId): void
    {
        $service = app(ProjectAnnexService::class);
        $project = $service->project(auth()->user(), $projectId);
        $this->kind = $service->kind($project) ?? abort(404);
        $this->projectId = $projectId;
        $this->load();
    }

    private function load(): void
    {
        $service = app(ProjectAnnexService::class);
        $project = $service->project(auth()->user(), $this->projectId);
        $questions = $service->questions($this->kind);
        $shape = fn (object $q, array $ids) => ! $q->multiple && count($ids) <= 1 ? (string) ($ids[0] ?? '') : array_map('strval', $ids);
        if ($this->kind === 'agriculture') {
            $saved = $service->agriculture($this->projectId);
            $this->exists = $saved !== [];
            $this->answers = [];
            foreach ($questions as $q) {
                $this->answers[$q->id] = $shape($q, $saved[$q->id] ?? []);
            }
        } else {
            $saved = $service->rehabilitation($project);
            $this->exists = $saved['exists'];
            $this->header = array_map(fn ($v) => (string) ($v ?? ''), array_intersect_key($saved['header'], array_flip(['property_ownership', 'property_area', 'street_id', 'address_after_fund'])));
            $this->items = [];
            foreach ($questions as $q) {
                $item = $saved['items'][$q->id] ?? ['answers' => [], 'note' => '', 'quantity' => '', 'price' => '', 'result' => ''];
                $item['answers'] = $shape($q, $item['answers']);
                $this->items[$q->id] = $item;
            }
        }
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $this->message = '';
        $service = app(ProjectAnnexService::class);
        try {
            if ($this->kind === 'agriculture') {
                $service->saveAgriculture(auth()->user(), $this->projectId, array_map(fn ($v) => (array) $v, $this->answers));
            } else {
                $items = array_map(fn ($item) => ['answers' => (array) $item['answers']] + $item, $this->items);
                $service->saveRehabilitation(auth()->user(), $this->projectId, $this->header, $items);
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
            $this->dispatch('form-errors');

            return;
        }
        $this->load();
        $this->message = __('annexes.saved');
    }

    public function remove(): void
    {
        app(ProjectAnnexService::class)->delete(auth()->user(), $this->projectId, $this->kind);
        $this->load();
        $this->message = __('annexes.deleted');
    }

    public function render(): View
    {
        $service = app(ProjectAnnexService::class);
        $project = $service->project(auth()->user(), $this->projectId);
        $questions = $service->questions($this->kind);
        $total = 0.0;
        foreach ($this->items as $item) {
            $q = (float) str_replace(',', '', (string) ($item['quantity'] ?? ''));
            $p = (float) str_replace(',', '', (string) ($item['price'] ?? ''));
            $total += $q * $p;
        }

        return view('livewire.project-annex', [
            'project' => $project, 'questions' => $questions, 'editable' => $service->canWrite(auth()->user(), $project),
            'groups' => $questions->groupBy(fn ($q) => config('annexes.rehabilitation.groups')[$q->type] ?? 'other'),
            'streets' => $this->kind === 'rehabilitation' ? app(PeopleService::class)->options('streets', (int) $project->city_id, $this->header['street_id'] ?? null) : collect(),
            'total' => $total,
            'code' => DB::table('cities')->where('id', $project->city_id)->value('code'),
        ]);
    }
}
