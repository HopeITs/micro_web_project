@php
    $en = app()->getLocale() === 'en';
    $lists = ['fund_type', 'project_type', 'subtype', 'status', 'donor', 'donor_group', 'category', 'subcategory', 'career', 'parish', 'marital', 'home_street', 'project_street', 'age'];
    $dates = ['request_from', 'request_to', 'fund_from', 'fund_to'];
    $amounts = ['amount_from', 'amount_to', 'usd_from', 'usd_to'];
    $many = $shown->count() > 1;
    $first = $visible[0];
    $multiSeries = count($result['series']) > 1;
@endphp
<div>
    @include('partials.filters-style')
    <x-hc.card :flush="true" style="margin-block-end:16px">
        <div class="hc-filters">
            <div class="hc-filters-grid" style="--cols:3">
                <div class="hc-field">
                    <label class="hc-label" for="st-axis">{{ __('statistics.axis') }}</label>
                    <select id="st-axis" class="hc-select" wire:model.live="axis">
                        @foreach(\App\Services\Reports\StatisticsService::AXES as $key => $n)<option value="{{ $key }}">{{ __('statistics.axes.'.$key) }}</option>@endforeach
                    </select>
                </div>
                @if($multiSeries && $result['series'] === ['all', 'funded'])
                    <div class="hc-field">
                        <label class="hc-label" for="st-show">{{ __('statistics.show') }}</label>
                        <select id="st-show" class="hc-select" wire:model.live="show">
                            <option value="both">{{ __('statistics.show_both') }}</option>
                            <option value="all">{{ __('statistics.series.all') }}</option>
                            <option value="funded">{{ __('statistics.series.funded') }}</option>
                        </select>
                    </div>
                @endif
            </div>

            <div x-data="{ open: window.matchMedia('(min-width: 900px)').matches }" style="margin-block-start:12px">
                <button type="button" class="hc-btn hc-btn-ghost hc-btn-sm" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-controls="st-filters">
                    <x-hc.icon name="settings" :size="18"/>{{ $activeFilters > 0 ? __('search.filters_active', ['count' => $activeFilters]) : __('search.filters') }}
                </button>
                <div id="st-filters" x-show="open" x-cloak style="margin-block-start:12px">
                    <div class="hc-filters-grid" style="--cols:4">
                        @foreach($lists as $key)
                            <div class="hc-field">
                                <label class="hc-label" for="st-{{ $key }}">{{ __('search.f.'.$key) }}</label>
                                <select id="st-{{ $key }}" class="hc-select" multiple size="4" wire:model.live="filters.{{ $key }}" aria-describedby="st-multi-hint">
                                    @foreach($options[$key] ?? [] as $o)<option value="{{ $o['id'] }}">{{ $o['label'] }}</option>@endforeach
                                </select>
                            </div>
                        @endforeach
                        <div class="hc-field">
                            <label class="hc-label" for="st-sex">{{ __('search.f.sex') }}</label>
                            <select id="st-sex" class="hc-select" multiple size="2" wire:model.live="filters.sex" aria-describedby="st-multi-hint">
                                @foreach($options['sex'] as $o)<option value="{{ $o['id'] }}">{{ $o['label'] }}</option>@endforeach
                            </select>
                        </div>
                        <div class="hc-field">
                            <label class="hc-label" for="st-partnership">{{ __('search.f.partnership') }}</label>
                            <select id="st-partnership" class="hc-select" wire:model.live="filters.partnership">
                                <option value="">{{ __('search.all') }}</option>
                                <option value="individual">{{ __('search.f.individual') }}</option>
                                <option value="partnership">{{ __('search.f.partnered') }}</option>
                            </select>
                        </div>
                        <div class="hc-field">
                            <label class="hc-label" for="st-request_year">{{ __('statistics.request_year') }}</label>
                            <select id="st-request_year" class="hc-select" multiple size="4" wire:model.live="filters.request_year" aria-describedby="st-multi-hint">
                                @foreach($years['request'] as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
                            </select>
                        </div>
                        <div class="hc-field">
                            <label class="hc-label" for="st-fund_year">{{ __('statistics.fund_year') }}</label>
                            <select id="st-fund_year" class="hc-select" multiple size="4" wire:model.live="filters.fund_year" aria-describedby="st-multi-hint">
                                @foreach($years['fund'] as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
                            </select>
                        </div>
                        @foreach($dates as $key)
                            <div class="hc-field">
                                <label class="hc-label" for="st-{{ $key }}">{{ __('search.f.'.$key) }}</label>
                                <input id="st-{{ $key }}" class="hc-input num ltr" type="date" wire:model.live="filters.{{ $key }}">
                            </div>
                        @endforeach
                        @foreach($amounts as $key)
                            <div class="hc-field">
                                <label class="hc-label" for="st-{{ $key }}">{{ __('search.f.'.$key) }}</label>
                                <input id="st-{{ $key }}" class="hc-input num ltr" type="number" min="0" step="any" inputmode="decimal" wire:model.live.debounce.500ms="filters.{{ $key }}">
                            </div>
                        @endforeach
                    </div>
                    <p id="st-multi-hint" class="muted" style="font-size:.82rem;margin-block-start:8px">{{ __('search.multi_hint') }}</p>
                </div>
            </div>
        </div>

        <div class="hc-toolbar" style="align-items:center;flex-wrap:wrap">
            @if($cityOptions->count() > 1)
                <div class="hc-chips" role="group" aria-label="{{ __('search.cities') }}">
                    @foreach($cityOptions as $c)
                        <button type="button" class="hc-chip" wire:click="toggleCity({{ $c->id }})" aria-pressed="{{ in_array((int) $c->id, $this->cities, true) ? 'true' : 'false' }}">{{ $en ? $c->name_en : $c->name_ar }} <span class="muted ltr">{{ $c->code }}</span></button>
                    @endforeach
                </div>
            @endif
            <span class="muted" style="margin-inline-start:auto" aria-live="polite">{{ __('statistics.total_projects', ['count' => number_format($result['totals'][$first])]) }}</span>
            @if($canExport)
                <x-hc.button variant="ghost" size="sm" icon="file" wire:click="exportTable('xlsx')">{{ __('search.export_excel') }}</x-hc.button>
                <x-hc.button variant="ghost" size="sm" icon="file" wire:click="exportTable('csv')">{{ __('search.export_csv') }}</x-hc.button>
            @endif
            @if($activeFilters > 0)
                <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('search.clear') }}</x-hc.button>
            @endif
        </div>
    </x-hc.card>

    @if($result['totals'][$first] === 0 && array_sum($result['totals']) === 0)
        <x-hc.card><x-hc.empty :title="__('statistics.none')" icon="chart">{{ __('search.none_hint') }}</x-hc.empty></x-hc.card>
    @else
        <x-hc.card style="margin-block-end:16px">
            <div class="hc-chips" role="group" aria-label="{{ __('report_builder.chart_type') }}" style="margin-block-end:12px">
                @foreach(\App\Livewire\StatisticsPage::CHARTS as $type)
                    <button type="button" class="hc-chip" wire:click="$set('chart', '{{ $type }}')" aria-pressed="{{ $chart === $type ? 'true' : 'false' }}">{{ __('report_builder.chart_'.$type) }}</button>
                @endforeach
            </div>
            <x-hc.chart :type="$chart" :labels="$charts['labels']" :datasets="$charts['datasets']" :title="__('statistics.axes.'.$result['axis'])"/>
        </x-hc.card>

        <x-hc.card :flush="true">
            <div class="hc-table-wrap" role="region" tabindex="0" aria-label="{{ __('statistics.table') }}" wire:loading.class="opacity-60">
                <table class="hc-table">
                    <thead><tr>
                        <th scope="col">{{ __('statistics.axes.'.$result['axis']) }}</th>
                        @foreach($visible as $s)<th scope="col">{{ __('statistics.series.'.$s) }}</th>@endforeach
                        <th scope="col">{{ __('statistics.share') }}</th>
                        @if($many)@foreach($shown as $c)<th scope="col">{{ $en ? $c->name_en : $c->name_ar }}</th>@endforeach @endif
                    </tr></thead>
                    <tbody>
                    @foreach($result['rows'] as $row)
                        <tr wire:key="stat-{{ $result['axis'] }}-{{ $row['key'] }}">
                            <th scope="row">{{ $row['label'] }}</th>
                            @foreach($visible as $s)
                                <td class="num ltr">
                                    @if($row['url'] && $row['counts'][$s] > 0 && $s === 'all')<a class="row-link" href="{{ $row['url'] }}">{{ number_format($row['counts'][$s]) }}</a>@else{{ number_format($row['counts'][$s]) }}@endif
                                </td>
                            @endforeach
                            <td class="num ltr">{{ number_format($row['share'], 1) }}%</td>
                            @if($many)@foreach($shown as $c)<td class="num ltr">{{ number_format($row['by_city'][(int) $c->id] ?? 0) }}</td>@endforeach @endif
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr>
                        <th scope="row">{{ __('statistics.total') }}</th>
                        @foreach($visible as $s)<td class="num ltr"><strong>{{ number_format($result['totals'][$s]) }}</strong></td>@endforeach
                        <td class="num ltr">100%</td>
                        @if($many)@foreach($shown as $c)<td class="num ltr"><strong>{{ number_format(array_sum(array_map(fn ($r) => $r['by_city'][(int) $c->id] ?? 0, $result['rows']))) }}</strong></td>@endforeach @endif
                    </tr></tfoot>
                </table>
            </div>
            <p class="muted" style="font-size:.8rem;padding:6px 20px">{{ __('statistics.note') }}</p>
        </x-hc.card>
    @endif
</div>
