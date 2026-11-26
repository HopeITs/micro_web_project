<?php

namespace App\Livewire;

use App\Services\People\FamilyService;
use App\Services\People\Households;
use App\Services\People\PeopleService;
use App\Services\People\PersonFields;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One household (desktop family grid): its members with role, state, education, work and income; adding a new or registered person,
 * editing and removing in a drawer. Compact on a person's page; with totals, attention flags and the family-wide tables on the family
 * page. Every action goes through PeopleService, which rechecks the person, the household and `people.manage` in the family's city.
 */
class FamilyPanel extends Component
{
    /** The person whose page this is (compact); 0 on the family page. */
    #[Locked]
    public int $personId = 0;

    #[Locked]
    public ?int $familyId = null;

    /** The household's beneficiary; 0 for rows with no recorded provider. */
    #[Locked]
    public int $headId = 0;

    #[Locked]
    public bool $compact = true;

    public bool $adding = false;

    /** new | existing */
    public string $mode = 'new';

    /** @var array<string, mixed> */
    public array $member = [];

    public string $pickSearch = '';

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $edit = [];

    /** "Not the same person": saving a name or national number that matches someone else. */
    public bool $confirmed = false;

    /** @var list<string> fields of the new member filled from the head's record, to be checked */
    public array $suggested = [];

    public ?string $notice = null;

    public function mount(int $personId = 0, ?int $familyId = null, ?int $headId = null, bool $compact = true): void
    {
        $this->personId = $personId;
        $this->compact = $compact;
        if ($familyId === null) {
            $own = app(FamilyService::class)->memberships(auth()->user(), $personId)->first();
            [$familyId, $headId] = $own ? [$own->family_id, $own->head_id] : [null, 0];
        }
        $this->familyId = $familyId;
        $this->headId = (int) $headId;
        $this->resetMember();
    }

    private function resetMember(): void
    {
        $this->member = array_fill_keys([...array_keys(PersonFields::MEMBER), ...PersonFields::MEMBER_PERSON, 'person_id'], '');
        // The desktop starts every new family row as resident.
        $this->member['person_state_id'] = (string) (Households::residentStateId() ?? '');
        $this->pickSearch = '';
        $this->suggested = [];
        $this->confirmed = false;
    }

    private function household(): array
    {
        abort_if($this->familyId === null, 404);

        return [$this->familyId, $this->headId];
    }

    /** The person a change is made through: the page's person, or on the family page the head (else any member). */
    private function anchor(): int
    {
        if ($this->compact) {
            return $this->personId;
        }
        $rows = Households::rows(...$this->household());
        $head = (clone $rows)->where('m.person_id', $this->headId)->value('m.person_id');

        return (int) ($head ?? $rows->orderBy('m.id')->value('m.person_id') ?? abort(404));
    }

    private function attempt(callable $action): bool
    {
        $this->resetErrorBag();
        $this->notice = null;
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

    public function openAdd(): void
    {
        $this->resetMember();
        $this->resetErrorBag();
        [$this->adding, $this->editing, $this->mode, $this->notice] = [true, null, 'new', null];
    }

    public function pick(int $id): void
    {
        $person = app(PeopleService::class)->person(auth()->user(), $id); // 404 outside the readable cities
        $this->member['person_id'] = (string) $id;
        $this->member['person_state_id'] = (string) ($person->person_state_id ?? Households::residentStateId() ?? '');
    }

    /** From the duplicate warning: take the registered person instead of typing them again. */
    public function useExisting(int $id): void
    {
        $this->mode = 'existing';
        $this->pick($id);
    }

    /** Fills what the chosen relation implies, only into empty fields; the form says which ones to check. */
    public function updatedMember(mixed $value, ?string $key = null): void
    {
        $this->confirmed = false;
        if ($key !== 'relation_id' || $this->mode !== 'new' || (int) $value < 1 || $this->headId < 1) {
            return;
        }
        $relation = (string) DB::table('relations')->where('id', (int) $value)->value('name_ar');
        $head = DB::table('people')->where('id', $this->headId)->whereNull('deleted_at')->first(['first_name', 'father_name', 'last_name', 'sex']);
        if ($head === null) {
            return;
        }
        $suggest = match ($relation) {
            'ابن/ة' => $head->sex === 'male' ? ['father_name' => $head->first_name, 'last_name' => $head->last_name] : [],
            'أخ' => ['father_name' => $head->father_name, 'last_name' => $head->last_name, 'sex' => 'male'],
            'أخت' => ['father_name' => $head->father_name, 'last_name' => $head->last_name, 'sex' => 'female'],
            'أب' => ['sex' => 'male'],
            'أم' => ['sex' => 'female'],
            default => [],
        };
        // A new choice of relation takes back what the previous one suggested.
        foreach ($this->suggested as $field) {
            $this->member[$field] = '';
        }
        $this->suggested = [];
        foreach ($suggest as $field => $text) {
            if (trim((string) $text) !== '' && trim((string) ($this->member[$field] ?? '')) === '') {
                $this->member[$field] = (string) $text;
                $this->suggested[] = $field;
            }
        }
    }

    public function add(bool $another = false): void
    {
        $input = $this->member;
        if ($this->mode === 'new') {
            $input['person_id'] = '';
        } elseif ((int) $input['person_id'] < 1) {
            $this->addError('member.person_id', __('people.errors.pick_person'));

            return;
        }
        if ($this->attempt(fn () => app(PeopleService::class)->addMember(auth()->user(), $this->anchor(), $input, $this->household()))) {
            $this->resetMember();
            $this->adding = $another;
            $this->notice = __('families.notice.added');
        }
    }

    public function startEdit(int $memberId): void
    {
        $row = app(FamilyService::class)->members([$this->household()[0]], $this->headId)->firstWhere('id', $memberId) ?? abort(404);
        $this->editing = $memberId;
        $this->adding = false;
        $this->confirmed = false;
        $this->notice = null;
        $this->edit = [];
        $stored = (array) DB::table('people')->where('id', $row->person_id)->first(PersonFields::MEMBER_PERSON)
            + (array) DB::table('family_members')->where('id', $row->id)->first(array_keys(PersonFields::MEMBER));
        foreach ([...array_keys(PersonFields::MEMBER), ...PersonFields::MEMBER_PERSON] as $field) {
            $value = $stored[$field] ?? null;
            $this->edit[$field] = match (true) {
                $value === null => '',
                $field === 'has_work' => (int) $value === 1 ? '1' : '0',
                // A birth year is kept as the first of January (the desktop's year field).
                $field === 'birth_date' => preg_match('/\A(\d{4})-01-01/', (string) $value, $m) ? $m[1] : substr((string) $value, 0, 10),
                default => (string) $value,
            };
        }
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        if ($this->editing !== null && $this->attempt(fn () => app(PeopleService::class)->updateMember(auth()->user(), $this->anchor(), $this->editing, $this->edit, $this->household(), $this->confirmed))) {
            $this->editing = null;
            $this->notice = __('families.notice.saved');
        }
    }

    public function updatedEdit(): void
    {
        $this->confirmed = false;
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->adding = false;
        $this->resetErrorBag();
    }

    public function remove(int $memberId): void
    {
        if ($this->attempt(fn () => app(PeopleService::class)->removeMember(auth()->user(), $this->anchor(), $memberId, $this->household(), ! $this->compact))) {
            $this->editing = $this->editing === $memberId ? null : $this->editing;
            $this->notice = __('families.notice.removed');
        }
    }

    public function recount(): void
    {
        if ($this->attempt(fn () => app(PeopleService::class)->recountHousehold(auth()->user(), $this->anchor(), $this->household()))) {
            $this->notice = __('families.notice.recounted');
        }
    }

    public function render(): View
    {
        $people = app(PeopleService::class);
        $families = app(FamilyService::class);
        $account = auth()->user();
        if ($this->compact) {
            $people->person($account, $this->personId);
        }
        if ($this->familyId === null) {
            return view('livewire.family-panel', ['home' => null]);
        }
        $family = DB::table('families')->where('id', $this->familyId)->whereNull('deleted_at')->first() ?? abort(404);
        $city = (int) $family->city_id;
        abort_unless(in_array($city, $people->cityIds($account), true), 404);
        $home = $families->household($this->familyId, $this->headId);
        $editable = $people->canManage($account, $city);
        $open = $editable && ($this->adding || $this->editing !== null);
        $row = $this->editing === null ? null : $home['members']->firstWhere('id', $this->editing);
        $options = [];
        if ($open) {
            $values = $this->editing !== null ? $this->edit : $this->member;
            $options = $people->optionsFor(PersonFields::MEMBER + array_intersect_key(PersonFields::personFlat(), ['person_state_id' => 1]), $city, $values);
            // "مستفيد" is the head's relation: not offered to members (kept only where it is already stored).
            $beneficiary = Households::beneficiaryRelationId();
            if ($row?->relation_id !== $beneficiary) {
                $options['relation_id'] = $options['relation_id']->reject(fn ($o) => $o->id === $beneficiary)->values();
            }
        }
        $picking = $open && $this->adding && $this->mode === 'existing';
        $picked = $picking && (int) ($this->member['person_id'] ?? 0) > 0 ? $people->person($account, (int) $this->member['person_id']) : null;
        $duplicates = match (true) {
            ! $open => [],
            $this->adding && $this->mode === 'new' => $people->duplicates($account, $city, $this->member),
            $row !== null && $this->getErrorBag()->has('confirm') => $people->duplicates($account, $city, $this->edit, $row->person_id),
            default => [],
        };

        return view('livewire.family-panel', [
            'home' => $home, 'editable' => $editable, 'open' => $open, 'row' => $row, 'options' => $options,
            'picks' => $picking ? $people->pick($account, $city, $this->pickSearch, $home['members']->pluck('person_id')->all()) : collect(),
            'picked' => $picked, 'pickedHomes' => $picked ? $families->memberships($account, (int) $picked->id) : collect(),
            'duplicates' => $duplicates,
            'records' => $this->compact ? [] : $families->records($home['members']->pluck('person_id')->all()),
        ]);
    }
}
