@php($locale = app()->getLocale())
<x-layouts.hc :title="__('lookups.title')">
    <div class="hc-page-head"><div><p class="hc-eyebrow">{{ __('hc.group_tools') }}</p><h1>{{ __('lookups.title') }}</h1><p>{{ __('lookups.intro') }}</p></div></div>
    <div class="hc-grid">
    @foreach($lists as $group => $items)
        <x-hc.card :title="__('lookups.groups.'.$group)" :flush="true">
            <div class="hc-table-wrap"><table class="hc-table stack">
                <thead><tr><th>{{ __('lookups.title') }}</th><th>{{ __('lookups.scope') }}</th><th class="num">{{ __('lookups.active') }}</th><th class="num">{{ __('lookups.hidden') }}</th></tr></thead>
                <tbody>
                @foreach($items as $item)
                    <tr>
                        <td data-label="{{ __('lookups.title') }}"><a class="row-link" href="{{ route('lookups.show', ['locale' => $locale, 'list' => $item['key']]) }}">{{ __('lookups.names.'.$item['key']) }}</a></td>
                        <td data-label="{{ __('lookups.scope') }}">
                            <x-hc.badge :tone="$item['city'] ? 'info' : null">{{ $item['city'] ? __('lookups.city_list') : __('lookups.shared_list') }}</x-hc.badge>
                            @unless($item['editable'])<x-hc.badge tone="warn">{{ __('lookups.read_only') }}</x-hc.badge>@endunless
                        </td>
                        <td data-label="{{ __('lookups.active') }}" class="num">{{ number_format($item['active']) }}</td>
                        <td data-label="{{ __('lookups.hidden') }}" class="num">{{ number_format($item['hidden']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </x-hc.card>
    @endforeach
    </div>
</x-layouts.hc>
