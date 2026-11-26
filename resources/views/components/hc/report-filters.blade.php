{{--
    The filter bar of the search and statistics pages: <x-hc.report-filters :bar="$bar" :cities="$cityOptions" :chosen="$this->cities"/>
    inside a Livewire view whose component uses App\Livewire\Concerns\HasReportFilters ($bar = reportFilterBar()).
    Cities are on / off chips; every filter in use is a chip that opens its editor and can be removed; the few common ones are always
    offered and the rest wait under "add filter", so only what is used takes room. The look follows the app's smart filter bar
    (x-hc.filter-bar). The default slot adds a page's own switches to the row.
--}}
@props(['bar', 'cities', 'chosen' => []])
@php($en = app()->getLocale() === 'en')
<div {{ $attributes->class('ins-bar') }}>
    @if($cities->count() > 1)
        <div class="ins-cities" role="group" aria-label="{{ __('search.cities') }}">
            <span class="ins-bar-label">{{ __('search.cities') }}</span>
            @foreach($cities as $c)
                <button type="button" class="ins-city" wire:key="city-{{ $c->id }}" wire:click="toggleCity({{ $c->id }})" aria-pressed="{{ in_array((int) $c->id, $chosen, true) ? 'true' : 'false' }}">
                    <x-hc.icon name="check" :size="14" class="ins-city-tick"/>{{ $en ? $c->name_en : $c->name_ar }} <small class="ltr">{{ $c->code }}</small>
                </button>
            @endforeach
            @if($chosen === [])<span class="ins-bar-hint">{{ __('search.ui.all_cities') }}</span>@endif
        </div>
    @endif

    <div class="ins-row" role="group" aria-label="{{ __('search.filters') }}">
        @foreach($bar['chips'] as $chip)
            <span @class(['ins-chip', 'is-active' => $chip['active'], 'is-editing' => $chip['editing'], 'is-excluded' => $chip['excluded']]) wire:key="chip-{{ $chip['key'] }}">
                <button type="button" id="flt-{{ $chip['key'] }}" class="ins-chip-main" data-flt-trigger="{{ $chip['key'] }}" wire:click="openFilter('{{ $chip['key'] }}')"
                        aria-haspopup="dialog" aria-expanded="{{ $chip['editing'] ? 'true' : 'false' }}">
                    <span class="ins-chip-field">{{ $chip['label'] }}@if($chip['active']):@endif</span>
                    @if($chip['active'])<span class="ins-chip-value">{{ $chip['summary'] }}</span>@else<x-hc.icon name="chevron" :size="14"/>@endif
                </button>
                @if($chip['active'])
                    <button type="button" class="ins-chip-x" wire:click="removeFilter('{{ $chip['key'] }}')" aria-label="{{ __('search.ui.remove', ['field' => $chip['label']]) }}" title="{{ __('search.ui.remove', ['field' => $chip['label']]) }}"><x-hc.icon name="x" :size="14"/></button>
                @endif
            </span>
        @endforeach

        @if($bar['menu'] !== [])
            <div class="ins-add" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.stop="open = false; $refs.add.focus()" wire:key="add-filter">
                <button type="button" class="ins-add-button" x-ref="add" @click="open = ! open; if (open) $nextTick(() => $refs.menu.querySelector('button')?.focus())" :aria-expanded="open ? 'true' : 'false'" aria-haspopup="true">
                    <x-hc.icon name="plus" :size="16"/>{{ __('search.ui.add') }}
                </button>
                <div class="ins-backdrop" x-show="open" x-cloak @click="open = false" aria-hidden="true"></div>
                <div class="ins-menu" x-ref="menu" x-show="open" x-cloak x-transition.opacity.duration.120ms>
                    @foreach($bar['menu'] as $group => $items)
                        <div class="ins-menu-group" role="group" aria-label="{{ __('search.ui.group.'.$group) }}">
                            <p>{{ __('search.ui.group.'.$group) }}</p>
                            @foreach($items as $item)
                                <button type="button" data-flt-trigger="{{ $item['key'] }}" wire:key="menu-{{ $item['key'] }}" wire:click="openFilter('{{ $item['key'] }}')" @click="open = false">
                                    <span>{{ $item['label'] }}</span>@if($item['active'])<x-hc.icon name="check" :size="14"/>@endif
                                </button>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{ $slot }}

        @if($bar['count'] > 0)
            <button type="button" class="ins-clear" wire:key="clear-all" wire:click="clearFilters"><x-hc.icon name="x" :size="15"/>{{ __('search.ui.clear_all', ['count' => $bar['count']]) }}</button>
        @endif
        <span class="ins-busy" wire:loading.delay role="status"><span class="sr-only">{{ __('search.ui.loading') }}</span></span>
    </div>

    @if($bar['notice'] && ! $bar['editor'])<p class="ins-notice" role="alert">{{ $bar['notice'] }}</p>@endif
    @if($bar['editor'])<x-hc.multi-filter :editor="$bar['editor']" :notice="$bar['notice']"/>@endif
</div>
