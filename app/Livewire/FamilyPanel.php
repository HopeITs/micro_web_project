<?php

namespace App\Livewire;

use App\Services\People\PeopleService;
use App\Services\People\PersonFields;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The person's family (desktop FamilyMember grid): members with relation, education and work; add a new or existing person, edit, remove. */
class FamilyPanel extends Component
{
    #[Locked]
    public int $personId;

    public bool $adding = false;

    /** new | existing */
    public string $mode = 'new';

    /** @var array<string, mixed> */
    public array $member = [];

    public string $pickSearch = '';

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $edit = [];

    public function mount(int $personId): void
    {
        $this->personId = $personId;
        $this->resetMember();
    }

    private function resetMember(): void
    {
        $this->member = array_fill_keys([...array_keys(PersonFields::MEMBER), 'first_name', 'last_name', 'father_name', 'sex', 'birth_date', 'national_number', 'person_id'], '');
        $this->pickSearch = '';
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

    public function pick(int $id): void
    {
        $this->member['person_id'] = (string) $id;
    }

    public function add(): void
    {
        $input = $this->member;
        if ($this->mode === 'new') {
            $input['person_id'] = '';
        } elseif ((int) $input['person_id'] < 1) {
            $this->addError('member.person_id', __('people.errors.pick_person'));

            return;
        }
        if ($this->attempt(fn () => app(PeopleService::class)->addMember(auth()->user(), $this->personId, $input))) {
            $this->resetMember();
            $this->adding = false;
        }
    }

    public function startEdit(int $memberId, array $values): void
    {
        $this->editing = $memberId;
        $this->edit = array_map(fn ($v) => $v === null ? '' : (is_bool($v) ? ($v ? '1' : '0') : (string) $v), array_intersect_key($values, PersonFields::MEMBER));
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        if ($this->editing !== null && $this->attempt(fn () => app(PeopleService::class)->updateMember(auth()->user(), $this->personId, $this->editing, $this->edit))) {
            $this->editing = null;
        }
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->adding = false;
        $this->resetErrorBag();
    }

    public function remove(int $memberId): void
    {
        $this->attempt(fn () => app(PeopleService::class)->removeMember(auth()->user(), $this->personId, $memberId));
    }

    public function render(): View
    {
        $service = app(PeopleService::class);
        $account = auth()->user();
        $person = $service->person($account, $this->personId);
        $city = (int) $person->city_id;
        $family = $service->family($account, $this->personId);
        $editable = $service->canManage($account, $city);
        $members = $family['members'] ?? collect();

        return view('livewire.family-panel', [
            'family' => $family['family'] ?? null, 'members' => $members, 'editable' => $editable,
            'options' => $editable ? $service->optionsFor(PersonFields::MEMBER, $city, $this->editing ? $this->edit : []) : [],
            'sexOptions' => null,
            'picks' => $this->adding && $this->mode === 'existing' ? $service->pick($account, $city, $this->pickSearch, $members->pluck('person_id')->all()) : collect(),
            'picked' => (int) ($this->member['person_id'] ?? 0) > 0 ? $service->person($account, (int) $this->member['person_id']) : null,
        ]);
    }
}
