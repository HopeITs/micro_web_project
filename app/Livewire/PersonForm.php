<?php

namespace App\Livewire;

use App\Services\People\PeopleService;
use App\Services\People\PersonFields;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Creating or editing a person (M4.3) in the paper order. While typing, the national number, the full name and the family book number
 * are checked against existing people (as the desktop's Check_National_Number / Check_Family_Num); saving over a match needs the
 * "not the same person" confirmation.
 */
class PersonForm extends Component
{
    #[Locked]
    public ?int $personId = null;

    public int $city = 0;

    /** @var array<string, mixed> */
    public array $person = [];

    public string $familyNumber = '';

    public bool $confirmed = false;

    public function mount(?int $personId = null): void
    {
        $service = app(PeopleService::class);
        $account = auth()->user();
        if ($personId !== null) {
            $row = $service->person($account, $personId);
            $service->canManage($account, (int) $row->city_id) || abort(403);
            $this->personId = $personId;
            $this->city = (int) $row->city_id;
            foreach (PersonFields::personFlat() as $field => $spec) {
                $value = $row->{$field};
                $this->person[$field] = match ($spec['kind']) {
                    'bool' => $value === null ? '' : ((bool) $value ? '1' : '0'),
                    'date' => $value ? substr((string) $value, 0, 10) : '',
                    default => $value === null ? '' : (string) $value,
                };
            }
        } else {
            $cities = $service->cityIds($account, 'people.manage');
            $cities !== [] || abort(403);
            $this->city = $cities[0];
            foreach (PersonFields::personFlat() as $field => $_) {
                $this->person[$field] = '';
            }
        }
    }

    public function updatedCity(): void
    {
        // City lists (streets, priests) belong to the city: clear them when it changes.
        foreach (PersonFields::personFlat() as $field => $spec) {
            if ($spec['city'] ?? false) {
                $this->person[$field] = '';
            }
        }
        $this->confirmed = false;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['person.national_number', 'person.first_name', 'person.last_name', 'person.father_name', 'familyNumber'], true)) {
            $this->confirmed = false;
        }
    }

    public function save(): void
    {
        $service = app(PeopleService::class);
        $account = auth()->user();
        $this->resetErrorBag();
        try {
            if ($this->personId === null) {
                $id = $service->create($account, $this->city, $this->person, $this->familyNumber, $this->confirmed);
                session()->flash('status', __('people.created'));
            } else {
                $service->update($account, $this->personId, $this->person, $this->confirmed);
                $id = $this->personId;
                session()->flash('status', __('people.updated'));
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->redirectRoute('people.show', ['locale' => app()->getLocale(), 'person' => $id]);
    }

    public function render(): View
    {
        $service = app(PeopleService::class);
        $account = auth()->user();
        $national = preg_replace('/\D/', '', (string) ($this->person['national_number'] ?? ''));

        return view('livewire.person-form', [
            'groups' => PersonFields::PERSON,
            'options' => $service->optionsFor(PersonFields::personFlat(), $this->city, $this->person),
            'cities' => $this->personId === null ? $service->cities($account, 'people.manage') : collect(),
            'duplicates' => $service->duplicates($account, $this->city, $this->person, $this->personId),
            'holders' => $this->personId === null ? $service->familyHolders($this->city, $this->familyNumber) : collect(),
            'nationalWarning' => $national !== '' && strlen($national) !== 11,
        ]);
    }
}
