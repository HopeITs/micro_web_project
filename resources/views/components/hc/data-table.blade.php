{{--
    Dataset table (smart-search plan 2026-11, phase 2) for a Livewire list using HasDatasetColumns:
    <x-hc.data-table :rows="$paginator" :table="$this->dataTable()"/>. Columns come from Field[] (label, Field::cell()),
    sortable headers call sortBy(key, shift) and carry aria-sort; on phones `hc-table stack` turns rows into cards
    labelled by data-label.
--}}
@props(['rows', 'table'])
<table class="hc-table stack" data-smart="off">
    <thead><tr>
        @foreach($table['columns'] as $col)
            @php($dir = $col['dir'])
            <th scope="col" @class(['num' => $col['field']->numeric()]) aria-sort="{{ $dir === 'asc' ? 'ascending' : ($dir === 'desc' ? 'descending' : 'none') }}" wire:key="th-{{ $col['key'] }}">
                @if($col['sortable'])
                    <button type="button" class="hc-sort @if($dir) is-sorted @endif" wire:click="sortBy('{{ $col['key'] }}', $event.shiftKey)" title="{{ __('hc.sort_hint') }}">
                        <span>{{ $col['label'] }}</span>
                        <span class="hc-sort-ind" aria-hidden="true">{{ $dir === 'asc' ? '▲' : ($dir === 'desc' ? '▼' : '↕') }}</span>
                        @if($col['priority'])<sup class="hc-sort-pri">{{ $col['priority'] }}</sup>@endif
                        @if($dir)<span class="sr-only">({{ __('hc.sort_'.$dir) }})</span>@endif
                    </button>
                @else
                    {{ $col['label'] }}
                @endif
            </th>
        @endforeach
    </tr></thead>
    <tbody>
    @foreach($rows as $row)
        <tr wire:key="row-{{ $row->id }}">
            @foreach($table['columns'] as $col)
                @php($cell = $col['field']->cell($row))
                <td data-label="{{ $col['label'] }}" @class(['num' => $col['field']->numeric()])>
                    @if($cell['text'] === '')
                        {{ __('hc.not_set') }}
                    @else
                        @if($cell['href'])<a class="row-link" href="{{ $cell['href'] }}">@endif
                        @if($cell['badge'])
                            <x-hc.badge :tone="$cell['tone']" :icon="$cell['icon']">{{ $cell['text'] }}</x-hc.badge>
                        @else
                            <span @class([$cell['class'], 'ltr' => $cell['ltr']]) @if($cell['ltr']) dir="ltr" @endif>{{ $cell['text'] }}</span>
                        @endif
                        @if($cell['href'])</a>@endif
                    @endif
                </td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
