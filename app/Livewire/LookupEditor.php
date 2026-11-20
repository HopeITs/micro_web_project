<?php

namespace App\Livewire;

use App\Services\Lookups\LookupAdminService;
use App\Services\Lookups\LookupCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** One list (M4.2): search, add, edit in place, hide/show and delete; every action is rechecked by LookupAdminService. */
class LookupEditor extends Component
{
    #[Locked]
    public string $list = '';

    #[Url(except: 0)]
    public int $city = 0;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: false)]
    public bool $hidden = false;

    /** @var array<string, mixed> the new value being typed */
    public array $draft = [];

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $edit = [];

    public string $message = '';

    public string $tone = 'success';

    public function mount(string $list): void
    {
        abort_unless(LookupCatalog::exists($list), 404);
        $this->list = $list;
        if (LookupCatalog::get($list)['city']) {
            $cities = $this->service()->cities(auth()->user())->pluck('id')->map(fn ($id) => (int) $id)->all();
            abort_if($cities === [], 403);
            $this->city = in_array($this->city, $cities, true) ? $this->city : $cities[0];
        } else {
            $this->city = 0;
        }
        $this->resetDraft();
    }

    private function service(): LookupAdminService
    {
        return app(LookupAdminService::class);
    }

    private function cityArg(): ?int
    {
        return LookupCatalog::get($this->list)['city'] ? $this->city : null;
    }

    private function resetDraft(): void
    {
        $this->draft = ['name_ar' => '', 'name_en' => ''];
        foreach (LookupCatalog::get($this->list)['fields'] as $field => $_) {
            $this->draft[$field] = '';
        }
    }

    private function flash(string $key, string $tone = 'success'): void
    {
        $this->message = __('lookups.'.$key);
        $this->tone = $tone;
    }

    /** Runs a service call and shows its validation errors under the given form prefix. */
    private function attempt(string $prefix, callable $action): bool
    {
        $this->resetErrorBag();
        try {
            $action();

            return true;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($prefix.'.'.$field, $messages[0]);
            }
            $this->message = '';

            return false;
        }
    }

    public function selectCity(int $city): void
    {
        $this->city = $city;
        $this->cancel();
        $this->message = '';
    }

    public function create(): void
    {
        if ($this->attempt('draft', fn () => $this->service()->create(auth()->user(), $this->list, $this->cityArg(), $this->draft))) {
            $this->resetDraft();
            $this->flash('created');
        }
    }

    public function startEdit(int $id): void
    {
        $row = $this->service()->rows(auth()->user(), $this->list, $this->cityArg(), '', true)->firstWhere('id', $id);
        abort_if($row === null, 404);
        $this->editing = $id;
        $this->edit = ['name_ar' => $row->name_ar, 'name_en' => (string) $row->name_en];
        foreach (LookupCatalog::get($this->list)['fields'] as $field => $_) {
            $this->edit[$field] = (string) ($row->{$field} ?? '');
        }
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->edit = [];
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        if ($this->editing !== null && $this->attempt('edit', fn () => $this->service()->update(auth()->user(), $this->list, $this->editing, $this->edit))) {
            $this->cancel();
            $this->flash('updated');
        }
    }

    public function toggle(int $id, bool $active): void
    {
        $this->service()->setActive(auth()->user(), $this->list, $id, $active);
        $this->flash($active ? 'shown_msg' : 'hidden_msg');
    }

    public function remove(int $id): void
    {
        $result = $this->service()->delete(auth()->user(), $this->list, $id);
        $result === 'deleted' ? $this->flash('deleted_msg') : $this->flash('hidden_used_msg', 'info');
        if ($this->editing === $id) {
            $this->cancel();
        }
    }

    public function render(): View
    {
        $service = $this->service();
        $account = auth()->user();
        $definition = LookupCatalog::get($this->list);
        $rows = $service->rows($account, $this->list, $this->cityArg(), $this->search, $this->hidden);
        $options = [];
        foreach ($definition['fields'] as $field => $spec) {
            if ($spec['kind'] === 'parent') {
                $options[$field] = $service->options($spec['list']);
            }
        }

        return view('livewire.lookup-editor', [
            'definition' => $definition, 'rows' => $rows, 'usage' => $service->usage($this->list, $rows->pluck('id')->all()), 'options' => $options,
            'cities' => $definition['city'] ? $service->cities($account) : collect(),
            'editable' => $service->canEdit($account, $this->list, $this->cityArg()),
        ]);
    }
}
