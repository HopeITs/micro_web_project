@php
    $en = app()->getLocale() === 'en';
    $kindKey = $searchMode->key();
    $count = __($searchMode->hasProject() ? 'search.count_projects' : 'search.count_rows', ['count' => number_format($total)]);
    $headingOf = array_combine($columnKeys, $headings);
    $shownCities = $this->cities === [] ? $cityOptions : $cityOptions->filter(fn ($c) => in_array((int) $c->id, $this->cities, true));
@endphp
<div class="ins">
    {{-- Shown on paper only: what the printed rows answer (never the search text). --}}
    <div class="ins-print-head">
        <h2>{{ __('search.modes.'.$kindKey) }}</h2>
        <p>{{ __('search.cities') }}: {{ $shownCities->map(fn ($c) => $en ? $c->name_en : $c->name_ar)->implode(__('search.ui.separator')) }} · {{ $count }} · <span class="ltr">{{ now(\App\Services\Staff\AuditLogService::TIMEZONE)->format('Y-m-d H:i') }}</span></p>
        @foreach($sentences as $sentence)<p><strong>{{ $sentence['label'] }}:</strong> {{ $sentence['summary'] }}</p>@endforeach
        @if($rows->lastPage() > 1)<p>{{ __('search.ui.printed_page', ['page' => $rows->currentPage(), 'pages' => $rows->lastPage()]) }}</p>@endif
    </div>

    <section class="hc-card ins-search ins-noprint" aria-label="{{ __('search.title') }}">
        {{-- The 22 types, grouped, found by typing. --}}
        <div class="ins-mode" x-data="insPicker" @keydown.escape.stop="close()" @click.outside="close(false)" wire:key="mode-picker">
            <button type="button" class="ins-mode-trigger" x-ref="trigger" @click="toggle()" aria-haspopup="dialog" :aria-expanded="open ? 'true' : 'false'">
                <span><small>{{ __('search.mode') }}</small><strong>{{ __('search.modes.'.$kindKey) }}</strong></span>
                <x-hc.icon name="chevron" :size="20"/>
            </button>
            <div class="ins-backdrop" x-show="open" x-cloak @click="close()" aria-hidden="true"></div>
            <div class="ins-picker" x-show="open" x-cloak x-transition.opacity.duration.120ms role="dialog" aria-label="{{ __('search.mode') }}">
                <label class="ins-find">
                    <x-hc.icon name="search" :size="16"/>
                    <span class="sr-only">{{ __('search.ui.mode_find') }}</span>
                    <input type="search" x-ref="find" x-model="q" placeholder="{{ __('search.ui.mode_find') }}" autocomplete="off" @keydown.down.prevent="move(1)" @keydown.enter.prevent="first()">
                </label>
                <div class="ins-mode-list" x-ref="list" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)">
                    @foreach($groups as $group => $items)
                        <div class="ins-mode-group" data-group role="group" aria-label="{{ __('search.groups.'.$group) }}" wire:key="group-{{ $group }}">
                            <h3>{{ __('search.groups.'.$group) }}</h3>
                            <div class="ins-mode-grid">
                                @foreach($items as $key => $m)
                                    <button type="button" class="ins-mode-card" wire:key="mode-{{ $key }}" data-mode data-find="{{ __('search.modes.'.$key) }} {{ __('search.mode_hint.'.$key) }} {{ __('search.groups.'.$group) }}"
                                            @if($key === $kindKey) aria-current="true" @endif wire:click="setMode('{{ $key }}')" @click="close()">
                                        <strong>{{ __('search.modes.'.$key) }}</strong><small>{{ __('search.mode_hint.'.$key) }}</small>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    <p class="ins-hint" x-show="shown === 0" x-cloak role="status">{{ __('search.ui.no_mode') }}</p>
                </div>
            </div>
        </div>

        <div class="ins-term">
            <label for="sp-term">{{ __('search.term') }}</label>
            <span class="ins-term-box">
                <x-hc.icon name="search" :size="20"/>
                <input id="sp-term" type="search" wire:model.live.debounce.500ms="term" maxlength="100" placeholder="{{ __('search.term_placeholder') }}" autocomplete="off">
                @if($term !== '')<button type="button" wire:click="clearTerm" aria-label="{{ __('search.ui.clear_term') }}" title="{{ __('search.ui.clear_term') }}"><x-hc.icon name="x" :size="16"/></button>@endif
            </span>
            <small>{{ __('search.ui.term_private') }}</small>
        </div>
    </section>

    <section class="hc-card ins-filters ins-noprint" aria-label="{{ __('search.filters') }}">
        <x-hc.report-views :views="$views"/>
        <x-hc.report-filters :bar="$bar" :cities="$cityOptions" :chosen="$this->cities">
            @if($searchMode->hasLastSwitch())
                <label class="ins-switch" wire:key="last-switch"><input type="checkbox" wire:model.live="last"> {{ __('search.last') }}</label>
                @if($last && $bar['count'] === 0)<button type="button" class="ins-clear" wire:key="clear-last" wire:click="clearFilters">{{ __('search.clear') }}</button>@endif
            @endif
        </x-hc.report-filters>
        @if($filterKind === 'none')<p class="ins-hint ins-nofilters">{{ __('search.ui.no_filters') }}</p>@endif
    </section>

    @if($summary !== null && $kindKey === 'applications')
        <div class="ins-kpis ins-kpis-small" aria-label="{{ __('search.summary.applications') }}">
            <div class="ins-kpi"><span class="ins-kpi-label">{{ __('search.summary.projects') }}</span><strong class="num">{{ number_format($summary['projects']) }}</strong></div>
            <div class="ins-kpi"><span class="ins-kpi-label">{{ __('search.summary.members') }}</span><strong class="num">{{ number_format($summary['members']) }}</strong></div>
        </div>
    @elseif($summary !== null && $kindKey === 'loans')
        <section class="hc-card" aria-label="{{ __('search.summary.loans_title') }}">
            <p class="ins-card-title">{{ __('search.summary.loans_title') }}</p>
            <div class="hc-table-wrap" role="region" tabindex="0" aria-label="{{ __('search.summary.loans_title') }}">
                <table class="hc-table" data-smart="off">
                    <thead><tr>
                        <th scope="col">{{ __('search.summary.currency') }}</th>
                        @foreach(['count', 'loans', 'repayments', 'returned', 'net', 'repayment_loans', 'balance'] as $k)<th scope="col" class="num">{{ __('search.summary.'.$k) }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                    @foreach($summary as $currency => $v)
                        <tr wire:key="loan-summary-{{ $currency }}">
                            <th scope="row">{{ __('search.summary.'.$currency) }}</th>
                            <td class="num ltr">{{ number_format($v['count']) }}</td>
                            @foreach(['loans', 'repayments', 'returned', 'net', 'repayment_loans', 'balance'] as $k)<td class="num ltr">{{ number_format((float) $v[$k], 2) }}</td>@endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="hc-card" aria-label="{{ __('search.table') }}">
        <header class="ins-panel-head">
            <div>
                <h2 aria-live="polite">{{ $count }}</h2>
                @if($sortedBy !== '')
                    <p class="muted ins-noprint">{{ __('search.ui.sorted_by', ['column' => $headingOf[$sortedBy], 'way' => __('search.ui.'.($dir === 'desc' ? 'desc' : 'asc'))]) }}
                        <button type="button" class="ins-link" wire:click="$set('sort', '')">{{ __('search.ui.sort_default') }}</button></p>
                @endif
            </div>
            <div class="ins-actions ins-noprint">
                @if($canExport)
                    <x-hc.button variant="ghost" size="sm" icon="file" wire:click="exportTable('xlsx')">{{ __('search.export_excel') }}</x-hc.button>
                    <x-hc.button variant="ghost" size="sm" icon="file" wire:click="exportTable('csv')">{{ __('search.export_csv') }}</x-hc.button>
                    @if($canExportDetails)
                        <x-hc.button variant="ghost" size="sm" icon="file" wire:click="exportDetails('xlsx')" title="{{ __('search.details_hint') }}">{{ __('search.export_details') }}</x-hc.button>
                    @endif
                @endif
                @if($rows->total() > 0)<x-hc.button variant="ghost" size="sm" icon="file" data-ins-print>{{ __('search.ui.print') }}</x-hc.button>@endif
            </div>
        </header>

        @if($rows->total() === 0)
            <x-hc.empty :title="__('search.none')" icon="search">
                {{ __('search.none_hint') }}
                @if($bar['count'] > 0)
                    <x-slot:action>
                        <x-hc.button variant="secondary" size="sm" wire:click="clearFilters">{{ __('search.clear') }}</x-hc.button>
                    </x-slot:action>
                @endif
            </x-hc.empty>
        @else
            {{-- On phones the headers scroll out of reach, so the order is also a list. --}}
            @if($sortable !== [])
                <div class="ins-sort-mobile ins-noprint">
                    <label for="sp-sort">{{ __('search.ui.sort_by') }}</label>
                    <select id="sp-sort" class="hc-select" wire:model.live="sort">
                        <option value="">{{ __('search.ui.sort_default') }}</option>
                        @foreach($sortable as $key)<option value="{{ $key }}">{{ $headingOf[$key] }}</option>@endforeach
                    </select>
                    @if($sortedBy !== '')<button type="button" class="hc-btn hc-btn-secondary hc-btn-sm" wire:click="$set('dir', '{{ $dir === 'desc' ? 'asc' : 'desc' }}')">{{ __('search.ui.'.($dir === 'desc' ? 'desc' : 'asc')) }}</button>@endif
                </div>
            @endif
            {{-- The table scrolls inside its own region, never the page. --}}
            <div class="hc-table-wrap ins-results" role="region" tabindex="0" aria-label="{{ __('search.table') }}" wire:loading.class="opacity-60">
                <table class="hc-table" data-smart="off">
                    <thead><tr>
                        @foreach($headings as $j => $h)
                            @php($key = $columnKeys[$j])
                            @if(in_array($key, $sortable, true))
                                <th scope="col" aria-sort="{{ $sortedBy === $key ? ($dir === 'desc' ? 'descending' : 'ascending') : 'none' }}">
                                    <button type="button" @class(['hc-sort', 'is-sorted' => $sortedBy === $key]) wire:click="sortBy('{{ $key }}')" title="{{ __('search.ui.sort_hint') }}"><span>{{ $h }}</span><span class="hc-sort-ind" aria-hidden="true">{{ $sortedBy === $key ? ($dir === 'desc' ? '▼' : '▲') : '↕' }}</span></button>
                                </th>
                            @else
                                <th scope="col">{{ $h }}</th>
                            @endif
                        @endforeach
                    </tr></thead>
                    <tbody>
                    @foreach($cells as $i => $line)
                        <tr wire:key="sr-{{ $kindKey }}-{{ $rows->currentPage() }}-{{ $i }}">
                            @foreach($line['cells'] as $j => $cell)
                                <td @class(['num ltr' => in_array($j, $numeric, true)]) @style(['white-space:pre-line;min-width:14rem' => is_string($cell) && mb_strlen($cell) > 60])>
                                    @if($j === $linkColumn && $line['url'])
                                        <a class="row-link" href="{{ $line['url'] }}">{{ $cell === '' ? '—' : $cell }}</a>
                                    @else
                                        {{ $cell === '' || $cell === null ? '—' : $cell }}
                                    @endif
                                    @if($line['flag'] && $j === 2)
                                        <x-hc.badge tone="warn" icon="users" title="{{ __('search.duplicate_hint') }}">{{ __('search.duplicate') }}</x-hc.badge>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="ins-hint ins-noprint ins-card-note">{{ __('search.scroll_hint') }}</p>

            @if($rows->lastPage() > 1)
                <div class="hc-pagination ins-noprint">
                    <span>{{ __('hc.page', ['page' => $rows->currentPage(), 'pages' => $rows->lastPage()]) }}</span>
                    <div style="display:flex;gap:8px">
                        @if(! $rows->onFirstPage())<x-hc.button variant="secondary" size="sm" wire:click="previousPage">{{ __('hc.previous') }}</x-hc.button>@endif
                        @if($rows->hasMorePages())<x-hc.button variant="secondary" size="sm" wire:click="nextPage">{{ __('hc.next') }}</x-hc.button>@endif
                    </div>
                </div>
            @endif
        @endif
    </section>
</div>
