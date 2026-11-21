{{--
    Smart filter bar (smart-search plan 2026-11, phase 1). Used inside a Livewire view whose component uses
    App\Livewire\Concerns\HasSmartFilters: <x-hc.filter-bar :bar="$filterBar"/> where $filterBar = $this->filterBar().
    One removable chip per active filter, "+ filter" lists the dataset's filterable fields, and the editor below
    offers multi-select with in-list search and counts, ranges for dates and amounts, and relative date presets.
--}}
@props(['bar'])
@php($editor = $bar['editor'])
<div class="hc-filter-bar" {{ $attributes }}>
    <div class="hc-filter-row" role="group" aria-label="{{ __('hc.filters') }}">
        @foreach($bar['chips'] as $chip)
            <span class="hc-filter-chip @if($editor && $editor['key'] === $chip['key']) is-editing @endif" wire:key="fchip-{{ $chip['key'] }}">
                <button type="button" class="hc-filter-chip-main" wire:click="openFilter({{ \Illuminate\Support\Js::from($chip['key']) }})" aria-expanded="{{ $editor && $editor['key'] === $chip['key'] ? 'true' : 'false' }}">
                    <span class="hc-filter-chip-field">{{ $chip['label'] }}:</span> <span>{{ $chip['summary'] }}</span>
                </button>
                <button type="button" class="hc-filter-chip-x" wire:click="removeFilter({{ \Illuminate\Support\Js::from($chip['key']) }})" aria-label="{{ __('hc.filter_remove', ['field' => $chip['label']]) }}" title="{{ __('hc.filter_remove', ['field' => $chip['label']]) }}"><x-hc.icon name="x" :size="14"/></button>
            </span>
        @endforeach
        <div class="hc-filter-add" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.stop="open = false">
            <button type="button" class="hc-chip" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-haspopup="menu"><x-hc.icon name="plus" :size="14"/>{{ __('hc.filter_add') }}</button>
            <div class="hc-dropdown hc-filter-menu" x-show="open" x-cloak role="menu">
                @foreach($bar['menu'] as $item)
                    <button type="button" role="menuitem" wire:click="openFilter({{ \Illuminate\Support\Js::from($item['key']) }})" @click="open = false">
                        <span>{{ $item['label'] }}</span>@if($item['active'])<x-hc.icon name="check" :size="14" style="margin-inline-start:auto;color:var(--brand)"/>@endif
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    @if($editor)
        @php($k = \Illuminate\Support\Js::from($editor['key']))
        @php($ranged = in_array($editor['type'], ['date', 'money', 'number'], true))
        @php($from = $editor['op'] === 'between' && count($editor['values']) === 2 ? $editor['values'][0] : '')
        @php($to = $editor['op'] === 'between' && count($editor['values']) === 2 ? $editor['values'][1] : '')
        <div class="hc-filter-editor" wire:key="feditor-{{ $editor['key'] }}" role="dialog" aria-label="{{ $editor['label'] }}"
             x-data="{ q: '', from: @js($from), to: @js($to), text: @js($editor['type'] === 'text' ? implode("\n", $editor['values']) : '') }"
             @keydown.escape.stop="$wire.closeFilter()">
            <div class="hc-filter-editor-head">
                <strong>{{ $editor['label'] }}</strong>
                @if(isset($editor['options']))
                    <div class="hc-filter-ops" role="group" aria-label="{{ __('hc.filter_operator') }}">
                        @foreach(['in', 'not_in', 'empty', 'not_empty'] as $op)
                            <button type="button" class="hc-chip @if($editor['op'] === $op) is-active @endif" aria-pressed="{{ $editor['op'] === $op ? 'true' : 'false' }}" wire:click="setFilterOp({{ $k }}, '{{ $op }}')">{{ __('hc.filter_op_'.$op) }}</button>
                        @endforeach
                    </div>
                @elseif($ranged)
                    <div class="hc-filter-ops" role="group" aria-label="{{ __('hc.filter_operator') }}">
                        @foreach(['empty', 'not_empty'] as $op)
                            <button type="button" class="hc-chip @if($editor['op'] === $op) is-active @endif" aria-pressed="{{ $editor['op'] === $op ? 'true' : 'false' }}" wire:click="setFilterOp({{ $k }}, '{{ $op }}')">{{ __('hc.filter_op_'.$op) }}</button>
                        @endforeach
                    </div>
                @endif
                <x-hc.button variant="ghost" size="sm" wire:click="closeFilter" style="margin-inline-start:auto">{{ __('hc.filter_done') }}</x-hc.button>
            </div>

            @if(isset($editor['options']))
                @if(! in_array($editor['op'], ['empty', 'not_empty'], true))
                    @if(count($editor['options']) > 6)
                        <input type="search" class="hc-input" x-model="q" placeholder="{{ __('hc.filter_find_value') }}" aria-label="{{ __('hc.filter_find_value') }}" autocomplete="off">
                    @endif
                    <div class="hc-filter-options">
                        @forelse($editor['options'] as $option)
                            <label class="hc-filter-option" wire:key="fopt-{{ $editor['key'] }}-{{ $option['value'] }}" data-label="{{ mb_strtolower($option['label']) }}" x-show="q === '' || $el.dataset.label.includes(q.toLowerCase())">
                                <input type="checkbox" @checked($option['selected']) wire:click="toggleFilterValue({{ $k }}, {{ \Illuminate\Support\Js::from($option['value']) }})">
                                <span>{{ $option['label'] }}</span>
                                <span class="muted num">({{ number_format($option['count']) }})</span>
                            </label>
                        @empty
                            <p class="muted">{{ __('hc.filter_no_values') }}</p>
                        @endforelse
                    </div>
                @endif
            @elseif($ranged)
                @if($editor['type'] === 'date')
                    <div class="hc-chips" role="group" aria-label="{{ __('hc.filter_presets') }}">
                        @foreach($bar['presets'] as $preset => $label)
                            @php($on = $editor['op'] === 'between' && $editor['values'] === [$preset])
                            <button type="button" class="hc-chip @if($on) is-active @endif" aria-pressed="{{ $on ? 'true' : 'false' }}" wire:click="setFilterPreset({{ $k }}, '{{ $preset }}')">{{ $label }}</button>
                        @endforeach
                    </div>
                @endif
                <form class="hc-filter-range" @submit.prevent="$wire.setFilterRange({{ $k }}, from, to)">
                    <label class="hc-field"><span class="hc-label">{{ __('hc.filter_from') }}</span>
                        <input class="hc-input" x-model="from" @if($editor['type'] === 'date') type="date" @else type="number" step="any" inputmode="decimal" @endif></label>
                    <label class="hc-field"><span class="hc-label">{{ __('hc.filter_to') }}</span>
                        <input class="hc-input" x-model="to" @if($editor['type'] === 'date') type="date" @else type="number" step="any" inputmode="decimal" @endif></label>
                    <x-hc.button type="submit" variant="secondary" size="sm">{{ __('hc.filter_apply') }}</x-hc.button>
                </form>
            @else
                <form class="hc-filter-range" @submit.prevent="$wire.setFilterText({{ $k }}, text)">
                    <label class="hc-field grow"><span class="hc-label">{{ __('hc.filter_contains_hint') }}</span>
                        <textarea class="hc-textarea" rows="2" x-model="text" @keydown.enter.prevent="if (! $event.shiftKey) $wire.setFilterText({{ $k }}, text); else text += '\n'"></textarea></label>
                    <x-hc.button type="submit" variant="secondary" size="sm">{{ __('hc.filter_apply') }}</x-hc.button>
                </form>
            @endif
        </div>
    @endif
</div>
