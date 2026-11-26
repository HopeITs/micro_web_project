@php
    $en = app()->getLocale() === 'en';
    $many = $shown->count() > 1;
    $axisLabel = __('statistics.axes.'.$result['axis']);
    $byLabel = $result['by'] === null ? null : ($result['by'] === 'city' ? __('statistics.by_city') : __('statistics.axes.'.$result['by']));
    $heading = $byLabel === null ? $axisLabel : __('statistics.crossed_title', ['axis' => $axisLabel, 'by' => $byLabel]);
    $measure = $chartData['measure'];
    $max = max([1, ...array_column($result['rows'], 'share')]);
    $outside = $tiles['all']['count'] - $result['total'];
    $sorted = ['label' => $sortRows === 'name' ? 'ascending' : 'none', 'value' => $sortRows === 'desc' ? 'descending' : ($sortRows === 'asc' ? 'ascending' : 'none')];
@endphp
<div class="ins">
    {{-- Shown on paper only: what the printed numbers answer. --}}
    <div class="ins-print-head">
        <h2>{{ $heading }}</h2>
        <p>{{ __('search.cities') }}: {{ $shown->map(fn ($c) => $en ? $c->name_en : $c->name_ar)->implode(__('search.ui.separator')) }} · <span class="ltr">{{ now(\App\Services\Staff\AuditLogService::TIMEZONE)->format('Y-m-d H:i') }}</span></p>
        @foreach($sentences as $sentence)<p><strong>{{ $sentence['label'] }}:</strong> {{ $sentence['summary'] }}</p>@endforeach
    </div>

    <div class="ins-reports ins-noprint" role="group" aria-label="{{ __('statistics.reports_title') }}">
        <span class="ins-bar-label">{{ __('statistics.reports_title') }}</span>
        @foreach($reports as $report)
            <button type="button" class="ins-report" wire:key="report-{{ $report }}" wire:click="applyReport('{{ $report }}')">{{ __('statistics.reports.'.$report) }}</button>
        @endforeach
    </div>

    <section class="hc-card ins-filters ins-noprint" aria-label="{{ __('search.filters') }}">
        <x-hc.report-views :views="$views"/>
        <x-hc.report-filters :bar="$bar" :cities="$cityOptions" :chosen="$this->cities"/>
    </section>

    {{-- Headline numbers under the same filters; a tile narrows the page to its projects. --}}
    <div class="ins-kpis" role="group" aria-label="{{ __('statistics.headline') }}">
        <div class="ins-kpi ins-kpi-main">
            <span class="ins-kpi-label">{{ __('statistics.series.all') }}</span>
            <strong class="num">{{ number_format($tiles['all']['count']) }}</strong>
            <small>{{ $bar['count'] > 0 || $this->cities !== [] ? __('statistics.under_filters') : __('statistics.no_filters') }}</small>
        </div>
        @foreach(['funded', 'ongoing', 'closed'] as $series)
            <button type="button" class="ins-kpi" wire:key="kpi-{{ $series }}" wire:click="filterSeries('{{ $series }}')" aria-pressed="{{ $tiles[$series]['active'] ? 'true' : 'false' }}"
                    @disabled($tiles[$series]['count'] === 0 && ! $tiles[$series]['active']) title="{{ $tiles[$series]['active'] ? __('statistics.kpi_unfilter') : __('statistics.kpi_filter') }}">
                <span class="ins-kpi-label">{{ __('statistics.series.'.$series) }}</span>
                <strong class="num">{{ number_format($tiles[$series]['count']) }}</strong>
                <small><span class="num ltr">{{ number_format($tiles[$series]['share'], 1) }}%</span> {{ __('statistics.of_all') }}</small>
                <span class="ins-meter" aria-hidden="true"><span style="inline-size: {{ min(100, $tiles[$series]['share']) }}%"></span></span>
            </button>
        @endforeach
    </div>

    <section class="hc-card ins-panel" aria-label="{{ $heading }}">
        <div class="ins-controls ins-noprint">
            <label class="ins-control ins-control-wide">
                <span>{{ __('statistics.axis') }}</span>
                <select id="st-axis" class="hc-select" wire:model.live="axis">
                    @foreach(\App\Services\Reports\StatisticsService::AXES as $key => $n)<option value="{{ $key }}">{{ __('statistics.axes.'.$key) }}</option>@endforeach
                </select>
            </label>
            <label class="ins-control ins-control-wide">
                <span>{{ __('statistics.by') }}</span>
                <select class="hc-select" wire:model.live="by">
                    <option value="">{{ __('statistics.by_none') }}</option>
                    @if($cityOptions->count() > 1)<option value="city">{{ __('statistics.by_city') }}</option>@endif
                    @foreach(\App\Services\Reports\StatisticsService::AXES as $key => $n)@if($key !== $result['axis'])<option value="{{ $key }}">{{ __('statistics.axes.'.$key) }}</option>@endif @endforeach
                </select>
            </label>
            @if($result['series'] === ['all', 'funded'])
                <label class="ins-control">
                    <span>{{ __('statistics.show') }}</span>
                    <select class="hc-select" wire:model.live="show">
                        <option value="both">{{ $result['crossed'] ? __('statistics.series.all') : __('statistics.show_both') }}</option>
                        @unless($result['crossed'])<option value="all">{{ __('statistics.series.all') }}</option>@endunless
                        <option value="funded">{{ __('statistics.series.funded') }}</option>
                    </select>
                </label>
            @endif
            <label class="ins-control">
                <span>{{ __('statistics.sort') }}</span>
                <select class="hc-select" wire:model.live="sortRows">
                    @foreach(\App\Services\Reports\StatisticsService::SORTS as $s)<option value="{{ $s }}">{{ __('statistics.sorts.'.$s) }}</option>@endforeach
                </select>
            </label>
            <label class="ins-control">
                <span>{{ __('statistics.top') }}</span>
                <select class="hc-select" wire:model.live="top">
                    @foreach(\App\Services\Reports\StatisticsService::TOPS as $n)<option value="{{ $n }}">{{ $n === 0 ? __('search.all') : __('statistics.top_n', ['count' => $n]) }}</option>@endforeach
                </select>
            </label>
            <label class="ins-switch"><input type="checkbox" wire:model.live="hideZero"> {{ __('statistics.hide_zero') }}</label>
        </div>

        <header class="ins-panel-head">
            <div>
                <h2>{{ $heading }}</h2>
                <p class="muted" aria-live="polite">{{ __('statistics.measuring', ['series' => $measure, 'count' => number_format($result['total'])]) }}</p>
            </div>
            <div class="ins-actions ins-noprint">
                @if($canExport)
                    <x-hc.button variant="ghost" size="sm" icon="file" wire:click="exportTable('xlsx')">{{ __('search.export_excel') }}</x-hc.button>
                    <x-hc.button variant="ghost" size="sm" icon="file" wire:click="exportTable('csv')">{{ __('search.export_csv') }}</x-hc.button>
                @endif
                <x-hc.button variant="ghost" size="sm" icon="file" data-ins-print>{{ __('search.ui.print') }}</x-hc.button>
            </div>
        </header>

        @if($tiles['all']['count'] === 0)
            <x-hc.empty :title="__('statistics.none')" icon="chart">
                {{ __('search.none_hint') }}
                @if($bar['count'] > 0)
                    <x-slot:action>
                        <x-hc.button variant="secondary" size="sm" wire:click="clearFilters">{{ __('search.clear') }}</x-hc.button>
                    </x-slot:action>
                @endif
            </x-hc.empty>
        @elseif($chartData['labels'] === [])
            <x-hc.empty :title="__('statistics.none_on_axis')" icon="chart">{{ __('statistics.none_on_axis_hint') }}</x-hc.empty>
        @else
            <div class="ins-types ins-seg ins-noprint" role="group" aria-label="{{ __('report_builder.chart_type') }}">
                @foreach($chartData['available'] as $type)
                    <button type="button" wire:key="type-{{ $type }}" wire:click="$set('chart', '{{ $type }}')" aria-pressed="{{ $chartData['chosen'] === $type ? 'true' : 'false' }}">
                        {{ $type === 'hbar' ? __('statistics.chart_hbar') : ($result['crossed'] && $type === 'bar' ? __('statistics.chart_grouped') : __('report_builder.chart_'.$type)) }}
                    </button>
                @endforeach
            </div>

            {{-- A press on a bar shows what can be done with it. --}}
            @if($pickedRow)
                <div class="ins-picked ins-noprint" role="status" wire:key="picked-{{ $pickedRow['key'] }}">
                    <span><strong>{{ $pickedRow['label'] }}</strong> <span class="num ltr">{{ number_format($pickedRow['value']) }}</span> <span class="muted">(<span class="num ltr">{{ number_format($pickedRow['share'], 1) }}%</span>)</span></span>
                    <span class="ins-picked-actions">
                        @if(! $pickedRow['other'] && $canFilterRow($pickedRow['key']))
                            <x-hc.button variant="secondary" size="sm" icon="plus" wire:click="filterBy('{{ $pickedRow['key'] }}')">{{ __('statistics.filter_by_this') }}</x-hc.button>
                        @endif
                        @php($pickedUrl = $pickedRow['urls'][$result['measure'][0]] ?? null)
                        @if($pickedUrl && count($result['measure']) === 1)<x-hc.button variant="secondary" size="sm" icon="search" :href="$pickedUrl">{{ __('statistics.open_in_search') }}</x-hc.button>@endif
                        <button type="button" class="ins-pop-close" wire:click="pick('{{ $pickedRow['key'] }}')" aria-label="{{ __('search.ui.close') }}"><x-hc.icon name="x" :size="16"/></button>
                    </span>
                </div>
            @endif

            <div class="ins-chart" wire:loading.class="is-loading" x-data x-on:hc-chart-pick="$wire.pick($event.detail.key)">
                <x-hc.chart :type="$chartData['type']" :labels="$chartData['labels']" :datasets="$chartData['datasets']" :height="$chartData['height']" :title="$heading.' - '.$measure"
                    :data-keys="json_encode($chartData['keys'])" :data-height="$chartData['height']" :data-horizontal="$chartData['horizontal'] ? '1' : '0'"
                    :data-other="$chartData['other'] ? 'slice' : ($chartData['othersLast'] ? 'series' : '')"/>
            </div>
            <p class="ins-hint ins-noprint">{{ __('statistics.press_bar') }}</p>
        @endif
    </section>

    @if($tiles['all']['count'] > 0)
        <section class="hc-card" aria-label="{{ __('statistics.table') }}">
            <div class="hc-table-wrap" role="region" tabindex="0" aria-label="{{ __('statistics.table') }}" wire:loading.class="opacity-60">
                <table class="hc-table ins-table" data-smart="off">
                    <caption class="sr-only">{{ $heading }}</caption>
                    <thead><tr>
                        <th scope="col" aria-sort="{{ $sorted['label'] }}"><button type="button" @class(['hc-sort', 'is-sorted' => $sortRows === 'name']) wire:click="sortBy('label')">{{ $axisLabel }}<span class="hc-sort-ind" aria-hidden="true">{{ $sortRows === 'name' ? '▲' : '↕' }}</span></button></th>
                        @if($result['crossed'])
                            @foreach($result['columns'] as $label)<th scope="col" class="num">{{ $label }}</th>@endforeach
                            <th scope="col" class="num" aria-sort="{{ $sorted['value'] }}"><button type="button" @class(['hc-sort', 'is-sorted' => in_array($sortRows, ['asc', 'desc'], true)]) wire:click="sortBy('value')">{{ __('statistics.total') }}<span class="hc-sort-ind" aria-hidden="true">{{ $sortRows === 'desc' ? '▼' : ($sortRows === 'asc' ? '▲' : '↕') }}</span></button></th>
                        @else
                            @foreach($visible as $i => $s)
                                @if($i === 0)
                                    <th scope="col" class="num" aria-sort="{{ $sorted['value'] }}"><button type="button" @class(['hc-sort', 'is-sorted' => in_array($sortRows, ['asc', 'desc'], true)]) wire:click="sortBy('value')">{{ __('statistics.series.'.$s) }}<span class="hc-sort-ind" aria-hidden="true">{{ $sortRows === 'desc' ? '▼' : ($sortRows === 'asc' ? '▲' : '↕') }}</span></button></th>
                                @else
                                    <th scope="col" class="num">{{ __('statistics.series.'.$s) }}</th>
                                @endif
                            @endforeach
                        @endif
                        <th scope="col">{{ __('statistics.share') }}</th>
                        @if($many && ! $result['crossed'])@foreach($shown as $c)<th scope="col" class="num" title="{{ __('statistics.city_counts', ['series' => $measure]) }}">{{ $en ? $c->name_en : $c->name_ar }}</th>@endforeach @endif
                    </tr></thead>
                    <tbody>
                    @foreach($result['rows'] as $row)
                        <tr wire:key="stat-{{ $result['axis'] }}-{{ $result['by'] }}-{{ $row['key'] }}" @class(['is-picked' => $pickedRow && $pickedRow['key'] === $row['key'], 'is-others' => $row['other']])>
                            <th scope="row">
                                <span class="ins-row-label">
                                    <span>{{ $row['label'] }}</span>
                                    @if(! $row['other'] && $row['value'] > 0 && $canFilterRow($row['key']))
                                        <button type="button" class="ins-row-filter ins-noprint" wire:click="filterBy('{{ $row['key'] }}')" aria-label="{{ __('statistics.filter_by', ['value' => $row['label']]) }}" title="{{ __('statistics.filter_by', ['value' => $row['label']]) }}"><x-hc.icon name="plus" :size="15"/></button>
                                    @endif
                                </span>
                            </th>
                            @if($result['crossed'])
                                @foreach($result['columns'] as $column => $label)
                                    @php($n = $row['values'][$column] ?? 0)
                                    <td @class(['num ltr', 'ins-zero' => $n === 0])>@if($row['cell_urls'][$column] ?? null)<a class="row-link" href="{{ $row['cell_urls'][$column] }}">{{ number_format($n) }}</a>@else{{ number_format($n) }}@endif</td>
                                @endforeach
                                <td class="num ltr"><strong>{{ number_format($row['value']) }}</strong></td>
                            @else
                                @foreach($visible as $s)
                                    @php($n = $row['counts'][$s] ?? 0)
                                    <td @class(['num ltr', 'ins-zero' => $n === 0])>@if($row['urls'][$s] ?? null)<a class="row-link" href="{{ $row['urls'][$s] }}">{{ number_format($n) }}</a>@else{{ number_format($n) }}@endif</td>
                                @endforeach
                            @endif
                            <td class="ins-share"><span class="num ltr">{{ number_format($row['share'], 1) }}%</span><span class="ins-meter" aria-hidden="true"><span style="inline-size: {{ round($row['share'] / $max * 100, 1) }}%"></span></span></td>
                            @if($many && ! $result['crossed'])@foreach($shown as $c)<td class="num ltr">{{ number_format($row['per_city'][(int) $c->id] ?? 0) }}</td>@endforeach @endif
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr>
                        <th scope="row">{{ __('statistics.total') }}</th>
                        @if($result['crossed'])
                            @foreach($result['columns'] as $column => $label)<td class="num ltr"><strong>{{ number_format($result['column_totals'][$column]) }}</strong></td>@endforeach
                            <td class="num ltr"><strong>{{ number_format($result['total']) }}</strong></td>
                        @else
                            @foreach($visible as $s)<td class="num ltr"><strong>{{ number_format($result['totals'][$s]) }}</strong></td>@endforeach
                        @endif
                        <td class="num ltr">{{ $result['total'] > 0 ? '100%' : '0%' }}</td>
                        @if($many && ! $result['crossed'])@foreach($shown as $c)<td class="num ltr"><strong>{{ number_format($result['city_totals'][(int) $c->id] ?? 0) }}</strong></td>@endforeach @endif
                    </tr></tfoot>
                </table>
            </div>
            <div class="ins-notes">
                @if(in_array($result['axis'], ['fund_year', 'fund_amount', 'sustainability', 'donor'], true) && $outside > 0 && $result['axis'] !== 'donor')
                    <p><x-hc.icon name="info" :size="16"/>{{ __('statistics.outside_'.($result['axis'] === 'sustainability' ? 'sustainability' : 'loan'), ['count' => number_format($outside)]) }}</p>
                @endif
                @if($many && ! $result['crossed'])<p>{{ __('statistics.city_counts', ['series' => $measure]) }}</p>@endif
                <p>{{ __('statistics.note') }}</p>
            </div>
        </section>
    @endif
</div>
