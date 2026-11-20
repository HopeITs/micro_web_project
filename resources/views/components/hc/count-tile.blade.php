{{--
    Count tile (F3, borrowed from HC Tracking's "Needs attention / Drafts / Approved"): a number over a
    list that filters the list when pressed. Give href (a link) or wire:click (Livewire); active marks
    the filter in use (aria-pressed). tone = ok | warn | info | danger | null, icon optional.
--}}
@props(['count', 'label', 'tone' => null, 'icon' => null, 'href' => null, 'active' => false])
@php($class = 'hc-count'.($tone ? ' hc-count-'.$tone : '').($active ? ' is-active' : ''))
@if($href)
<a href="{{ $href }}" {{ $attributes->merge(['class' => $class]) }} @if($active) aria-current="true" @endif>
@else
<button type="button" {{ $attributes->merge(['class' => $class]) }} aria-pressed="{{ $active ? 'true' : 'false' }}">
@endif
    <strong class="num">{{ is_numeric($count) ? number_format((float) $count) : $count }}</strong>
    <span>@if($icon)<x-hc.icon :name="$icon" :size="15"/>@endif{{ $label }}</span>
@if($href)</a>@else</button>@endif
