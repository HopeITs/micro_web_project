@php
    $locale = app()->getLocale();
    $en = $locale === 'en';
    $query = array_filter($filters, fn ($v) => $v !== '' && $v !== null);
    $grouped = collect($papers)->groupBy('group');
@endphp
<x-layouts.hc :title="__('papers.title')">
    <style>
        .paper-files { display: flex; flex-wrap: wrap; gap: 6px; }
        .paper-file { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 4px 10px; border: 1px solid var(--line); border-radius: var(--radius-sm); font-size: .875rem; text-decoration: none; color: inherit; background: var(--surface); }
        .paper-file:hover { border-color: var(--brand); }
        .paper-file .fmt { font-size: .75rem; font-weight: 600; color: var(--muted); }
        .paper-file[aria-disabled="true"] { opacity: .55; pointer-events: none; }
        .paper-id { font-variant-numeric: tabular-nums; color: var(--muted); font-size: .8125rem; }
    </style>
    <div class="hc-page-head">
        <div><p class="hc-eyebrow">{{ __('hc.group_tools') }}</p><h1>{{ __('papers.title') }}</h1><p>{{ __('papers.intro') }}</p></div>
    </div>
    @if(! $installed)<x-hc.alert type="warning" style="margin-block-end:16px">{{ __('papers.not_installed') }}</x-hc.alert>@endif

    <x-hc.card :flush="true">
        <form class="hc-toolbar" method="GET" action="{{ route('papers.index', ['locale' => $locale]) }}">
            <div class="hc-field grow"><label class="hc-label" for="q">{{ __('papers.search') }}</label><input id="q" name="q" type="search" class="hc-input" maxlength="100" value="{{ $filters['q'] ?? '' }}" autocomplete="off"></div>
            <div class="hc-field">
                <label class="hc-label" for="city">{{ __('papers.city') }}</label>
                <select id="city" name="city" class="hc-select"><option value="">{{ __('papers.all_cities') }}</option>
                    @foreach($cities as $c)<option value="{{ $c->code }}" @selected(($filters['city'] ?? '') === $c->code)>{{ $en ? $c->name_en : $c->name_ar }}</option>@endforeach</select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="variant">{{ __('papers.variant') }}</label>
                <select id="variant" name="variant" class="hc-select"><option value="">{{ __('papers.any') }}</option>
                    @foreach(__('papers.variants') as $v => $label)<option value="{{ $v }}" @selected(($filters['variant'] ?? '') === $v)>{{ $label }}</option>@endforeach</select>
            </div>
            <div class="hc-field" style="justify-content:flex-end;flex-direction:row;gap:8px;align-items:flex-end">
                <x-hc.button type="submit" variant="secondary" icon="search">{{ __('papers.filter') }}</x-hc.button>
                @if($query !== [])<x-hc.button variant="ghost" :href="route('papers.index', ['locale' => $locale])">{{ __('papers.clear') }}</x-hc.button>@endif
            </div>
        </form>

        <div class="hc-table-wrap">
            @if($papers === [])
                <x-hc.empty :title="__('papers.none')">{{ __('hc.no_results_hint') }}</x-hc.empty>
            @else
            <table class="hc-table stack">
                <thead><tr><th>{{ __('papers.title') }}</th><th>{{ __('papers.download') }}</th></tr></thead>
                @foreach($grouped as $group => $items)
                    <tbody>
                    <tr><th colspan="2" scope="rowgroup" style="text-align:start;background:var(--canvas)">{{ __('papers.groups.'.$group) }}</th></tr>
                    @foreach($items as $paper)
                        <tr>
                            <td data-label="{{ __('papers.title') }}" style="min-width:14rem">
                                <span class="paper-id">{{ $paper['id'] }}</span> <strong>{{ $paper['title'] }}</strong><br>
                                <small class="muted">{{ $paper['fillable'] ? __('papers.fillable') : __('papers.static') }} · {{ trans_choice('papers.files_count', count($paper['files']), ['count' => count($paper['files'])]) }}</small>
                                @if(isset(\App\Services\Papers\PaperLibrary::REDRAWN[$paper['id']]))<br><a class="row-link" href="{{ route('papers.review', ['locale' => $locale, 'form' => $paper['id']]) }}"><small>{{ __('papers.review_link') }}</small></a>@endif
                            </td>
                            <td data-label="{{ __('papers.download') }}">
                                <div class="paper-files">
                                    @foreach($paper['files'] as $file)
                                        @php($label = __('papers.cities.'.($file['place'] ?? $file['city'])).($file['variant'] !== 'syrian' ? ' · '.__('papers.variants.'.$file['variant']) : ''))
                                        <a class="paper-file" @if($file['installed']) href="{{ route('papers.download', ['locale' => $locale, 'form' => $paper['id'], 'city' => $file['city'], 'place' => $file['place'], 'variant' => $file['variant'], 'format' => $file['format']]) }}" download @else aria-disabled="true" title="{{ __('papers.missing_file') }}" @endif>
                                            <x-hc.icon name="file" :size="16"/><span>{{ $label }}</span><span class="fmt">{{ $file['format'] === 'pdf' ? 'PDF' : 'Word' }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                @endforeach
            </table>
            @endif
        </div>
    </x-hc.card>
</x-layouts.hc>
