{{--
    "My views" of the search and statistics pages (App\Livewire\Concerns\HasReportViews): own views and the ones colleagues shared; a
    view keeps cities, filters and page settings, never the search text. Same look as the views row of x-hc.filter-bar.
--}}
@props(['views'])
@php($active = $views['active'])
<div {{ $attributes->class('hc-views-row ins-views') }} role="group" aria-label="{{ __('hc.views_mine') }}" x-data="{ saving: false }">
    <span class="ins-bar-label">{{ __('hc.views_mine') }}</span>
    @foreach($views['list'] as $v)
        <button type="button" class="hc-view-chip @if($v['active']) is-active @endif" wire:key="view-{{ $v['id'] }}" wire:click="applyView({{ $v['id'] }})"
                aria-pressed="{{ $v['active'] ? 'true' : 'false' }}" @if(! $v['own']) title="{{ __('hc.view_shared_by', ['name' => $v['owner']]) }}" @endif>
            @if(! $v['own'])<x-hc.icon name="users" :size="14"/>@elseif($v['shared'])<x-hc.icon name="share" :size="14"/>@endif
            <span>{{ $v['name'] }}</span>
        </button>
    @endforeach
    @if($active && $active['own'])
        @if($active['shared'] || $views['can_share'])
            <button type="button" class="ins-link" wire:key="view-share" wire:click="toggleShareView({{ $active['id'] }})">{{ $active['shared'] ? __('hc.view_unshare') : __('hc.view_share') }}</button>
        @endif
        <button type="button" class="ins-link is-danger" wire:key="view-delete" wire:click="deleteView({{ $active['id'] }})" wire:confirm="{{ __('hc.view_confirm_delete') }}">{{ __('hc.view_delete') }}</button>
    @endif
    <button type="button" class="ins-link" x-ref="save" @click="saving = ! saving; if (saving) $nextTick(() => $refs.name.focus())" :aria-expanded="saving ? 'true' : 'false'" title="{{ __('hc.view_save_as') }}"><x-hc.icon name="plus" :size="14"/>{{ __('hc.view_save') }}</button>
    <form class="hc-view-form" x-show="saving" x-cloak x-data="{ name: '' }" @submit.prevent="$wire.saveView(name).then(() => { if (! $wire.viewError) { saving = false; name = '' } })">
        <label class="hc-field grow"><span class="hc-label">{{ __('hc.view_name') }}</span>
            <input class="hc-input" x-ref="name" x-model="name" maxlength="120" autocomplete="off" @keydown.escape.stop="saving = false; $refs.save.focus()"></label>
        <x-hc.button type="submit" variant="secondary" size="sm">{{ __('hc.view_save') }}</x-hc.button>
        <x-hc.button type="button" variant="ghost" size="sm" x-on:click="saving = false">{{ __('hc.view_cancel') }}</x-hc.button>
        <span class="hc-hint">{{ __('hc.view_save_hint') }}</span>
    </form>
    @if($views['error'])
        <p class="hc-view-msg is-error" role="alert">{{ $views['error'] }}</p>
    @elseif($views['notice'])
        <p class="hc-view-msg" role="status">{{ $views['notice'] }}</p>
    @endif
</div>
