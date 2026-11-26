<div>
    @if (class_exists(\App\Livewire\AskBox::class))
        <div style="margin-block-end:16px" wire:key="ask-{{ $source }}">
            <livewire:ask-box :dataset="$source" mode="report" />
        </div>
    @endif

    {{-- Dataset and the ready questions: one click opens a ready filter + view. --}}
    <x-hc.card :flush="true" style="margin-block-end:16px">
        <div class="hc-toolbar" role="group" aria-label="{{ __('explore.dataset') }}">
            <x-hc.field :label="__('explore.dataset')" for="ex-source">
                <select id="ex-source" class="hc-select" wire:model.live="source">
                    @foreach($datasets as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            </x-hc.field>
        </div>
        <div class="hc-toolbar" style="align-items:center">
            <span class="hc-label">{{ __('explore.questions') }}</span>
            <div class="hc-chips" role="group" aria-label="{{ __('explore.questions') }}">
                @foreach($questions as $q)
                    <button type="button" class="hc-chip" wire:key="q-{{ $source }}-{{ $q['key'] }}" wire:click="openQuestion('{{ $q['key'] }}')">{{ __('explore.q.'.$source.'.'.$q['key']) }}</button>
                @endforeach
            </div>
        </div>
    </x-hc.card>

    {{-- KPI tiles: the count, and the money totals per currency (never added across currencies). --}}
    <div class="hc-counts" role="group" aria-label="{{ __('explore.kpis') }}" style="margin-block-end:16px">
        <x-hc.count-tile :count="$kpis['count']" :label="__('explore.kpi_count')" tone="info" style="cursor:default"/>
        @foreach($kpis['money'] as $m)
            <x-hc.count-tile :count="number_format($m['total'], 2)" :label="$m['label'].' · '.$m['currency']" style="cursor:default"/>
        @endforeach
    </div>

    <x-hc.card :flush="true" style="margin-block-end:16px">
        <x-hc.filter-bar :bar="$filterBar"/>
        <div class="hc-toolbar" style="align-items:center">
            <div class="hc-chips" role="group" aria-label="{{ __('explore.views') }}">
                @foreach(['list', 'pivot', 'chart'] as $v)
                    <button type="button" class="hc-chip" wire:click="setShow('{{ $v }}')" aria-pressed="{{ $show === $v ? 'true' : 'false' }}">{{ __('explore.view_'.$v) }}</button>
                @endforeach
            </div>
            @if($filterBar['chips'] !== [])
                <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearAll" style="margin-inline-start:auto">{{ __('hc.clear') }}</x-hc.button>
            @endif
        </div>

        @if($show !== 'list')
            <div class="hc-toolbar" role="group" aria-label="{{ __('report_builder.setup') }}">
                <x-hc.field :label="__('report_builder.rows')" for="ex-rows">
                    <select id="ex-rows" class="hc-select" wire:model.live="rows">
                        @foreach($groupable as $key => $g)<option value="{{ $key }}">{{ $g['label'] }}</option>@endforeach
                    </select>
                </x-hc.field>
                @if($groupable[$rows]['date'] ?? false)
                    <x-hc.field :label="__('report_builder.grain')" for="ex-rg">
                        <select id="ex-rg" class="hc-select" wire:model.live="rowGrain">
                            <option value="year">{{ __('report_builder.grain_year') }}</option><option value="month">{{ __('report_builder.grain_month') }}</option>
                        </select>
                    </x-hc.field>
                @endif
                <x-hc.field :label="__('report_builder.columns')" for="ex-cols">
                    <select id="ex-cols" class="hc-select" wire:model.live="columns">
                        <option value="">{{ __('report_builder.no_columns') }}</option>
                        @foreach($groupable as $key => $g)@if($key !== $rows)<option value="{{ $key }}">{{ $g['label'] }}</option>@endif @endforeach
                    </select>
                </x-hc.field>
                @if($columns !== '' && ($groupable[$columns]['date'] ?? false))
                    <x-hc.field :label="__('report_builder.grain')" for="ex-cg">
                        <select id="ex-cg" class="hc-select" wire:model.live="columnGrain">
                            <option value="year">{{ __('report_builder.grain_year') }}</option><option value="month">{{ __('report_builder.grain_month') }}</option>
                        </select>
                    </x-hc.field>
                @endif
                <x-hc.field :label="__('report_builder.measure')" for="ex-measure">
                    <select id="ex-measure" class="hc-select" wire:model.live="measure">
                        @foreach($measureTypes as $type)<option value="{{ $type }}">{{ __('report_builder.measure_'.$type) }}</option>@endforeach
                    </select>
                </x-hc.field>
                @if(in_array($measure, \App\Support\Datasets\ReportSpec::FIELD_MEASURES, true))
                    <x-hc.field :label="__('report_builder.measure_field')" for="ex-mf">
                        <select id="ex-mf" class="hc-select" wire:model.live="measureField">
                            @foreach($measurable as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </x-hc.field>
                @endif
            </div>
        @endif
    </x-hc.card>

    @if($error)
        <x-hc.alert type="warning" style="margin-block-end:16px">{{ $error }}</x-hc.alert>
    @endif

    @if($show === 'list')
        <x-hc.card :flush="true">
            <div class="hc-toolbar" style="align-items:center">
                <x-hc.column-picker :picker="$columnPicker"/>
                <span class="muted" style="margin-inline-start:auto" aria-live="polite">{{ __('hc.results', ['count' => number_format($rowsPage->total())]) }}</span>
            </div>
            <div class="hc-table-wrap" wire:loading.class="opacity-60">
                @if($rowsPage->isEmpty())
                    <x-hc.empty :title="__('hc.no_results')">{{ __('hc.no_results_hint') }}</x-hc.empty>
                @else
                    <x-hc.data-table :rows="$rowsPage" :table="$table"/>
                @endif
            </div>
            @if($rowsPage->total() > $table['per_page_choices'][0] || $rowsPage->lastPage() > 1)
                <div class="hc-pagination">
                    <span>{{ __('hc.page', ['page' => $rowsPage->currentPage(), 'pages' => $rowsPage->lastPage()]) }}</span>
                    <label class="hc-per-page"><span>{{ __('hc.per_page') }}</span>
                        <select class="hc-select" wire:model.live="perPage">
                            @foreach($table['per_page_choices'] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                        </select>
                    </label>
                    @if($rowsPage->lastPage() > 1)
                        <nav aria-label="pagination">
                            <x-hc.button variant="secondary" size="sm" wire:click="previousPage" :disabled="$rowsPage->onFirstPage()">{{ __('hc.previous') }}</x-hc.button>
                            <x-hc.button variant="secondary" size="sm" wire:click="nextPage" :disabled="! $rowsPage->hasMorePages()">{{ __('hc.next') }}</x-hc.button>
                        </nav>
                    @endif
                </div>
            @endif
        </x-hc.card>
    @else
        @php
            $format = function (?float $v) use ($result) {
                if ($v === null) {
                    return '—';
                }
                if ($result->isPercent()) {
                    return number_format($v, 1).'%';
                }

                return number_format($v, $result->isMoney() || fmod($v, 1.0) !== 0.0 ? 2 : 0);
            };
        @endphp
        @if($result === null || $result->isEmpty())
            <x-hc.empty :title="__('report_builder.no_data')" icon="chart">{{ __('report_builder.no_data_hint') }}</x-hc.empty>
        @else
            <div class="hc-toolbar" style="padding-inline:0;border:0;align-items:center">
                @if($show === 'chart')
                    <div class="hc-chips" role="group" aria-label="{{ __('report_builder.chart_type') }}">
                        @foreach(['bar', 'line', 'pie'] as $type)
                            <button type="button" class="hc-chip" wire:click="setChart('{{ $type }}')" aria-pressed="{{ $chart === $type ? 'true' : 'false' }}">{{ __('report_builder.chart_'.$type) }}</button>
                        @endforeach
                    </div>
                @endif
                @if($canExportReport)
                    <div class="hc-export" role="group" aria-label="{{ __('hc.export') }}" style="margin-inline-start:auto">
                        <x-hc.button variant="secondary" size="sm" wire:click="exportReport('xlsx')">{{ __('hc.export_xlsx') }}</x-hc.button>
                        <x-hc.button variant="secondary" size="sm" wire:click="exportReport('csv')">{{ __('hc.export_csv') }}</x-hc.button>
                    </div>
                @endif
            </div>
            @if($result->byCurrency())
                <x-hc.alert type="info" style="margin-block-end:16px">{{ __('report_builder.currency_note') }}</x-hc.alert>
            @endif
            @if($show === 'pivot')
                <p class="hc-hint" style="margin-block-end:12px">{{ __('explore.drill_hint') }}</p>
            @endif

            @foreach($pivots as $i => $pivot)
                @php($cur = $pivot['currency'] !== null ? json_encode($pivot['currency']) : 'null')
                <x-hc.card :title="$pivot['currency'] !== null ? __('report_builder.in_currency', ['currency' => $pivot['currency']]) : $result->measureLabel()" :subtitle="$pivot['currency'] !== null ? $result->measureLabel() : null" wire:key="slice-{{ $show }}-{{ $i }}" style="margin-block-end:16px">
                    @if($show === 'chart')
                        <x-hc.chart :type="$chart" :labels="$charts[$i]['labels']" :datasets="$charts[$i]['datasets']" :title="$result->measureLabel().($pivot['currency'] !== null ? ' - '.$pivot['currency'] : '')" :percent="$result->isPercent()"/>
                    @else
                        <div class="hc-table-wrap">
                            <table class="hc-table stack" data-smart="off">
                                <caption class="sr-only">{{ $result->measureLabel() }}{{ $pivot['currency'] !== null ? ' - '.$pivot['currency'] : '' }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ $dataset->field($spec->rows)->label() }}</th>
                                        @if($result->hasColumns)
                                            @foreach($pivot['cols'] as $col)<th scope="col" class="num">{{ $col['label'] }}</th>@endforeach
                                            <th scope="col" class="num">{{ __('report_builder.total') }}</th>
                                        @else
                                            <th scope="col" class="num">{{ $result->measureLabel() }}</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($pivot['rows'] as $row)
                                        <tr wire:key="row-{{ $i }}-{{ $row['key'] }}">
                                            <th scope="row" data-label="{{ $dataset->field($spec->rows)->label() }}">{{ $row['label'] }}</th>
                                            @if($result->hasColumns)
                                                @foreach($pivot['cols'] as $col)
                                                    @php($v = $row['cells'][$col['key']] ?? null)
                                                    <td class="num" data-label="{{ $col['label'] }}">@if($v !== null)<button type="button" class="row-link" wire:click='drill({{ json_encode((string) $row['key']) }}, {{ json_encode((string) $col['key']) }}, {{ $cur }})'>{{ $format($v) }}</button>@else{{ $format($v) }}@endif</td>
                                                @endforeach
                                            @endif
                                            <td class="num" data-label="{{ $result->hasColumns ? __('report_builder.total') : $result->measureLabel() }}"><strong><button type="button" class="row-link" wire:click='drill({{ json_encode((string) $row['key']) }}, null, {{ $cur }})'>{{ $format($row['total']) }}</button></strong></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th scope="row">{{ __('report_builder.total') }}</th>
                                        @if($result->hasColumns)
                                            @foreach($pivot['cols'] as $col)
                                                <td class="num" data-label="{{ $col['label'] }}"><strong><button type="button" class="row-link" wire:click='drill(null, {{ json_encode((string) $col['key']) }}, {{ $cur }})'>{{ $format($pivot['colTotals'][$col['key']] ?? null) }}</button></strong></td>
                                            @endforeach
                                        @endif
                                        <td class="num" data-label="{{ __('report_builder.total') }}"><strong><button type="button" class="row-link" wire:click='drill(null, null, {{ $cur }})'>{{ $format($pivot['grand']) }}</button></strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        @if($pivot['truncated'])
                            <p class="hc-hint" style="margin-block-start:8px">{{ __('report_builder.truncated', ['rows' => \App\Services\Reports\ReportQuery::MAX_ROWS, 'cols' => \App\Services\Reports\ReportQuery::MAX_COLUMNS]) }}</p>
                        @endif
                    @endif
                </x-hc.card>
            @endforeach
        @endif
    @endif
</div>
