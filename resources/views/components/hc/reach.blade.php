{{--
    Reach strip (X4, owner decision 28 Nov 2026): the three counting units side by side, with a note on how each is
    calculated. <x-hc.reach :reach="app(\App\Services\Reports\ReachCounter::class)->count($projectsQuery)"/>
    reach = ['projects' => int, 'beneficiaries' => int, 'reached' => int, 'partners' => int, 'without_person' => int]
--}}
@props(['reach'])
@php
    $fmt = fn ($n) => number_format((int) $n);
    $items = [
        ['unit' => 'projects', 'icon' => 'folder', 'value' => $reach['projects'], 'note' => null],
        ['unit' => 'beneficiaries', 'icon' => 'user', 'value' => $reach['beneficiaries'],
            'note' => ($reach['partners'] ?? 0) > 0 ? __('hc.reach_partners_note', ['count' => $fmt($reach['partners'])]) : __('hc.reach_beneficiaries_hint')],
        ['unit' => 'reached', 'icon' => 'users', 'value' => $reach['reached'], 'note' => __('hc.reach_reached_hint')],
    ];
@endphp
<section {{ $attributes->merge(['class' => 'hc-reach']) }} aria-label="{{ __('hc.reach_title') }}">
    @foreach($items as $item)
        <div class="hc-reach-item" data-unit="{{ $item['unit'] }}">
            <span class="hc-reach-icon" aria-hidden="true"><x-hc.icon :name="$item['icon']" :size="20"/></span>
            <div style="min-width:0">
                <strong data-reach="{{ $item['unit'] }}">{{ $fmt($item['value']) }}</strong>
                <span>{{ __('hc.reach_'.$item['unit']) }}</span>
                @if($item['note'])<small title="{{ $item['note'] }}">{{ $item['note'] }}</small>@endif
            </div>
        </div>
    @endforeach
    <details class="hc-reach-how" x-data @click.outside="$el.open = false" @keydown.escape="$el.open = false">
        <summary><x-hc.icon name="info" :size="16"/><span>{{ __('hc.reach_how') }}</span></summary>
        <dl class="hc-reach-note">
            <div><dt>{{ __('hc.reach_projects') }}</dt><dd>{{ __('hc.reach_projects_def') }}</dd></div>
            <div><dt>{{ __('hc.reach_beneficiaries') }}</dt><dd>{{ __('hc.reach_beneficiaries_def') }}@if(($reach['without_person'] ?? 0) > 0) {{ __('hc.reach_without_person', ['count' => $fmt($reach['without_person'])]) }}@endif</dd></div>
            <div><dt>{{ __('hc.reach_reached') }}</dt><dd>{{ __('hc.reach_reached_def') }}</dd></div>
        </dl>
    </details>
</section>
