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
        .paper-file-wrap { display: inline-flex; align-items: center; gap: 2px; }
        .paper-x { display: inline; margin: 0; }
        .paper-x-btn { min-width: 32px; min-height: 36px; border: 1px solid var(--line); border-radius: var(--radius-sm); background: var(--surface); color: var(--danger, #b42318); cursor: pointer; font-size: 1.125rem; line-height: 1; }
        .paper-x-btn:hover { border-color: var(--danger, #b42318); }
        .paper-manage-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 12px; padding: 16px; }
        .paper-id { font-variant-numeric: tabular-nums; color: var(--muted); font-size: .8125rem; }
    </style>
    <div class="hc-page-head">
        <div><p class="hc-eyebrow">{{ __('hc.group_tools') }}</p><h1>{{ __('papers.title') }}</h1><p>{{ __('papers.intro') }}</p></div>
        @if(! empty($manage))<x-hc.button variant="secondary" icon="settings" :href="route('papers.settings', ['locale' => $locale])">{{ __('papers.settings.open') }}</x-hc.button>@endif
    </div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
    @if($errors->any())<x-hc.alert type="danger" style="margin-block-end:16px">{{ $errors->first() }}</x-hc.alert>@endif
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
                                        @php($params = ['locale' => $locale, 'form' => $paper['id'], 'city' => $file['city'], 'place' => $file['place'] ?? null, 'variant' => $file['variant'], 'format' => $file['format']])
                                        <span class="paper-file-wrap">
                                        <a class="paper-file" @if($file['installed']) href="{{ route('papers.download', $params) }}" download @else aria-disabled="true" title="{{ __('papers.missing_file') }}" @endif>
                                            <x-hc.icon name="file" :size="16"/><span>{{ $label }}</span><span class="fmt">{{ $file['format'] === 'pdf' ? 'PDF' : 'Word' }}</span>
                                            @if(! empty($file['custom']))<span class="fmt" title="{{ __('papers.added_hint') }}">· {{ $file['replaces'] ? __('papers.replaced') : __('papers.added') }}</span>@endif
                                        </a>
                                        @if(in_array($file['city'], $manage, true))
                                            <form method="POST" action="{{ route('papers.remove', ['locale' => $locale]) }}" onsubmit="return confirm(@js(__('papers.confirm_remove')))" class="paper-x">
                                                @csrf @method('DELETE')
                                                <input type="hidden" name="form" value="{{ $paper['id'] }}"><input type="hidden" name="city" value="{{ $file['city'] }}">
                                                @if($file['place'] ?? null)<input type="hidden" name="place" value="{{ $file['place'] }}">@endif
                                                <input type="hidden" name="variant" value="{{ $file['variant'] }}"><input type="hidden" name="format" value="{{ $file['format'] }}">
                                                <button type="submit" class="paper-x-btn" aria-label="{{ __('papers.remove') }}" title="{{ __('papers.remove') }}">&times;</button>
                                            </form>
                                        @endif
                                        </span>
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
    @if($manage !== [])
    <x-hc.card style="margin-block-start:16px">
        <h2 style="margin:0 0 4px">{{ __('papers.manage_title') }}</h2>
        <p class="muted" style="margin:0">{{ __('papers.manage_intro') }}</p>
        <form method="POST" action="{{ route('papers.upload', ['locale' => $locale]) }}" enctype="multipart/form-data">
            @csrf
            <div class="paper-manage-grid">
                <div class="hc-field"><label class="hc-label" for="m-form">{{ __('papers.m_paper') }}</label>
                    <select id="m-form" name="form" class="hc-select" required>
                        <option value="new" @selected(old('form') === 'new')>{{ __('papers.m_new_paper') }}</option>
                        @foreach(collect($forms)->groupBy('group') as $group => $items)
                            <optgroup label="{{ __('papers.groups.'.$group) }}">
                                @foreach($items as $id => $f)<option value="{{ $id }}" @selected(old('form') === $id)>{{ $id }} {{ \App\Services\Papers\PaperLibrary::title($id) }}</option>@endforeach
                            </optgroup>
                        @endforeach
                    </select></div>
                <div class="hc-field"><label class="hc-label" for="m-city">{{ __('papers.city') }}</label>
                    <select id="m-city" name="city" class="hc-select" required>
                        @foreach($cities->whereIn('code', $manage) as $c)<option value="{{ $c->code }}" @selected(old('city') === $c->code)>{{ $en ? $c->name_en : $c->name_ar }}</option>@endforeach
                    </select></div>
                <div class="hc-field"><label class="hc-label" for="m-place">{{ __('papers.m_place') }}</label>
                    <select id="m-place" name="place" class="hc-select"><option value="">—</option><option value="IDL" @selected(old('place') === 'IDL')>{{ __('papers.cities.IDL') }}</option></select></div>
                <div class="hc-field"><label class="hc-label" for="m-variant">{{ __('papers.variant') }}</label>
                    <select id="m-variant" name="variant" class="hc-select" required>@foreach(__('papers.variants') as $v => $label)<option value="{{ $v }}" @selected(old('variant', 'syrian') === $v)>{{ $label }}</option>@endforeach</select></div>
                <div class="hc-field"><label class="hc-label" for="m-file">{{ __('papers.m_file') }}</label>
                    <input id="m-file" name="file" type="file" class="hc-input" accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required></div>
            </div>
            <div class="paper-manage-grid" style="padding-block-start:0">
                <div class="hc-field"><label class="hc-label" for="m-name-ar">{{ __('papers.m_name_ar') }}</label><input id="m-name-ar" name="name_ar" class="hc-input" maxlength="150" value="{{ old('name_ar') }}"></div>
                <div class="hc-field"><label class="hc-label" for="m-name-en">{{ __('papers.m_name_en') }}</label><input id="m-name-en" name="name_en" class="hc-input" maxlength="150" value="{{ old('name_en') }}" dir="ltr"></div>
                <div class="hc-field"><label class="hc-label" for="m-group">{{ __('papers.m_group') }}</label>
                    <select id="m-group" name="group" class="hc-select">@foreach(\App\Services\Papers\PaperLibrary::GROUPS as $g)@if(\Illuminate\Support\Facades\Lang::has('papers.groups.'.$g))<option value="{{ $g }}" @selected(old('group', 'legal') === $g)>{{ __('papers.groups.'.$g) }}</option>@endif @endforeach</select></div>
            </div>
            <p class="muted" style="padding-inline:16px;margin:0">{{ __('papers.m_new_hint') }}</p>
            <div style="padding:16px"><x-hc.button type="submit" icon="plus">{{ __('papers.m_save') }}</x-hc.button></div>
        </form>

        @if($hidden !== [])
            <h3 style="margin:8px 16px 0">{{ __('papers.hidden_title') }}</h3>
            <ul style="padding:8px 16px 16px;margin:0;list-style:none;display:grid;gap:6px">
                @foreach($hidden as $file)
                    <li style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <span class="paper-id">{{ $file['form'] }}</span> {{ \App\Services\Papers\PaperLibrary::title($file['form']) }} · {{ __('papers.cities.'.($file['place'] ?? $file['city'])) }}{{ $file['variant'] !== 'syrian' ? ' · '.__('papers.variants.'.$file['variant']) : '' }} · {{ $file['format'] === 'pdf' ? 'PDF' : 'Word' }}
                        <form method="POST" action="{{ route('papers.restore', ['locale' => $locale]) }}" style="display:inline">
                            @csrf
                            <input type="hidden" name="form" value="{{ $file['form'] }}"><input type="hidden" name="city" value="{{ $file['city'] }}">
                            @if($file['place'] ?? null)<input type="hidden" name="place" value="{{ $file['place'] }}">@endif
                            <input type="hidden" name="variant" value="{{ $file['variant'] }}"><input type="hidden" name="format" value="{{ $file['format'] }}">
                            <x-hc.button type="submit" variant="secondary">{{ __('papers.restore') }}</x-hc.button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-hc.card>
    @endif
</x-layouts.hc>
