<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Identity\IdCardReader;
use App\Services\Identity\IdentityReadException;
use App\Services\Identity\IdentityReviewService;
use App\Services\People\PersonFields;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Reads an identity document from an attachment and lets staff approve the values beside the image (owner decision 23 Nov 2026).
 * Nothing is written until "save": the values are proposals, each one is editable, doubtful ones are unticked, and the national number
 * goes through the person form's own duplicate check.
 */
final class IdentityReview extends Component
{
    public int $personId;

    public ?int $attachmentId = null;

    /** @var array<string, mixed> the reader's result, empty until "read" */
    public array $reading = [];

    /** @var array<string, string> field => the value shown in its input (a proposal, or what the person has now) */
    public array $values = [];

    /** @var array<string, bool> field => whether the value is applied on save */
    public array $use = [];

    public bool $confirmed = false;

    public bool $needsConfirm = false;

    /** @var list<string> */
    public array $problems = [];

    public ?string $error = null;

    public function mount(int $personId, ?int $attachmentId = null): void
    {
        $service = app(IdentityReviewService::class);
        $scans = $service->scans(auth()->user(), $personId);
        $this->personId = $personId;
        $this->attachmentId = $attachmentId !== null ? $service->scan(auth()->user(), $personId, $attachmentId)->id : ($scans[0]->id ?? null);
    }

    public function updatedAttachmentId(): void
    {
        $this->reading = $this->values = $this->use = $this->problems = [];
        $this->error = null;
        $this->confirmed = $this->needsConfirm = false;
    }

    public function read(): void
    {
        $service = app(IdentityReviewService::class);
        $this->error = null;
        $this->problems = [];
        $this->needsConfirm = false;
        if ($this->attachmentId === null) {
            return;
        }
        try {
            $this->reading = $service->read(auth()->user(), $this->personId, (int) $this->attachmentId);
        } catch (IdentityReadException $e) {
            $this->error = $e->getMessage();
            $this->reading = [];

            return;
        }
        $person = $service->person(auth()->user(), $this->personId);
        $this->values = [];
        $this->use = [];
        foreach (IdCardReader::FIELDS as $field) {
            $read = $this->reading['fields'][$field];
            $current = $this->current($person, $field);
            $this->values[$field] = $read['value'] ?? $current;
            // A proposal that differs from what is saved is applied by default only when the model and the local checks are sure of it.
            $this->use[$field] = $read['value'] !== null && $read['value'] !== $current && $read['confidence'] !== 'low';
        }
        [$nationality, $type] = match ($this->reading['type']) {
            'national_id' => ['syrian', 'national_id'], 'family_book' => ['syrian', 'family_book'], 'passport' => ['other', 'passport'], 'residence_permit' => ['other', 'residence_permit'], default => [null, null],
        };
        foreach (['nationality' => $nationality, 'id_document_type' => $type] as $field => $proposal) {
            $current = $this->current($person, $field);
            $this->values[$field] = $proposal ?? $current;
            $this->use[$field] = $proposal !== null && $current === '';
        }
    }

    /** Takes one of the other candidates the readings produced for a doubtful field. */
    public function pick(string $field, string $value): void
    {
        if (in_array($field, IdCardReader::FIELDS, true) && in_array($value, $this->reading['fields'][$field]['alternatives'] ?? [], true)) {
            $this->values[$field] = $value;
            $this->use[$field] = true;
        }
    }

    public function save(bool $confirmed = false): void
    {
        $this->problems = [];
        $apply = [];
        foreach ($this->use as $field => $on) {
            if ($on && isset($this->values[$field])) {
                $apply[$field] = (string) $this->values[$field];
            }
        }
        if ($apply === []) {
            $this->problems = [__('identity.nothing_chosen')];

            return;
        }
        try {
            app(IdentityReviewService::class)->apply(auth()->user(), $this->personId, $apply, $confirmed || $this->confirmed);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->problems[] = $messages[0];
                $this->needsConfirm = $this->needsConfirm || $key === 'confirm';
            }

            return;
        }
        session()->flash('status', __('identity.saved'));
        $this->redirectRoute('people.show', ['locale' => app()->getLocale(), 'person' => $this->personId]);
    }

    private function current(object $person, string $field): string
    {
        $value = $person->{$field} ?? null;

        return $value === null ? '' : (PersonFields::personFlat()[$field]['kind'] === 'date' ? substr((string) $value, 0, 10) : (string) $value);
    }

    public function render(): View
    {
        $service = app(IdentityReviewService::class);
        $person = $service->person(auth()->user(), $this->personId);

        return view('livewire.identity-review', [
            'person' => $person,
            'scans' => $service->scans(auth()->user(), $this->personId),
            'enabled' => $service->enabled(),
            'driver' => $service->driver(),
            'local' => $service->isLocal(),
            'remaining' => $service->remaining(auth()->user()),
            'currentValues' => collect([...IdCardReader::FIELDS, 'nationality', 'id_document_type'])->mapWithKeys(fn ($f) => [$f => $this->current($person, $f)])->all(),
        ]);
    }
}
