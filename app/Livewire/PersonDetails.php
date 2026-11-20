<?php

namespace App\Livewire;

use App\Services\People\PeopleService;
use App\Services\People\PersonFields;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** One person sub-table (education, work experience, courses, losses, illnesses…) as an editable grid, like the desktop grids. */
class PersonDetails extends Component
{
    #[Locked]
    public int $personId;

    #[Locked]
    public string $kind;

    /** @var array<string, mixed> */
    public array $draft = [];

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $edit = [];

    public bool $adding = false;

    public function mount(int $personId, string $kind): void
    {
        abort_unless(isset(PersonFields::DETAILS[$kind]), 404);
        $this->personId = $personId;
        $this->kind = $kind;
        $this->resetDraft();
    }

    private function fields(): array
    {
        return PersonFields::DETAILS[$this->kind]['fields'];
    }

    private function resetDraft(): void
    {
        $this->draft = array_fill_keys(array_keys($this->fields()), '');
    }

    private function attempt(callable $action): bool
    {
        $this->resetErrorBag();
        try {
            $action();

            return true;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return false;
        }
    }

    public function add(): void
    {
        if ($this->attempt(fn () => app(PeopleService::class)->saveDetail(auth()->user(), $this->kind, $this->personId, null, $this->draft))) {
            $this->resetDraft();
            $this->adding = false;
        }
    }

    public function startEdit(int $id): void
    {
        $row = app(PeopleService::class)->details(auth()->user(), $this->kind, $this->personId)->firstWhere('id', $id) ?? abort(404);
        $this->editing = $id;
        $this->edit = [];
        foreach ($this->fields() as $field => $spec) {
            $value = $row->{$field};
            $this->edit[$field] = $spec['kind'] === 'bool' ? ($value === null ? '' : ((bool) $value ? '1' : '0')) : (string) ($value ?? '');
        }
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->adding = false;
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        if ($this->editing !== null && $this->attempt(fn () => app(PeopleService::class)->saveDetail(auth()->user(), $this->kind, $this->personId, $this->editing, $this->edit))) {
            $this->editing = null;
        }
    }

    public function remove(int $id): void
    {
        app(PeopleService::class)->deleteDetail(auth()->user(), $this->kind, $this->personId, $id);
        $this->editing === $id && $this->cancel();
    }

    public function render(): View
    {
        $service = app(PeopleService::class);
        $account = auth()->user();
        $person = $service->person($account, $this->personId);
        $editable = $service->canManage($account, (int) $person->city_id);

        return view('livewire.person-details', [
            'fields' => $this->fields(), 'rows' => $service->details($account, $this->kind, $this->personId), 'editable' => $editable,
            'options' => $editable ? $service->optionsFor($this->fields(), (int) $person->city_id, $this->editing ? $this->edit : []) : [],
        ]);
    }
}
