<?php

namespace App\Livewire;

use App\Livewire\Concerns\ExportsPrivately;
use App\Livewire\Concerns\HasTableSort;
use App\Services\AccountAccess;
use App\Services\Staff\TaskBoardService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The task board page (M4.16): "my tasks" and the grid of funded / application projects, ticked in place by whoever is responsible. The
 * grid sorts by any column header; the search may hold a name or a mobile number, so it stays out of the address.
 */
class TaskBoard extends Component
{
    use ExportsPrivately;
    use HasTableSort;
    use WithPagination;

    #[Url]
    public string $view = '';

    #[Url]
    public string $kind = 'f';

    /** Not kept in the address: it may be a name or a mobile number. */
    public string $q = '';

    #[Url]
    public string $state = '';

    #[Url]
    public string $donor = '';

    #[Url]
    public string $type = '';

    /** @var list<int> */
    #[Url]
    public array $cities = [];

    /** @var array<int|string, string> task definition id => '1' done / '0' open */
    #[Url]
    public array $tasks = [];

    public ?string $notice = null;

    public function updated(): void
    {
        $this->resetPage();
    }

    public function toggleCity(int $city): void
    {
        $chosen = array_map('intval', $this->cities);
        $this->cities = in_array($city, $chosen, true) ? array_values(array_diff($chosen, [$city])) : [...$chosen, $city];
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['q', 'state', 'donor', 'type', 'cities', 'tasks']);
        $this->resetPage();
    }

    /** Ticks or unticks a cell; `expected` is the state the page showed, so a stale click is refused. */
    public function toggle(int $cell, bool $expected): void
    {
        app(TaskBoardService::class)->toggle(auth()->user(), $cell, $expected, ! $expected);
        $this->notice = __('staff.tasks.saved');
    }

    protected function sortableKeys(): array
    {
        $kind = $this->kind === 'a' ? 'a' : 'f';

        return TaskBoardService::sortKeys(app(TaskBoardService::class)->columns($kind), $kind);
    }

    protected function exportTarget(): array
    {
        return ['task-board', 'tasks.board.export'];
    }

    protected function exportFilters(): array
    {
        return $this->filters();
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->sortFilters() + ['kind' => $this->kind === 'a' ? 'a' : 'f', 'q' => mb_substr($this->q, 0, 100), 'state' => (int) $this->state ?: null, 'donor' => (int) $this->donor ?: null,
            'type' => (int) $this->type ?: null, 'cities' => array_map('intval', $this->cities), 'tasks' => array_filter($this->tasks, fn ($f) => in_array((string) $f, ['0', '1'], true))];
    }

    public function render(TaskBoardService $board): View
    {
        $mine = $this->view !== 'grid';
        $account = auth()->user();
        $data = $mine ? $board->mine($account) : $board->board($account, $this->filters());
        $access = app(AccountAccess::class);
        $canExport = $board->cities($account)->contains(fn ($c) => $access->check($account, (int) $c->id, 'reports.export'));

        return view('livewire.task-board', $data + ['mine' => $mine, 'canExport' => $canExport, 'query' => array_filter($this->filters(), fn ($v) => $v !== null && $v !== [] && $v !== '')]);
    }
}
