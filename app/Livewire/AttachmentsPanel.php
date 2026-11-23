<?php

namespace App\Livewire;

use App\Services\AccountAccess;
use App\Services\Attachments\AttachmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The attachments of a project or a person (desktop Attachments_Form): filter by type with counts, upload several files under one
 * type, open, download, delete. On the person page it also holds the personal photo. Every action goes through AttachmentService.
 */
class AttachmentsPanel extends Component
{
    use WithFileUploads;

    /** project | person */
    #[Locked]
    public string $owner;

    #[Locked]
    public int $ownerId;

    public ?int $type = null;

    public string $uploadType = '';

    /** @var array<int, mixed> */
    public array $files = [];

    public mixed $photo = null;

    public string $message = '';

    public function mount(string $owner, int $ownerId): void
    {
        array_key_exists($owner, AttachmentService::OWNERS) || abort(404);
        $this->owner = $owner;
        $this->ownerId = $ownerId;
    }

    public function filter(?int $type): void
    {
        $this->type = $type;
    }

    private function attempt(callable $action, string $field): bool
    {
        $this->resetErrorBag();
        $this->message = '';
        try {
            $action();

            return true;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key === 'type' ? 'uploadType' : $field, $messages[0]);
            }

            return false;
        }
    }

    public function saveFiles(): void
    {
        $ids = [];
        $ok = $this->attempt(function () use (&$ids) {
            $ids = app(AttachmentService::class)->upload(auth()->user(), $this->owner, $this->ownerId, $this->uploadType === '' ? null : (int) $this->uploadType, $this->files);
        }, 'files');
        if ($ok) {
            $this->files = [];
            $this->message = trans_choice('attachments.saved', count($ids), ['count' => count($ids)]);
        }
    }

    public function updatedPhoto(): void
    {
        if ($this->photo === null) {
            return;
        }
        if ($this->attempt(fn () => app(AttachmentService::class)->setPhoto(auth()->user(), $this->ownerId, $this->photo), 'photo')) {
            $this->message = __('attachments.photo_saved');
        }
        $this->photo = null;
    }

    public function removeFile(int $index): void
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    public function delete(int $id): void
    {
        app(AttachmentService::class)->delete(auth()->user(), $id);
        $this->message = __('attachments.deleted');
    }

    public function render(AttachmentService $service): View
    {
        $account = auth()->user();
        $owner = $service->owner($account, $this->owner, $this->ownerId);
        $city = (int) $owner->city_id;
        $visible = $service->canSee($account, $city);
        $list = $visible ? $service->list($account, $this->owner, $this->ownerId, $this->type) : ['rows' => collect(), 'counts' => [], 'hidden' => 0];
        $types = $visible ? $service->uploadTypes($account, $city) : collect();
        $person = $this->owner === 'person';

        // "Read the identity document": for staff who may change the person (the beneficiary of a project, or the person themselves).
        $identityPerson = null;
        if ($visible && app(\App\Services\People\PeopleService::class)->canManage($account, $city)) {
            $identityPerson = $person ? $this->ownerId : DB::table('project_applicants')->where('project_id', $this->ownerId)->whereNull('deleted_at')
                ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [\App\Services\Applications\ApplicationService::BENEFICIARY])->orderBy('id')->value('person_id');
        }

        return view('livewire.attachments-panel', [
            'identityPerson' => $identityPerson ? (int) $identityPerson : null,
            'visible' => $visible, 'rows' => $list['rows'], 'counts' => $list['counts'], 'hidden' => $list['hidden'],
            'total' => array_sum($list['counts']), 'types' => $types,
            // Filter chips: only the types that hold files the account may see (an empty type would be noise, especially on a phone).
            'chips' => $visible ? DB::table('attachment_types')
                ->whereIn('id', array_keys($list['counts']))->orderBy('sort_order')->orderBy('id')->get() : collect(),
            'uncategorised' => $list['counts'][0] ?? 0,
            // The photo belongs to the personal documents: shown only to those who hold that category.
            'showPhoto' => $person && in_array(array_search('attachments.personal', AccountAccess::ATTACHMENT_CATEGORIES, true), $service->categories($account, $city), true),
            'photoRow' => $person ? $service->photo($account, $this->ownerId) : null,
            'canPhoto' => $person && $service->canSetPhoto($account, $city),
        ]);
    }
}
