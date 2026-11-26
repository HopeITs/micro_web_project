{{--
    The editor of one filter (used by <x-hc.report-filters>): a panel under its chip, a bottom sheet on phones.
      list    a searchable checklist (Arabic letters folded) with the records each value would give, "is one of" / "is none of",
              tick all shown, invert, clear; values that would give nothing are folded away until asked for
      choice  one of a few options
      date    ready periods and a from / to range
      amount  a from / to range
    Every change goes to the component (HasReportFilters), which checks it. The panel's own behaviour is insEditor / insOptions in
    resources/js/modules/insights.js.
--}}
@props(['editor', 'notice' => null])
@php($key = $editor['key'])
<div class="ins-backdrop ins-sheet-only" wire:key="backdrop-{{ $key }}" wire:click="closeFilter" aria-hidden="true"></div>
<div class="ins-pop" wire:key="editor-{{ $key }}" wire:ignore.self role="dialog" aria-label="{{ $editor['label'] }}" tabindex="-1"
     x-data="insEditor('{{ $key }}')" x-anchor.bottom-start.offset.6="document.getElementById('flt-{{ $key }}')" x-trap.noautofocus="sheet"
     @keydown.escape.stop.prevent="close()" @click.outside="outside($event)">
    <div class="ins-pop-head">
        <strong>{{ $editor['label'] }}</strong>
        <button type="button" class="ins-pop-close" @click="close()" aria-label="{{ __('search.ui.close') }}" title="{{ __('search.ui.close') }}"><x-hc.icon name="x" :size="18"/></button>
    </div>

    @if($editor['type'] === 'list')
        <div class="ins-list" x-data="insOptions">
            <div class="ins-seg" role="group" aria-label="{{ __('search.ui.mode') }}">
                <button type="button" wire:click="setExclude('{{ $key }}', false)" aria-pressed="{{ $editor['exclude'] ? 'false' : 'true' }}">{{ __('search.ui.include') }}</button>
                <button type="button" wire:click="setExclude('{{ $key }}', true)" aria-pressed="{{ $editor['exclude'] ? 'true' : 'false' }}">{{ __('search.ui.exclude') }}</button>
            </div>
            @if($editor['exclude'])<p class="ins-hint">{{ __('search.ui.exclude_hint') }}</p>@endif
            <label class="ins-find">
                <x-hc.icon name="search" :size="16"/>
                <span class="sr-only">{{ __('search.ui.find', ['field' => $editor['label']]) }}</span>
                <input type="search" x-model="q" data-autofocus placeholder="{{ __('search.ui.find', ['field' => $editor['label']]) }}" autocomplete="off"
                       @keydown.down.prevent="focusFirst()" @keydown.enter.prevent="tickOnly()">
            </label>
            <div class="ins-tools">
                <button type="button" @click="all('{{ $key }}')">{{ __('search.ui.select_all') }}</button>
                <button type="button" @click="invert('{{ $key }}')">{{ __('search.ui.invert') }}</button>
                <button type="button" wire:click="setValues('{{ $key }}', [])" @disabled($editor['selected'] === [])>{{ __('search.ui.clear') }}</button>
            </div>
            <ul class="ins-options" x-ref="list" @keydown.down.prevent="step($event, 1)" @keydown.up.prevent="step($event, -1)">
                @foreach($editor['options'] as $option)
                    <li wire:key="opt-{{ $key }}-{{ $option['value'] }}" data-label="{{ $option['label'] }}" data-value="{{ $option['value'] }}" data-count="{{ $option['count'] }}" data-selected="{{ $option['selected'] ? '1' : '0' }}">
                        <label>
                            <input type="checkbox" value="{{ $option['value'] }}" @checked($option['selected']) wire:click="toggleValue('{{ $key }}', '{{ $option['value'] }}')">
                            <span class="ins-option-label">{{ $option['label'] }}</span>
                            <span class="ins-count num" title="{{ __('search.ui.count_hint') }}">{{ number_format($option['count']) }}</span>
                        </label>
                    </li>
                @endforeach
            </ul>
            <p class="ins-hint" x-show="shown === 0" x-cloak role="status">{{ __('search.ui.no_values') }}</p>
            <div class="ins-pop-foot">
                <span aria-live="polite">{{ __('search.ui.selected', ['count' => count($editor['selected'])]) }}</span>
                <button type="button" class="ins-link" x-show="hidden > 0" x-cloak @click="empty = ! empty" :aria-pressed="empty ? 'true' : 'false'">
                    <span x-show="! empty">{{ __('search.ui.show_empty') }} (<span x-text="hidden"></span>)</span><span x-show="empty">{{ __('search.ui.hide_empty') }}</span>
                </button>
                <button type="button" class="hc-btn hc-btn-primary hc-btn-sm" @click="close()">{{ __('search.ui.done') }}</button>
            </div>
        </div>
    @elseif($editor['type'] === 'choice')
        <div class="ins-choices" role="radiogroup" aria-label="{{ $editor['label'] }}">
            @foreach($editor['choices'] as $i => $choice)
                <button type="button" role="radio" wire:key="choice-{{ $key }}-{{ $i }}" aria-checked="{{ $choice['selected'] ? 'true' : 'false' }}" @if($choice['selected']) data-autofocus @endif
                        wire:click="setChoice('{{ $key }}', '{{ $choice['value'] }}')">
                    <span>{{ $choice['label'] }}</span><span class="ins-count num">{{ number_format($choice['count']) }}</span>
                </button>
            @endforeach
        </div>
        <div class="ins-pop-foot"><span></span><button type="button" class="hc-btn hc-btn-primary hc-btn-sm" @click="close()">{{ __('search.ui.done') }}</button></div>
    @else
        @if($editor['type'] === 'date')
            <div class="ins-presets" role="group" aria-label="{{ __('search.ui.presets') }}">
                @foreach($editor['presets'] as $i => $preset)
                    <button type="button" wire:key="preset-{{ $key }}-{{ $preset['value'] }}" aria-pressed="{{ $preset['selected'] ? 'true' : 'false' }}" @if($i === 0) data-autofocus @endif
                            wire:click="setPreset('{{ $key }}', '{{ $preset['value'] }}')">{{ $preset['label'] }}</button>
                @endforeach
            </div>
            <p class="ins-sub">{{ __('search.ui.custom') }}</p>
        @endif
        <form class="ins-range" wire:key="range-{{ $key }}-{{ $editor['from'] }}-{{ $editor['to'] }}" x-data="{ from: @js($editor['from']), to: @js($editor['to']) }"
              @submit.prevent="$wire.setRange('{{ $key }}', String(from ?? ''), String(to ?? ''))">
            <label><span>{{ __('search.ui.from') }}</span>
                <input class="hc-input num ltr" x-model="from" @if($editor['type'] === 'date') type="date" min="1900-01-01" max="2100-12-31" @else type="number" min="0" step="any" inputmode="decimal" data-autofocus @endif></label>
            <label><span>{{ __('search.ui.to') }}</span>
                <input class="hc-input num ltr" x-model="to" @if($editor['type'] === 'date') type="date" min="1900-01-01" max="2100-12-31" @else type="number" min="0" step="any" inputmode="decimal" @endif></label>
            <button type="submit" class="hc-btn hc-btn-secondary hc-btn-sm">{{ __('search.ui.apply') }}</button>
        </form>
        <p class="ins-hint">{{ __('search.ui.open_end') }}</p>
        <div class="ins-pop-foot">
            @if($editor['active'])<button type="button" class="ins-link" wire:click="removeFilter('{{ $key }}')">{{ __('search.ui.clear') }}</button>@else<span></span>@endif
            <button type="button" class="hc-btn hc-btn-primary hc-btn-sm" @click="close()">{{ __('search.ui.done') }}</button>
        </div>
    @endif
    @if($notice)<p class="ins-notice" role="alert">{{ $notice }}</p>@endif
</div>
