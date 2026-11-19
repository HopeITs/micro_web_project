@props(['label', 'value', 'note' => null, 'accent' => null])
<div {{ $attributes->merge(['class' => 'hc-card hc-stat']) }} @if($accent) style="--accent: {{ $accent }}" @endif>
    <span>{{ $label }}</span><strong>{{ $value }}</strong>@if($note)<small>{{ $note }}</small>@endif
</div>
