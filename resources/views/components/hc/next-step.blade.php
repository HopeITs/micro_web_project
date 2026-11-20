{{--
    "Next step" box (F2, borrowed from HC Tracking): one sentence for what has to happen next and who it
    waits on, with one action. late=true marks it as overdue (red: waits on someone and is late).
--}}
@props(['title', 'waiting' => null, 'href' => null, 'action' => null, 'late' => false, 'since' => null])
<section {{ $attributes->class(['hc-next', 'is-late' => $late]) }} aria-label="{{ __('hc.next_step') }}">
    <div>
        <p class="hc-next-eyebrow">{{ __('hc.next_step') }}</p>
        <p class="hc-next-title">{{ $title }}</p>
        @if($waiting || $since)
            <p class="hc-next-meta">@if($waiting)<x-hc.icon name="user" :size="15"/>{{ __('hc.waiting_on', ['who' => $waiting]) }}@endif
                @if($since)<span @class(['hc-next-since', 'is-late' => $late])><x-hc.icon name="clock" :size="15"/>{{ $since }}</span>@endif</p>
        @endif
    </div>
    @if($href && $action)<x-hc.button :href="$href" icon="arrow" size="sm">{{ $action }}</x-hc.button>@endif
</section>
