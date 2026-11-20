{{--
    Quick filter chip (F3, from HC Tracking's "All 3 · Draft 3"): a pill that switches one filter on or
    off, with an optional count. Wrap several in <div class="hc-chips" role="group" aria-label="…">.
--}}
@props(['active' => false, 'count' => null, 'href' => null])
@if($href)
<a href="{{ $href }}" {{ $attributes->class(['hc-chip', 'is-active' => $active]) }} @if($active) aria-current="true" @endif>{{ $slot }}@if($count !== null)<span class="num">{{ $count }}</span>@endif</a>
@else
<button type="button" {{ $attributes->class(['hc-chip', 'is-active' => $active]) }} aria-pressed="{{ $active ? 'true' : 'false' }}">{{ $slot }}@if($count !== null)<span class="num">{{ $count }}</span>@endif</button>
@endif
