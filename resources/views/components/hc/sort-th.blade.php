{{--
    A sortable header of a server-paginated table: <x-hc.sort-th column="date" :label="..." :sort="$sortKey" :dir="$sortDir"/> in a Livewire
    list (HasTableSort::sortBy), or with `link` on a plain GET page (?sort=date&dir=desc, other filters kept, back to page 1).
    Click: default order → ascending → descending → default order. aria-sort carries the state.
--}}
@props(['column', 'label', 'sort' => null, 'dir' => 'asc', 'link' => false, 'num' => false])
@php
    $state = $sort === $column ? ($dir === 'desc' ? 'desc' : 'asc') : null;
    [$nextKey, $nextDir] = \App\Support\TableTools\TableSort::next($sort, $dir, $column);
    $url = $link ? request()->fullUrlWithQuery(['sort' => $nextKey, 'dir' => $nextKey === null ? null : $nextDir, 'page' => null]) : null;
    // A heading's own tooltip (who is responsible for a task, a long question) stays the one shown over the control.
    $hint = (string) $attributes->get('title') !== '' ? (string) $attributes->get('title') : __('tables.sort_hint');
@endphp
<th scope="col" {{ $attributes->class(['num' => $num]) }} aria-sort="{{ $state === 'asc' ? 'ascending' : ($state === 'desc' ? 'descending' : 'none') }}">
    @if($link)<a class="hc-sort{{ $state ? ' is-sorted' : '' }}" href="{{ $url }}" title="{{ $hint }}">@else<button type="button" class="hc-sort{{ $state ? ' is-sorted' : '' }}" wire:click="sortBy('{{ $column }}')" title="{{ $hint }}">@endif
        <span>{{ $label }}</span>
        <span class="hc-sort-ind" aria-hidden="true">{{ $state === 'asc' ? '▲' : ($state === 'desc' ? '▼' : '↕') }}</span>
        @if($state)<span class="sr-only">({{ __('tables.'.$state) }})</span>@endif
    @if($link)</a>@else</button>@endif
</th>
