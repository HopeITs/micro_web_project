{{-- M4.19: the two sources of the audit log, plus the export buttons for the current filters. --}}
@php($locale = app()->getLocale())
@include('partials.filters-style')
<style>
    .audit-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 20px; border-block-end: 1px solid var(--line); }
    .audit-head .hc-chips { flex-wrap: wrap; }
    @media (max-width: 640px) { .audit-head { padding: 12px 16px; } .audit-head > * { width: 100%; } .audit-head > * > * { flex: 1 1 auto; justify-content: center; white-space: nowrap; } }
</style>
<div class="audit-head">
    <nav class="hc-chips" aria-label="{{ __('audit.title') }}">
        @foreach(['admin.audit' => ['audit.tab_web', 'globe'], 'admin.audit.desktop' => ['audit.tab_desktop', 'folder']] as $name => [$label, $icon])
            <a class="hc-chip" href="{{ route($name, ['locale' => $locale]) }}" aria-pressed="{{ request()->routeIs($name) ? 'true' : 'false' }}" @if(request()->routeIs($name)) aria-current="page" @endif><x-hc.icon :name="$icon" :size="16"/>{{ __($label) }}</a>
        @endforeach
    </nav>
    <div style="display:flex;gap:8px;align-items:center" title="{{ __('audit.export_hint') }}">
        @foreach(['xlsx' => 'export_xlsx', 'csv' => 'export_csv'] as $format => $label)
            <x-hc.button variant="secondary" size="sm" icon="file" :href="route($exportRoute, ['locale' => $locale, 'format' => $format] + $query)">{{ __('audit.'.$label) }}</x-hc.button>
        @endforeach
    </div>
</div>
