{{-- Status chip (U1): the colour is never alone, every tone carries its own icon; neutral/brand keep the dot. --}}
@props(['tone' => null, 'icon' => null])
@php($icon ??= ['ok' => 'check', 'warn' => 'clock', 'info' => 'flag', 'danger' => 'x'][$tone] ?? null)
<span {{ $attributes->merge(['class' => 'hc-badge'.($tone ? ' hc-badge-'.$tone : '').($icon ? ' has-icon' : '')]) }}>@if($icon)<x-hc.icon :name="$icon" :size="13"/>@endif{{ $slot }}</span>
