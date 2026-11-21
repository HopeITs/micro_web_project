{{-- M4.19: the two sources of the audit log, plus the export buttons for the current filters. --}}
@php($locale = app()->getLocale())
<style>
    /* Filters as an even grid sized by the card, not the window (the sidebar takes part of it): 4 columns wide, 2 medium, 1 narrow. */
    .audit-filters { container-type: inline-size; padding: 16px 20px; border-block-end: 1px solid var(--line); }
    .audit-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px 16px; align-items: end; }
    .audit-grid .hc-field { min-width: 0; }
    .audit-grid .grow { grid-column: span 2; }
    .audit-actions { display: flex; gap: 8px; }
    .audit-actions > * { flex: 1 1 auto; justify-content: center; }
    .audit-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 20px; border-block-end: 1px solid var(--line); }
    .audit-head .hc-chips { flex-wrap: wrap; }
    @container (max-width: 860px) { .audit-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .audit-grid .grow { grid-column: auto; } .audit-actions { grid-column: -2 / -1; } }
    @container (max-width: 480px) { .audit-grid { grid-template-columns: 1fr; } .audit-actions { grid-column: auto; } }
    @media (max-width: 640px) { .audit-filters { padding: 12px 16px; } .audit-head { padding: 12px 16px; } .audit-head > * { width: 100%; } .audit-head > * > * { flex: 1 1 auto; justify-content: center; white-space: nowrap; } }
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
