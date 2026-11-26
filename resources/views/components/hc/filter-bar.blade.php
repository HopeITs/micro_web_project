{{--
    Smart filter bar (smart-search plan 2026-11, phase 1). Used inside a Livewire view whose component uses
    App\Livewire\Concerns\HasSmartFilters: <x-hc.filter-bar :bar="$filterBar"/> where $filterBar = $this->filterBar().
    One removable chip per active filter, "+ filter" lists the dataset's filterable fields, and the editor below
    offers multi-select with in-list search and counts, ranges for dates and amounts, and relative date presets.
--}}
@props(['bar'])
@php($editor = $bar['editor'])
@php($views = $bar['views'] ?? null)
<div class="hc-filter-bar" {{ $attributes }}>
    @if(! empty($bar['ask']))
        {{-- X1: ask in plain words; the answer comes back as the `ask-applied` event this list listens for. --}}
        <livewire:ask-box :dataset="$bar['ask']" :key="'ask-'.$bar['ask']"/>
    @endif
    @if($views && ($views['list'] !== [] || $views['can_save']))
        {{-- "My views": own views and the ones colleagues shared; only filters are stored, never the search or names. --}}
        @php($av = $views['active'])
        <div class="hc-views-row" role="group" aria-label="{{ __('hc.views_mine') }}" x-data="{ saving: false, renaming: false }">
            <span class="hc-label">{{ __('hc.views_mine') }}</span>
            @foreach($views['list'] as $v)
                <button type="button" class="hc-view-chip @if($v['active']) is-active @endif" wire:key="view-{{ $v['id'] }}" wire:click="applyView({{ $v['id'] }})"
                        aria-pressed="{{ $v['active'] ? 'true' : 'false' }}" @if(! $v['own']) title="{{ __('hc.view_shared_by', ['name' => $v['owner']]) }}" @endif>
                    @if(! $v['own'])<x-hc.icon name="users" :size="14"/>@elseif($v['shared'])<x-hc.icon name="share" :size="14"/>@endif
                    <span>{{ $v['name'] }}</span>
                    @if($v['default'])<span class="hc-view-tag">{{ __('hc.view_default') }}</span>@endif
                    @if($v['active'] && $av['changed'])<span class="hc-view-tag">{{ __('hc.view_changed') }}</span>@endif
                </button>
            @endforeach

            @if($av && $av['own'])
                <div class="hc-filter-add" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.stop="open = false">
                    <button type="button" class="hc-chip" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-haspopup="menu"><x-hc.icon name="settings" :size="14"/>{{ __('hc.view_actions') }}</button>
                    <div class="hc-dropdown hc-filter-menu" x-show="open" x-cloak role="menu">
                        @if($av['changed'] && $views['can_save'])
                            <button type="button" role="menuitem" wire:click="overwriteView({{ $av['id'] }})" @click="open = false">{{ __('hc.view_overwrite') }}</button>
                        @endif
                        <button type="button" role="menuitem" @click="open = false; renaming = true; saving = false">{{ __('hc.view_rename') }}</button>
                        <button type="button" role="menuitem" wire:click="toggleDefaultView({{ $av['id'] }})" @click="open = false">{{ $av['default'] ? __('hc.view_unset_default') : __('hc.view_make_default') }}</button>
                        @if($av['shared'] || $views['can_share'])
                            <button type="button" role="menuitem" wire:click="toggleShareView({{ $av['id'] }})" @click="open = false">{{ $av['shared'] ? __('hc.view_unshare') : __('hc.view_share') }}</button>
                        @endif
                        <button type="button" role="menuitem" class="is-danger" wire:click="deleteView({{ $av['id'] }})" wire:confirm="{{ __('hc.view_confirm_delete') }}" @click="open = false">{{ __('hc.view_delete') }}</button>
                    </div>
                </div>
            @endif
            @if($views['can_save'])
                <button type="button" class="hc-chip hc-view-save" @click="saving = ! saving; renaming = false" :aria-expanded="saving ? 'true' : 'false'" title="{{ __('hc.view_save_as') }}"><x-hc.icon name="plus" :size="14"/>{{ __('hc.view_save') }}</button>
            @endif

            <form class="hc-view-form" x-show="saving" x-cloak x-data="{ name: '' }" @submit.prevent="$wire.saveView(name).then(() => { if (! $wire.viewError) { saving = false; name = '' } })">
                <label class="hc-field grow"><span class="hc-label">{{ __('hc.view_name') }}</span>
                    <input class="hc-input" x-model="name" maxlength="120" autocomplete="off" @keydown.escape.stop="saving = false"></label>
                <x-hc.button type="submit" variant="secondary" size="sm">{{ __('hc.view_save') }}</x-hc.button>
                <x-hc.button type="button" variant="ghost" size="sm" @click="saving = false">{{ __('hc.view_cancel') }}</x-hc.button>
                <span class="hc-hint">{{ __('hc.view_save_hint') }}</span>
            </form>
            @if($av && $av['own'])
                <form class="hc-view-form" x-show="renaming" x-cloak x-data="{ name: @js($av['name']) }" wire:key="view-rename-{{ $av['id'] }}" @submit.prevent="$wire.renameView({{ $av['id'] }}, name).then(() => { if (! $wire.viewError) renaming = false })">
                    <label class="hc-field grow"><span class="hc-label">{{ __('hc.view_name') }}</span>
                        <input class="hc-input" x-model="name" maxlength="120" autocomplete="off" @keydown.escape.stop="renaming = false"></label>
                    <x-hc.button type="submit" variant="secondary" size="sm">{{ __('hc.view_rename') }}</x-hc.button>
                    <x-hc.button type="button" variant="ghost" size="sm" @click="renaming = false">{{ __('hc.view_cancel') }}</x-hc.button>
                </form>
            @endif
            @if($views['error'])
                <p class="hc-view-msg is-error" role="alert">{{ $views['error'] }}</p>
            @elseif($views['notice'])
                <p class="hc-view-msg" role="status">{{ $views['notice'] }}</p>
            @endif
        </div>
    @endif
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
                <x-hc.button variant="secondary" size="sm" icon="check" class="hc-filter-editor-done" wire:click="closeFilter">{{ __('hc.filter_done') }}</x-hc.button>
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
                                <span class="muted num">{{ number_format($option['count']) }}</span>
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
