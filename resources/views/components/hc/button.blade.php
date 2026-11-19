@props(['variant' => 'primary', 'size' => null, 'href' => null, 'icon' => null, 'type' => 'button'])
@php($class = 'hc-btn hc-btn-'.$variant.($size ? ' hc-btn-'.$size : ''))
@if($href)
<a href="{{ $href }}" {{ $attributes->merge(['class' => $class]) }}>@if($icon)<x-hc.icon :name="$icon" :size="18"/>@endif{{ $slot }}</a>
@else
<button type="{{ $type }}" {{ $attributes->merge(['class' => $class]) }}>@if($icon)<x-hc.icon :name="$icon" :size="18"/>@endif{{ $slot }}</button>
@endif
