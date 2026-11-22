{{-- "Add new" from a list page: choose the project by its number or the beneficiary's name (uses the Ctrl+K window styles). --}}
<div class="hc-cmdk-root" x-data="{ open: false, show() { this.open = true; setTimeout(() => this.$refs.input.focus(), 80) }, hide() { this.open = false } }">
    <x-hc.button icon="plus" x-on:click="show()" aria-haspopup="dialog">{{ $label }}</x-hc.button>
    <div class="hc-cmdk-backdrop" x-show="open" x-cloak x-on:click="hide()" x-transition.opacity></div>
    <div class="hc-cmdk" role="dialog" aria-modal="true" aria-label="{{ $label }}" x-show="open" x-cloak x-trap.noscroll="open" x-on:keydown.escape.prevent.stop="hide()">
        <div class="hc-cmdk-field">
            <x-hc.icon name="search" :size="20"/>
            <input x-ref="input" autofocus type="search" class="hc-cmdk-input" wire:model.live.debounce.250ms="term" autocomplete="off" spellcheck="false"
                   placeholder="{{ __('hc.picker_placeholder') }}" aria-label="{{ __('hc.picker_placeholder') }}" aria-controls="picker-list-{{ $this->getId() }}">
            <span class="hc-cmdk-busy" wire:loading wire:target="term" aria-hidden="true"></span>
            <kbd class="ltr">Esc</kbd>
        </div>
        <div id="picker-list-{{ $this->getId() }}" class="hc-cmdk-list" role="listbox">
            @if($results)
                <div class="hc-cmdk-group" role="group" aria-label="{{ __('hc.cmdk_projects') }}">
                    <p class="hc-cmdk-label">{{ __('hc.picker_choose') }}</p>
                    @foreach($results as $item)
                        <a class="hc-cmdk-item" role="option" href="{{ $item['url'] }}" wire:key="pick-{{ $item['project'] }}">
                            <span class="hc-cmdk-icon"><x-hc.icon name="folder" :size="18"/></span>
                            <span class="hc-cmdk-text"><strong>{{ $item['title'] }}</strong>@if(! empty($item['meta']))<small>{{ $item['meta'] }}</small>@endif</span>
                            @if(! empty($item['state']))<x-hc.badge :tone="$item['tone']" :icon="$item['stateIcon']">{{ $item['state'] }}</x-hc.badge>@endif
                        </a>
                    @endforeach
                </div>
            @elseif(trim($term) !== '')
                <x-hc.empty :title="__('hc.cmdk_none_title')" icon="search">{{ __('hc.picker_none') }}</x-hc.empty>
            @else
                <p class="muted" style="padding:16px 20px;margin:0">{{ __('hc.picker_hint') }}</p>
            @endif
        </div>
        <div class="hc-cmdk-foot"><span><kbd>Esc</kbd> {{ __('hc.cmdk_close') }}</span></div>
    </div>
</div>
