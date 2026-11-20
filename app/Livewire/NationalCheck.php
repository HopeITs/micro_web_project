<?php

namespace App\Livewire;

use App\Services\People\NationalNumberCheck;
use App\Services\People\PeopleService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The national number check page (M4.4). The number is typed or taken from a person page (`?person=<id>`, an internal id, so the
 * number itself never appears in a link) and checked in every city and in DISC/F2F.
 */
class NationalCheck extends Component
{
    #[Url(as: 'person', except: 0)]
    public int $personId = 0;

    public string $number = '';

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public function mount(): void
    {
        if ($this->personId > 0) {
            $person = app(PeopleService::class)->person(auth()->user(), $this->personId);
            $this->number = (string) $person->national_number;
            if ($this->number !== '') {
                $this->check();
            }
        }
    }

    public function check(): void
    {
        $this->resetErrorBag();
        $this->result = null;
        try {
            $result = app(NationalNumberCheck::class)->check(auth()->user(), $this->number);
        } catch (ValidationException $e) {
            $this->addError('number', $e->errors()['number'][0] ?? __('national.errors.number'));

            return;
        }
        // Livewire keeps public state as arrays.
        $this->result = json_decode(json_encode($result), true);
    }

    public function clear(): void
    {
        $this->reset('number', 'result', 'personId');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('livewire.national-check');
    }
}
