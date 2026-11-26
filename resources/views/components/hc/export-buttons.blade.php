{{--
    The Excel / CSV buttons of a Livewire list. Normally links carrying the filters (`to` route + `query`); while the search box holds text
    (`private`), buttons calling ExportsPrivately::exportList(), so the text never enters an address.
--}}
@props(['to', 'query' => [], 'private' => false, 'xlsx' => 'Excel', 'csv' => 'CSV'])
@foreach(['xlsx' => $xlsx, 'csv' => $csv] as $format => $label)
    @if($private)
        <x-hc.button variant="ghost" size="sm" icon="file" wire:click="exportList('{{ $format }}')" wire:loading.attr="disabled" wire:key="export-private-{{ $format }}">{{ $label }}</x-hc.button>
    @else
        <x-hc.button variant="ghost" size="sm" icon="file" :href="route($to, ['locale' => app()->getLocale()] + $query + ['format' => $format])" wire:key="export-link-{{ $format }}">{{ $label }}</x-hc.button>
    @endif
@endforeach
