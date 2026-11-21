{{-- M4.19: the two sources of the audit log, plus the export buttons for the current filters. --}}
@php($locale = app()->getLocale())
<div class="hc-toolbar" style="padding-block:0 12px;justify-content:space-between;flex-wrap:wrap;gap:8px">
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
