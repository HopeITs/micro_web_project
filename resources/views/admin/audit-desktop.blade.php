@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $query = array_filter(collect($filters)->except(['format'])->all(), fn ($v) => $v !== '' && $v !== null);
@endphp
<x-layouts.hc :title="__('audit.title').' · '.__('audit.tab_desktop')">
    <div class="hc-page-head">
        <div><p class="hc-eyebrow">{{ __('hc.group_tools') }}</p><div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><h1>{{ __('audit.title') }}</h1><x-hc.badge>{{ __('audit.read_only') }}</x-hc.badge></div><p>{{ __('audit.desktop_intro') }}</p></div>
    </div>

    <x-hc.card :flush="true">
        @include('admin._audit-tabs', ['exportRoute' => 'admin.audit.desktop.export', 'query' => $query])
        <form class="hc-filters" method="GET" action="{{ route('admin.audit.desktop', ['locale' => $locale]) }}"><div class="hc-filters-grid">
            <div class="hc-field">
                <label class="hc-label" for="city">{{ __('audit.city') }}</label>
                <select id="city" name="city" class="hc-select"><option value="">{{ __('audit.all_cities') }}</option>
                    @foreach($cities as $c)<option value="{{ $c->id }}" @selected((int) ($filters['city'] ?? 0) === (int) $c->id)>{{ $en ? $c->name_en : $c->name_ar }}</option>@endforeach</select>
            </div>
            <div class="hc-field"><label class="hc-label" for="from">{{ __('audit.from') }}</label><input id="from" name="from" type="date" class="hc-input" value="{{ $filters['from'] ?? '' }}"></div>
            <div class="hc-field"><label class="hc-label" for="to">{{ __('audit.to') }}</label><input id="to" name="to" type="date" class="hc-input" value="{{ $filters['to'] ?? '' }}"></div>
            <div class="hc-field">
                <label class="hc-label" for="table">{{ __('audit.table') }}</label>
                <select id="table" name="table" class="hc-select"><option value="">{{ __('audit.any') }}</option>
                    @foreach($tables as $t)<option value="{{ $t }}" @selected(($filters['table'] ?? '') === $t)>{{ $t }}</option>@endforeach
                    <option value="-" @selected(($filters['table'] ?? '') === '-')>{{ __('audit.no_table') }}</option></select>
            </div>
            <div class="hc-field"><label class="hc-label" for="user">{{ __('audit.user') }}</label><input id="user" name="user" type="search" class="hc-input" maxlength="100" value="{{ $filters['user'] ?? '' }}" autocomplete="off"></div>
            <div class="hc-field grow"><label class="hc-label" for="q">{{ __('audit.q') }}</label><input id="q" name="q" type="search" class="hc-input" maxlength="100" value="{{ $filters['q'] ?? '' }}" autocomplete="off"></div>
            <div class="hc-filters-actions">
                <x-hc.button type="submit" variant="secondary" icon="search">{{ __('audit.filter') }}</x-hc.button>
                @if($query !== [])<x-hc.button variant="ghost" :href="route('admin.audit.desktop', ['locale' => $locale])">{{ __('audit.clear') }}</x-hc.button>@endif
            </div>
        </div></form>
        <div class="hc-toolbar" style="padding-block:0 12px"><span class="muted" aria-live="polite">{{ __('hc.results', ['count' => number_format($rows->total())]) }}</span></div>

        <div class="hc-table-wrap">
            @if($rows->isEmpty())
                <x-hc.empty :title="__('audit.none')">{{ __('hc.no_results_hint') }}</x-hc.empty>
            @else
            <table class="hc-table stack">
                <thead><tr><th>{{ __('audit.col.time') }}</th><th>{{ __('audit.col.user') }}</th><th>{{ __('audit.col.table') }}</th><th>{{ __('audit.col.description') }}</th><th>{{ __('audit.col.city') }}</th></tr></thead>
                <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td data-label="{{ __('audit.col.time') }}" class="num"><bdi class="ltr">{{ $row['time'] ?? '—' }}</bdi></td>
                        <td data-label="{{ __('audit.col.user') }}"><bdi>{{ $row['user'] ?? '—' }}</bdi></td>
                        <td data-label="{{ __('audit.col.table') }}"><bdi class="ltr">{{ $row['table'] ?? '—' }}</bdi></td>
                        <td data-label="{{ __('audit.col.description') }}" style="overflow-wrap:anywhere"><bdi>{{ $row['text'] }}</bdi></td>
                        <td data-label="{{ __('audit.col.city') }}">{{ $row['city'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
        @include('admin._audit-pages')
    </x-hc.card>
</x-layouts.hc>
