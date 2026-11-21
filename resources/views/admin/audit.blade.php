@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $query = array_filter(collect($filters)->except(['format'])->all(), fn ($v) => $v !== '' && $v !== null);
    $tone = ['change' => 'info', 'view' => null, 'security' => 'warn', 'denied' => 'danger'];
@endphp
<x-layouts.hc :title="__('audit.title')">
    <style>
        .audit-changes { margin-block-start: 4px; font-size: .875rem; }
        .audit-changes summary { cursor: pointer; color: var(--info); min-height: 32px; display: list-item; }
        .audit-changes dl { display: grid; grid-template-columns: minmax(6rem, max-content) 1fr; gap: 4px 12px; margin: 6px 0 0; }
        .audit-changes dt { color: var(--muted); }
        .audit-changes dd { margin: 0; overflow-wrap: anywhere; }
        .audit-changes del { opacity: .75; }
        .audit-changes ins { text-decoration: none; font-weight: 600; }
    </style>
    <div class="hc-page-head">
        <div><p class="hc-eyebrow">{{ __('hc.group_tools') }}</p><div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><h1>{{ __('audit.title') }}</h1><x-hc.badge>{{ __('audit.read_only') }}</x-hc.badge></div><p>{{ __('audit.intro') }}</p></div>
    </div>

    <x-hc.card :flush="true">
        @include('admin._audit-tabs', ['exportRoute' => 'admin.audit.export', 'query' => $query])
        <form class="audit-filters" method="GET" action="{{ route('admin.audit', ['locale' => $locale]) }}"><div class="audit-grid">
            <div class="hc-field">
                <label class="hc-label" for="city">{{ __('audit.city') }}</label>
                <select id="city" name="city" class="hc-select"><option value="">{{ __('audit.all_cities') }}</option>
                    @foreach($cities as $c)<option value="{{ $c->id }}" @selected((int) ($filters['city'] ?? 0) === (int) $c->id)>{{ $en ? $c->name_en : $c->name_ar }}</option>@endforeach</select>
            </div>
            <div class="hc-field"><label class="hc-label" for="from">{{ __('audit.from') }}</label><input id="from" name="from" type="date" class="hc-input" value="{{ $filters['from'] ?? '' }}"></div>
            <div class="hc-field"><label class="hc-label" for="to">{{ __('audit.to') }}</label><input id="to" name="to" type="date" class="hc-input" value="{{ $filters['to'] ?? '' }}"></div>
            <div class="hc-field">
                <label class="hc-label" for="kind">{{ __('audit.kind') }}</label>
                <select id="kind" name="kind" class="hc-select"><option value="">{{ __('audit.any') }}</option>
                    @foreach(__('audit.kinds') as $k => $label)<option value="{{ $k }}" @selected(($filters['kind'] ?? '') === $k)>{{ $label }}</option>@endforeach</select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="area">{{ __('audit.area') }}</label>
                <select id="area" name="area" class="hc-select"><option value="">{{ __('audit.any') }}</option>
                    @foreach(array_keys(\App\Services\Staff\AuditLogService::AREAS) as $a)<option value="{{ $a }}" @selected(($filters['area'] ?? '') === $a)>{{ __('audit.areas.'.$a) }}</option>@endforeach</select>
            </div>
            <div class="hc-field grow"><label class="hc-label" for="actor">{{ __('audit.actor') }}</label><input id="actor" name="actor" type="search" class="hc-input" maxlength="100" value="{{ $filters['actor'] ?? '' }}" autocomplete="off"></div>
            <div class="audit-actions">
                <x-hc.button type="submit" variant="secondary" icon="search">{{ __('audit.filter') }}</x-hc.button>
                @if($query !== [])<x-hc.button variant="ghost" :href="route('admin.audit', ['locale' => $locale])">{{ __('audit.clear') }}</x-hc.button>@endif
            </div>
        </div></form>
        <div class="hc-toolbar" style="padding-block:0 12px"><span class="muted" aria-live="polite">{{ __('hc.results', ['count' => number_format($rows->total())]) }} · {{ __('audit.scope_note') }}</span></div>

        <div class="hc-table-wrap">
            @if($rows->isEmpty())
                <x-hc.empty :title="__('audit.none')">{{ __('hc.no_results_hint') }}</x-hc.empty>
            @else
            <table class="hc-table stack">
                <thead><tr><th>{{ __('audit.col.time') }}</th><th>{{ __('audit.col.account') }}</th><th>{{ __('audit.col.action') }}</th><th>{{ __('audit.col.record') }}</th><th>{{ __('audit.col.city') }}</th><th>{{ __('audit.col.kind') }}</th></tr></thead>
                <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td data-label="{{ __('audit.col.time') }}" class="num"><bdi class="ltr">{{ $row['time'] }}</bdi></td>
                        <td data-label="{{ __('audit.col.account') }}">@if($row['actor'])<bdi class="ltr">{{ $row['actor'] }}</bdi>@else<span class="muted">—</span>@endif</td>
                        <td data-label="{{ __('audit.col.action') }}"><span title="{{ $row['event'] }}">{{ $row['label'] }}</span>@if($row['decision'] && $row['kind'] === 'security')<br><small class="muted ltr"><bdi>{{ $row['decision'] }}</bdi></small>@endif
                            @if($row['changes'] !== [])
                                <details class="audit-changes"><summary>{{ trans_choice('audit.changes_summary', count($row['changes'])) }}</summary>
                                    <dl>@foreach($row['changes'] as $c)<dt>{{ $c['label'] }}</dt><dd>@if($c['hidden'])<span class="muted">{{ __('audit.changed') }}</span>@else<del><bdi>{{ $c['before'] ?? '—' }}</bdi></del> <span aria-hidden="true">{{ $en ? '→' : '←' }}</span><ins><bdi>{{ $c['after'] ?? '—' }}</bdi></ins>@if($c['cut']) <small class="muted">({{ __('audit.cut') }})</small>@endif @endif</dd>@endforeach</dl>
                                </details>
                            @endif</td>
                        <td data-label="{{ __('audit.col.record') }}">@if($row['url'])<a class="row-link" href="{{ $row['url'] }}"><bdi>{{ $row['subject'] }}</bdi></a>@elseif($row['subject'])<bdi>{{ $row['subject'] }}</bdi>@else<span class="muted">—</span>@endif</td>
                        <td data-label="{{ __('audit.col.city') }}">{{ $row['city'] ?? '—' }}</td>
                        <td data-label="{{ __('audit.col.kind') }}"><x-hc.badge :tone="$tone[$row['kind']]">{{ __('audit.kinds.'.$row['kind']) }}@if($row['outcome'] === 'failed') · {{ __('audit.outcomes.failed') }}@endif</x-hc.badge></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
        @include('admin._audit-pages')
    </x-hc.card>
</x-layouts.hc>
