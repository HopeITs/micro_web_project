{{--
    F3 Ctrl+K search (HopeHR's "search for a person, a page or a command"). Opened by Ctrl+K / ⌘K, by "/"
    outside a field, or by the header search button. Arrow keys move, Enter opens, Escape closes.
    Recent pages and commands (never people or projects) are kept in this browser only.
--}}
<div class="hc-cmdk-root" x-data="commandPalette" x-on:keydown.window="globalKey($event)">
    <button type="button" class="hc-icon-btn hc-cmdk-trigger" x-on:click="show()" aria-haspopup="dialog" aria-keyshortcuts="Control+K"
            aria-label="{{ __('hc.cmdk_trigger') }}" title="{{ __('hc.cmdk_trigger') }} (Ctrl+K)">
        <x-hc.icon name="search"/>
    </button>
    <div class="hc-cmdk-backdrop" x-show="open" x-cloak x-on:click="hide()" x-transition.opacity></div>
    <div class="hc-cmdk" role="dialog" aria-modal="true" aria-label="{{ __('hc.cmdk_title') }}" x-show="open" x-cloak x-trap.noscroll="open" x-on:keydown.escape.prevent.stop="hide()">
        <div class="hc-cmdk-field">
            <x-hc.icon name="search" :size="20"/>
            <input x-ref="input" type="search" class="hc-cmdk-input" wire:model.live.debounce.250ms="term" autocomplete="off" spellcheck="false"
                   placeholder="{{ __('hc.cmdk_placeholder') }}" aria-label="{{ __('hc.cmdk_placeholder') }}" role="combobox" aria-expanded="true" aria-controls="hc-cmdk-list"
                   x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)" x-on:keydown.enter.prevent="choose()">
            <span class="hc-cmdk-busy" wire:loading wire:target="term" aria-hidden="true"></span>
            <kbd class="ltr">Esc</kbd>
        </div>
        <div id="hc-cmdk-list" class="hc-cmdk-list" role="listbox" x-ref="list">
            <template x-if="recent.length && !$wire.term">
                <div class="hc-cmdk-group" role="group" aria-label="{{ __('hc.cmdk_recent') }}">
                    <p class="hc-cmdk-label">{{ __('hc.cmdk_recent') }}</p>
                    <template x-for="item in recent" :key="item.url">
                        <a class="hc-cmdk-item" role="option" :href="item.url" x-on:click="remember(item)" data-cmdk-item>
                            <span class="hc-cmdk-icon"><x-hc.icon name="clock" :size="18"/></span>
                            <span class="hc-cmdk-text"><strong x-text="item.title"></strong></span>
                        </a>
                    </template>
                </div>
            </template>
            @foreach(['commands' => 'cmdk_commands', 'projects' => 'cmdk_projects', 'people' => 'cmdk_people', 'pages' => 'cmdk_pages'] as $group => $label)
                @if($results[$group])
                    <div class="hc-cmdk-group" role="group" aria-label="{{ __('hc.'.$label) }}">
                        <p class="hc-cmdk-label">{{ __('hc.'.$label) }}</p>
                        @foreach($results[$group] as $item)
                            <a class="hc-cmdk-item" role="option" href="{{ $item['url'] }}" data-cmdk-item wire:key="{{ $group }}-{{ $loop->index }}-{{ md5($item['url']) }}"
                               @if(isset($item['key'])) x-on:click="remember({ title: @js($item['title']), url: @js($item['url']) })" @endif>
                                <span class="hc-cmdk-icon"><x-hc.icon :name="$item['icon']" :size="18"/></span>
                                <span class="hc-cmdk-text"><strong>{{ $item['title'] }}</strong>@if(! empty($item['meta']))<small>{{ $item['meta'] }}</small>@endif</span>
                                @if(! empty($item['state']))<x-hc.badge :tone="$item['tone']" :icon="$item['stateIcon']">{{ $item['state'] }}</x-hc.badge>@endif
                            </a>
                        @endforeach
                    </div>
                @endif
            @endforeach
            @if(mb_strlen(trim($term)) >= 2 && ! $results['people'] && ! $results['projects'] && ! $results['pages'] && ! $results['commands'])
                <x-hc.empty :title="__('hc.cmdk_none_title')" icon="search">{{ __('hc.cmdk_none') }}</x-hc.empty>
            @endif
        </div>
        <div class="hc-cmdk-foot"><span><kbd>↑</kbd><kbd>↓</kbd> {{ __('hc.cmdk_move') }}</span><span><kbd>Enter</kbd> {{ __('hc.cmdk_open') }}</span><span><kbd>Esc</kbd> {{ __('hc.cmdk_close') }}</span></div>
    </div>
</div>
