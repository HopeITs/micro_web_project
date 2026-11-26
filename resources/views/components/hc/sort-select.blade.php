{{--
    The "sort by" list shown on phones, where a `hc-table stack` has no header row: the same keys as the table's <x-hc.sort-th>.
    `columns` is key => label. In a Livewire list it calls HasTableSort::sortTo(); with `link` it opens the sorted address of a GET page.
--}}
@props(['columns', 'sort' => null, 'dir' => 'asc', 'link' => false])
@php
    $url = fn (?string $key, string $direction) => request()->fullUrlWithQuery(['sort' => $key, 'dir' => $key === null ? null : $direction, 'page' => null]);
    $current = $sort !== null && isset($columns[$sort]) ? [$sort, $dir === 'desc' ? 'desc' : 'asc'] : [null, 'asc'];
@endphp
<label class="hc-sort-mobile">
    <span>{{ __('tables.sort_by') }}</span>
    <select class="hc-select" @if($link) onchange="window.location.assign(this.value)" @else wire:change="sortTo($event.target.value)" @endif>
        <option value="{{ $link ? $url(null, 'asc') : '' }}">{{ __('tables.default_order') }}</option>
        @foreach($columns as $key => $label)
            @foreach(['asc', 'desc'] as $direction)
                <option value="{{ $link ? $url((string) $key, $direction) : ($direction === 'desc' ? '-' : '').$key }}" @selected($current === [(string) $key, $direction])>{{ $label }} ({{ __('tables.'.$direction) }})</option>
            @endforeach
        @endforeach
    </select>
</label>
