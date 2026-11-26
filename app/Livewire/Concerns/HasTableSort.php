<?php

namespace App\Livewire\Concerns;

use App\Support\TableTools\TableSort;
use Livewire\Attributes\Url;

/**
 * Header sorting of a server-paginated Livewire list: `<x-hc.sort-th>` calls sortBy(), `<x-hc.sort-select>` (phones) calls sortTo().
 * Only the column key and direction are kept (also in the address: `sort=date&dir=desc`); the list's service whitelists the key again
 * before it orders the query (TableSort::apply).
 */
trait HasTableSort
{
    #[Url(as: 'sort', except: '')]
    public string $sortKey = '';

    #[Url(as: 'dir', except: 'asc')]
    public string $sortDir = 'asc';

    /** @return list<string> the keys the shown table may be sorted by */
    abstract protected function sortableKeys(): array;

    public function sortBy(string $key): void
    {
        if (! in_array($key, $this->sortableKeys(), true)) {
            return;
        }
        [$next, $this->sortDir] = TableSort::next($this->sortKey, $this->sortDir, $key);
        $this->sortKey = $next ?? '';
        $this->resetAfterSort();
    }

    /** "key", "-key" (descending) or "" (the list's own order). */
    public function sortTo(string $choice): void
    {
        $key = ltrim($choice, '-');
        $known = in_array($key, $this->sortableKeys(), true);
        $this->sortKey = $known ? $key : '';
        $this->sortDir = $known && str_starts_with($choice, '-') ? 'desc' : 'asc';
        $this->resetAfterSort();
    }

    /** @return array{sort: ?string, dir: ?string} for the list's filters */
    protected function sortFilters(): array
    {
        [$key, $dir] = TableSort::clean($this->sortKey, $this->sortDir, $this->sortableKeys());

        return ['sort' => $key, 'dir' => $key === null ? null : $dir];
    }

    protected function resetAfterSort(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }
}
