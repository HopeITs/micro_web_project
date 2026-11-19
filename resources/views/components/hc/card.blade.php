@props(['title' => null, 'subtitle' => null, 'flush' => false])
<section {{ $attributes->merge(['class' => 'hc-card']) }}>
    @if($title || isset($actions))
    <header class="hc-card-head"><div>@if($title)<h2>{{ $title }}</h2>@endif @if($subtitle)<p class="muted" style="font-size:.85rem">{{ $subtitle }}</p>@endif</div>{{ $actions ?? '' }}</header>
    @endif
    @if($flush){{ $slot }}@else<div class="hc-card-body">{{ $slot }}</div>@endif
    @isset($footer)<footer class="hc-card-foot">{{ $footer }}</footer>@endisset
</section>
