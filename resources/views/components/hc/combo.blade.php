{{--
    Type-to-search list for long lookups (owner request, 20 Nov 2026). Bound like an input: <x-hc.combo wire:model="form.x" :options="$options" id="..."/>.
    $options: items {id, label, group?}. The value travels through x-modelable, so wire:model / wire:model.live work as on a select.
--}}
@props(['options', 'id', 'placeholder' => null])
@php
    $items = collect($options)->map(fn ($o) => ['id' => (string) $o->id, 'label' => (string) $o->label, 'group' => $o->group ?? null])->values();
    $listId = $id.'-list';
@endphp
<div x-data="hcCombo(@js($items))" x-modelable="value" {{ $attributes->whereStartsWith('wire:model') }}
     class="hc-combo" style="position:relative" @click.outside="close()" @keydown.escape.prevent.stop="close()">
    <input x-ref="input" type="text" id="{{ $id }}" class="hc-input" autocomplete="off" role="combobox" aria-autocomplete="list"
           :aria-expanded="open" aria-controls="{{ $listId }}" :aria-activedescendant="active >= 0 ? '{{ $id }}-o' + active : null"
           x-model="query" @focus="show()" @click="show()" @input="open = true; active = -1"
           @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="enter()" @keydown.tab="close()"
           placeholder="{{ $placeholder ?? __('people.type_to_search') }}" style="padding-inline-end:56px">
    <span style="position:absolute;inset-inline-end:8px;inset-block-start:50%;transform:translateY(-50%);display:flex;gap:2px;align-items:center;color:var(--muted)">
        <button type="button" x-show="value !== '' && value !== null" x-cloak @click="clear()" class="hc-icon-btn" style="width:24px;height:24px" aria-label="{{ __('hc.clear') }}"><x-hc.icon name="x" :size="14"/></button>
        <x-hc.icon name="chevron" :size="16"/>
    </span>
    <ul x-ref="list" id="{{ $listId }}" role="listbox" x-show="open" x-cloak class="hc-dropdown"
        style="inset-inline-start:0;inset-inline-end:0;min-width:100%;max-height:260px;overflow:auto;list-style:none;margin:0">
        <template x-for="(option, index) in filtered()" :key="option.id">
            <li role="option" :id="'{{ $id }}-o' + index" :data-index="index" :aria-selected="option.id === String(value)"
                @mousedown.prevent="choose(option)" @mouseenter="active = index"
                :style="(index === active ? 'background:var(--surface-2);' : '') + (option.id === String(value) ? 'font-weight:700;color:var(--brand);' : '')"
                style="padding:8px 10px;border-radius:6px;cursor:pointer;display:flex;justify-content:space-between;gap:12px">
                <span x-text="option.label"></span>
                <span x-show="option.group" x-text="option.group" class="muted" style="font-size:.8rem;white-space:nowrap"></span>
            </li>
        </template>
        <li x-show="filtered().length === 0" class="muted" style="padding:8px 10px">{{ __('people.no_match') }}</li>
        <li x-show="filtered().length === limit && total() > limit" class="muted" style="padding:6px 10px;font-size:.8rem">{{ __('people.type_more') }}</li>
    </ul>
</div>
