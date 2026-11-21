@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $h = $hub['header'];
    $status = \App\Support\ProjectStatus::class;
    $current = $hub['current'];
    $cards = $hub['cards'];
    $opening = $cards->firstWhere('phase', 'opening');
    $closing = $cards->firstWhere('phase', 'closing');
    $pid = $hub['project']->id;
    $card = function ($visit) use ($locale, $pid) { return route('visits.show', ['locale' => $locale, 'project' => $pid, 'kind' => $visit->kind]); };
@endphp
<div class="hc-grid">
    <x-hc.card>
        <dl class="hc-dl">
            <div><dt>{{ __('visits.header.number') }}</dt><dd class="ltr num">{{ $h['number'] }}</dd></div>
            <div><dt>{{ __('visits.header.beneficiary') }}</dt><dd>{{ $h['beneficiary'] ?: '—' }}</dd></div>
            <div><dt>{{ __('visits.header.amount') }}</dt><dd class="num">{{ $h['amount'] ?? __('visits.header.no_loan') }}</dd></div>
            <div><dt>{{ __('visits.header.loan_date') }}</dt><dd class="num">{{ $h['loan_date'] ?: '—' }}</dd></div>
            <div><dt>{{ __('finance.state') }}</dt><dd>@if($current)<x-hc.badge :tone="$status::tone($current->state_en)" :icon="$status::icon($current->state_en)">{{ $en && $current->state_en ? $current->state_en : $current->state_ar }}</x-hc.badge>@else — @endif</dd></div>
        </dl>
        @if($hub['class']['rehab'])<x-hc.alert type="info" style="margin-block-start:12px">{{ __('visits.rehab_note') }}</x-hc.alert>
        @elseif($hub['class']['vehicle'])<p class="hc-hint" style="margin:12px 0 0">{{ __('visits.vehicle_note') }}</p>@endif
        @if(! $hub['funded'])<x-hc.alert type="warning" style="margin-block-start:12px">{{ __('visits.not_funded') }}</x-hc.alert>@endif
    </x-hc.card>

    @if($hub['canVisits'])
    <div class="hc-grid" style="grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); align-items:start">
        @foreach(['opening' => $opening, 'closing' => $closing] as $phase => $visit)
            @continue($phase === 'opening' && $hub['class']['rehab'] && ! $visit)
            <x-hc.card :title="$visit ? __('visits.kinds.'.$visit->kind) : __('visits.phases.'.$phase)">
                @if($visit)
                    <dl class="hc-dl">
                        <div><dt>{{ __('visits.fields.date') }}</dt><dd class="num">{{ $visit->date ?: '—' }}</dd></div>
                        <div><dt>{{ __('visits.fields.visitors') }}</dt><dd>{{ $visit->visitors ? implode('، ', $visit->visitors) : '—' }}</dd></div>
                    </dl>
                    <p style="margin:12px 0 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                        @if($visit->kobo)<x-hc.badge tone="info" icon="sync">{{ __('visits.kobo') }}</x-hc.badge>@endif
                        <a class="hc-btn hc-btn-secondary hc-btn-sm" href="{{ $card($visit) }}"><x-hc.icon name="eye" :size="16"/>{{ __('visits.open') }}</a>
                    </p>
                @else
                    <p class="muted">{{ __('visits.none') }}</p>
                    @php($kind = collect($hub['addable'])->first(fn ($k) => \App\Services\Field\VisitKinds::phase($k) === $phase))
                    @if($kind)
                        <x-hc.button icon="plus" variant="secondary" :href="route('visits.show', ['locale' => $locale, 'project' => $pid, 'kind' => $kind])">{{ __('visits.add.'.($kind === 'R-cl' ? 'rehab' : $phase)) }}</x-hc.button>
                    @endif
                @endif
            </x-hc.card>
        @endforeach
    </div>
    @endif

    @if($hub['canMe'] && ! ($hub['class']['rehab'] && $hub['people']->every(fn ($p) => $p->visits->isEmpty())))
    <x-hc.card :title="__('visits.phases.me')" :flush="true">
        @foreach($hub['people'] as $person)
            <div style="padding:16px 20px;border-block-end:1px solid var(--line)" wire:key="person-{{ $person->id }}">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between">
                    <strong>{{ $person->name }} @if(! $person->beneficiary)<span class="muted">· {{ $person->role ?: __('visits.me.partner') }}</span>@endif</strong>
                    @if($hub['meCreatable'] && $person->linked)
                        <x-hc.button size="sm" variant="secondary" icon="plus" :href="route('visits.me.create', ['locale' => $locale, 'project' => $pid, 'person' => $person->id])">{{ __('visits.add.me') }} · M{{ $person->next }}</x-hc.button>
                    @endif
                </div>
                @if($person->visits->isEmpty())
                    <p class="muted" style="margin:8px 0 0">{{ __('visits.me.none') }}</p>
                @else
                <div class="hc-table-wrap" style="margin-block-start:8px">
                    <table class="hc-table stack">
                        <thead><tr><th>{{ __('visits.me.number') }}</th><th>{{ __('visits.fields.date') }}</th><th class="num">{{ __('visits.me.result') }}</th><th>{{ __('visits.me.evaluation') }}</th><th>{{ __('visits.fields.visitors') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach($person->visits as $v)
                            <tr wire:key="me-{{ $v->id }}">
                                <td data-label="{{ __('visits.me.number') }}"><span class="ltr">M{{ $v->number }}</span> @if($v->kobo)<x-hc.badge tone="info" icon="sync">{{ __('visits.kobo') }}</x-hc.badge>@endif</td>
                                <td data-label="{{ __('visits.fields.date') }}" class="num">{{ $v->date ?: '—' }}</td>
                                <td data-label="{{ __('visits.me.result') }}" class="num">{{ $v->result ?? '—' }}</td>
                                <td data-label="{{ __('visits.me.evaluation') }}">{{ $v->evaluation ? ($en && $v->evaluation->name_en ? $v->evaluation->name_en : $v->evaluation->name_ar) : '—' }}</td>
                                <td data-label="{{ __('visits.fields.visitors') }}">{{ $v->visitors ? implode('، ', $v->visitors) : '—' }}</td>
                                <td class="full"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('visits.me.show', ['locale' => $locale, 'project' => $pid, 'visit' => $v->id]) }}"><x-hc.icon name="eye" :size="16"/>{{ __('visits.open') }}</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        @endforeach
    </x-hc.card>
    @endif
</div>
