{{-- My desk (X3): what waits on the account, its saved views and reports as live cards, quick actions, recently opened projects. --}}
@php($locale = app()->getLocale())
<div class="hc-desk">
    <section aria-labelledby="desk-waiting" style="margin-block-end:24px">
        <h2 id="desk-waiting" style="font-size:1.05rem;margin:0 0 10px">{{ __('desk.waiting') }}</h2>
        @if($tiles === [])
            <x-hc.card>
                <x-hc.empty :title="__('desk.waiting_none')" icon="check">{{ __('desk.waiting_none_hint') }}
                    <x-slot:action><x-hc.button :href="route('projects.index', ['locale' => $locale])" icon="folder" variant="secondary">{{ __('hc.all_projects') }}</x-hc.button></x-slot:action>
                </x-hc.empty>
            </x-hc.card>
        @else
            <div class="hc-grid hc-grid-stats">
                @foreach($tiles as $tile)
                    <x-hc.count-tile :count="$tile['count']" :label="__('desk.tile.'.$tile['key'])" :tone="$tile['tone']" :icon="$tile['icon']" :href="$tile['href']" data-desk-tile="{{ $tile['key'] }}" wire:key="tile-{{ $tile['key'] }}"/>
                @endforeach
            </div>
        @endif
    </section>

    @if($actions !== [])
    <section aria-labelledby="desk-actions" style="margin-block-end:24px">
        <h2 id="desk-actions" style="font-size:1.05rem;margin:0 0 10px">{{ __('desk.actions') }}</h2>
        <div class="hc-chips" style="flex-wrap:wrap">
            @foreach($actions as $action)
                <x-hc.button :href="$action['href']" :icon="$action['icon']" variant="secondary" wire:key="qa-{{ $action['key'] }}">{{ __('desk.action.'.$action['key']) }}</x-hc.button>
            @endforeach
        </div>
    </section>
    @endif

    <section aria-labelledby="desk-views" style="margin-block-end:24px" wire:init="loadCards">
        <h2 id="desk-views" style="font-size:1.05rem;margin:0 0 10px">{{ __('desk.views') }}</h2>
        @if($cards === null)
            <p class="muted" role="status">{{ __('desk.loading') }}</p>
        @elseif($cards === [])
            <x-hc.card>
                <x-hc.empty :title="__('desk.views_none')" icon="search">{{ __('desk.views_none_hint') }}
                    <x-slot:action><x-hc.button :href="route('projects.index', ['locale' => $locale])" icon="folder" variant="secondary">{{ __('hc.all_projects') }}</x-hc.button></x-slot:action>
                </x-hc.empty>
            </x-hc.card>
        @else
            <div class="hc-grid" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr))">
                @foreach($cards as $card)
                    @include('desk.card', ['card' => $card])
                @endforeach
            </div>
            @if($savedTotal > count($cards))
                <p class="muted" style="margin-block-start:8px">{{ __('desk.views_more', ['count' => $savedTotal - count($cards)]) }}
                    <a href="{{ route('projects.index', ['locale' => $locale]) }}">{{ __('desk.views_all') }}</a> · <a href="{{ route('reports.index', ['locale' => $locale]) }}">{{ __('desk.reports_all') }}</a></p>
            @endif
        @endif
    </section>

    @include('desk.recent')
</div>
