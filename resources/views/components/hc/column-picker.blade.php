{{--
    Column picker ("الأعمدة", smart-search plan 2026-11, phase 2) for a Livewire list using HasDatasetColumns:
    <x-hc.column-picker :picker="$this->columnPicker()"/>. Tick to show, drag (SortableJS, see resources/js/app.js
    `columnPicker`) or use the arrow buttons to reorder; every change sends the shown keys in order to setColumns().
    Optional export buttons call exportTable('xlsx' | 'csv') when the account may export (picker.can_export).
--}}
@props(['picker', 'export' => true])
<div class="hc-table-tools">
    <div class="hc-filter-add hc-column-picker" x-data="columnPicker" @click.outside="open = false" @keydown.escape.stop="open = false">
        <button type="button" class="hc-chip" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-haspopup="dialog" aria-controls="column-picker-panel">
            <x-hc.icon name="menu" :size="14"/>{{ __('hc.columns') }}
        </button>
        <div id="column-picker-panel" class="hc-dropdown hc-column-menu" x-show="open" x-cloak role="dialog" aria-label="{{ __('hc.columns_title') }}">
            <p class="hc-hint">{{ __('hc.columns_hint') }}</p>
            @if($picker['error'])<p class="hc-hint" role="alert" style="color:var(--danger)">{{ $picker['error'] }}</p>@endif
            <ul class="hc-column-list" x-ref="list">
                @foreach($picker['items'] as $item)
                    <li data-key="{{ $item['key'] }}" wire:key="colpick-{{ $item['key'] }}">
                        <span class="hc-column-handle" aria-hidden="true" title="{{ __('hc.column_drag', ['name' => $item['label']]) }}">⋮⋮</span>
                        <label>
                            <input type="checkbox" @checked($item['visible']) @change="commit($event)">
                            <span>{{ $item['label'] }}</span>
                            @if($item['sensitive'])<span class="hc-view-tag">{{ __('hc.columns_sensitive') }}</span>@endif
                        </label>
                        <span class="hc-column-move">
                            <button type="button" @click="move($event, -1)" aria-label="{{ __('hc.column_move_up', ['name' => $item['label']]) }}">▲</button>
                            <button type="button" @click="move($event, 1)" aria-label="{{ __('hc.column_move_down', ['name' => $item['label']]) }}">▼</button>
                        </span>
                    </li>
                @endforeach
            </ul>
            @unless($picker['is_default'])
                <button type="button" class="hc-column-reset" wire:click="resetColumns">{{ __('hc.columns_reset') }}</button>
            @endunless
        </div>
    </div>
    @if($export && $picker['can_export'])
        <div class="hc-export" role="group" aria-label="{{ __('hc.export') }}" title="{{ __('hc.export_hint') }}">
            <x-hc.button variant="secondary" size="sm" wire:click="exportTable('xlsx')">{{ __('hc.export_xlsx') }}</x-hc.button>
            <x-hc.button variant="secondary" size="sm" wire:click="exportTable('csv')">{{ __('hc.export_csv') }}</x-hc.button>
        </div>
    @endif
</div>
