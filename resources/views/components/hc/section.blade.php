@props(['title', 'step' => null, 'open' => true])
<details {{ $attributes->merge(['class' => 'hc-section']) }} @if($open) open @endif>
    <summary>@if($step)<span class="step">{{ $step }}</span>@endif<span>{{ $title }}</span><x-hc.icon name="chevron" class="chev"/></summary>
    <div class="hc-section-body">{{ $slot }}</div>
</details>
