@props(['tone' => null])
<span {{ $attributes->merge(['class' => 'hc-badge'.($tone ? ' hc-badge-'.$tone : '')]) }}>{{ $slot }}</span>
