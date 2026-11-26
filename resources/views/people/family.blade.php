@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $named = collect($homes)->filter(fn ($h) => $h['head_name'] !== null)->values();
    $title = $named->count() === 1 ? __('families.family_of', ['name' => $named[0]['head_name']]) : __('families.book', ['number' => $family->family_number]);
@endphp
<x-layouts.hc :title="$title">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('families.index', ['locale' => $locale]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('families.back') }}</a></p>
    <div class="hc-page-head">
        <div>
            <p class="hc-eyebrow">{{ $en ? $city->name_en : $city->name_ar }} · {{ __('families.family') }}</p>
            <h1>{{ $title }}</h1>
            <p class="fam-book">
                @if($named->count() === 1)<span>{{ __('people.family_number') }}: <strong class="ltr num">{{ $family->family_number }}</strong></span>
                @else<span>{{ __('families.household_count', ['count' => count($homes)]) }}</span>@endif
                @include('people._copy', ['text' => $family->family_number])
            </p>
        </div>
    </div>

    @if(count($homes) > 1)
        <x-hc.alert type="warning" style="margin-block-end:16px" :title="__('families.several_title', ['count' => count($homes)])">
            {{ __('families.several_text') }}
            <span class="fam-jump">@foreach($homes as $home)<a class="hc-chip" href="#h-{{ $home['head_id'] }}">{{ $home['head_name'] ?? __('families.no_head') }} <span>{{ $home['totals']['members'] }}</span></a>@endforeach</span>
        </x-hc.alert>
    @endif
    @if($elsewhere->isNotEmpty())
        <x-hc.alert type="warning" style="margin-block-end:16px" :title="__('families.elsewhere_title')">
            @foreach($elsewhere as $other)
                <a class="hc-link" href="{{ route('families.show', ['locale' => $locale, 'family' => $other->family_id]) }}#h-{{ $other->head_id }}">{{ $other->head_name ?? __('families.no_head') }}</a>@if(! $loop->last)، @endif
            @endforeach
        </x-hc.alert>
    @endif

    <div class="hc-grid">
        @forelse($homes as $home)
            <livewire:family-panel :family-id="(int) $family->id" :head-id="$home['head_id']" :compact="false" :key="'h-'.$family->id.'-'.$home['head_id']" />
        @empty
            <x-hc.card><x-hc.empty :title="__('people.no_family')" icon="users">{{ __('families.empty_hint') }}</x-hc.empty></x-hc.card>
        @endforelse
    </div>
</x-layouts.hc>
