<div>
    @php
        $drill = fn (?string $row = null, ?string $col = null, ?string $currency = null) => $canDrill ? \App\Services\Reports\ReportDrill::url($dataset, $spec, $row, $col, $currency) : null;
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
    <x-hc.card :flush="true" style="margin-block-end:16px">
        <div class="hc-toolbar" role="group" aria-label="{{ __('report_builder.setup') }}">
            <x-hc.field :label="__('report_builder.dataset')" for="rb-source">
                <select id="rb-source" class="hc-select" wire:model.live="source">
                    @foreach($datasets as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            </x-hc.field>
            <x-hc.field :label="__('report_builder.rows')" for="rb-rows">
                <select id="rb-rows" class="hc-select" wire:model.live="rows">
                    @foreach($groupable as $key => $g)<option value="{{ $key }}">{{ $g['label'] }}</option>@endforeach
                </select>
            </x-hc.field>
            @if($groupable[$rows]['date'] ?? false)
                <x-hc.field :label="__('report_builder.grain')" for="rb-rg">
                    <select id="rb-rg" class="hc-select" wire:model.live="rowGrain">
                        <option value="year">{{ __('report_builder.grain_year') }}</option><option value="month">{{ __('report_builder.grain_month') }}</option>
                    </select>
                </x-hc.field>
            @endif
            <x-hc.field :label="__('report_builder.columns')" for="rb-cols">
                <select id="rb-cols" class="hc-select" wire:model.live="columns">
                    <option value="">{{ __('report_builder.no_columns') }}</option>
                    @foreach($groupable as $key => $g)@if($key !== $rows)<option value="{{ $key }}">{{ $g['label'] }}</option>@endif @endforeach
                </select>
            </x-hc.field>
            @if($columns !== '' && ($groupable[$columns]['date'] ?? false))
                <x-hc.field :label="__('report_builder.grain')" for="rb-cg">
                    <select id="rb-cg" class="hc-select" wire:model.live="columnGrain">
                        <option value="year">{{ __('report_builder.grain_year') }}</option><option value="month">{{ __('report_builder.grain_month') }}</option>
                    </select>
                </x-hc.field>
            @endif
            <x-hc.field :label="__('report_builder.measure')" for="rb-measure">
                <select id="rb-measure" class="hc-select" wire:model.live="measure">
                    @foreach($measureTypes as $type)<option value="{{ $type }}">{{ __('report_builder.measure_'.$type) }}</option>@endforeach
                </select>
            </x-hc.field>
            @if(in_array($measure, \App\Support\Datasets\ReportSpec::FIELD_MEASURES, true))
                <x-hc.field :label="__('report_builder.measure_field')" for="rb-mf">
                    <select id="rb-mf" class="hc-select" wire:model.live="measureField">
                        @foreach($measurable as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                </x-hc.field>
            @endif
        </div>
        <x-hc.filter-bar :bar="$filterBar"/>
    </x-hc.card>

    @if($error)
        <x-hc.alert type="warning" style="margin-block-end:16px">{{ $error }}</x-hc.alert>
    @endif

    @if($result === null || $result->isEmpty())
        <x-hc.empty :title="__('report_builder.no_data')" icon="chart">{{ __('report_builder.no_data_hint') }}</x-hc.empty>
    @else
        <div class="hc-toolbar" style="padding-inline:0;border:0;align-items:center">
            <div class="hc-chips" role="group" aria-label="{{ __('report_builder.chart_type') }}">
                @foreach(\App\Livewire\ReportBuilder::CHARTS as $type)
                    <button type="button" class="hc-chip" wire:click="$set('chart', '{{ $type }}')" aria-pressed="{{ $chartType === $type ? 'true' : 'false' }}">{{ __('report_builder.chart_'.$type) }}</button>
                @endforeach
            </div>
            @if($canExport)
                <div class="hc-export" role="group" aria-label="{{ __('hc.export') }}" style="margin-inline-start:auto">
                    <x-hc.button variant="secondary" size="sm" wire:click="exportReport('xlsx')">{{ __('hc.export_xlsx') }}</x-hc.button>
                    <x-hc.button variant="secondary" size="sm" wire:click="exportReport('csv')">{{ __('hc.export_csv') }}</x-hc.button>
                </div>
            @endif
        </div>

        @if($result->byCurrency())
            <x-hc.alert type="info" style="margin-block-end:16px">{{ __('report_builder.currency_note') }}</x-hc.alert>
        @endif
        @if(! $canDrill)
            <p class="hc-hint" style="margin-block-end:12px">{{ __('report_builder.no_links') }}</p>
        @endif

        @foreach($pivots as $i => $pivot)
            <x-hc.card :title="$pivot['currency'] !== null ? __('report_builder.in_currency', ['currency' => $pivot['currency']]) : $result->measureLabel()" :subtitle="$pivot['currency'] !== null ? $result->measureLabel() : null" wire:key="slice-{{ $i }}" style="margin-block-end:16px">
                <div style="margin-block-end:16px">
                    <x-hc.chart :type="$chartType" :labels="$charts[$i]['labels']" :datasets="$charts[$i]['datasets']" :title="$result->measureLabel().($pivot['currency'] !== null ? ' - '.$pivot['currency'] : '')" :percent="$result->isPercent()"/>
                </div>
                <div class="hc-table-wrap">
                    {{-- A cut pivot is not searched or re-ordered in the browser: only the shown rows would be covered. --}}
                    <table class="hc-table" data-smart="{{ $pivot['truncated'] ? 'off' : 'auto' }}">
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
                                    <th scope="row">{{ $row['label'] }}</th>
                                    @if($result->hasColumns)
                                        @foreach($pivot['cols'] as $col)
                                            @php($v = $row['cells'][$col['key']] ?? null)
                                            <td class="num">@if($v !== null && ($url = $drill($row['key'], $col['key'], $pivot['currency'])))<a class="row-link" href="{{ $url }}">{{ $format($v) }}</a>@else{{ $format($v) }}@endif</td>
                                        @endforeach
                                    @endif
                                    <td class="num"><strong>@if(($url = $drill($row['key'], null, $pivot['currency'])))<a class="row-link" href="{{ $url }}">{{ $format($row['total']) }}</a>@else{{ $format($row['total']) }}@endif</strong></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row">{{ __('report_builder.total') }}</th>
                                @if($result->hasColumns)
                                    @foreach($pivot['cols'] as $col)
                                        <td class="num"><strong>@if(($url = $drill(null, $col['key'], $pivot['currency'])))<a class="row-link" href="{{ $url }}">{{ $format($pivot['colTotals'][$col['key']] ?? null) }}</a>@else{{ $format($pivot['colTotals'][$col['key']] ?? null) }}@endif</strong></td>
                                    @endforeach
                                @endif
                                <td class="num"><strong>@if(($url = $drill(null, null, $pivot['currency'])))<a class="row-link" href="{{ $url }}">{{ $format($pivot['grand']) }}</a>@else{{ $format($pivot['grand']) }}@endif</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @if($pivot['truncated'])
                    <p class="hc-hint" style="margin-block-start:8px">{{ __('report_builder.truncated', ['rows' => \App\Services\Reports\ReportQuery::MAX_ROWS, 'cols' => \App\Services\Reports\ReportQuery::MAX_COLUMNS]) }}</p>
                @endif
            </x-hc.card>
        @endforeach
    @endif
</div>
