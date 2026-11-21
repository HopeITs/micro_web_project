@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $stages = \App\Services\Field\VisitorStatisticsService::STAGES;
@endphp
<div>
    @include('partials.filters-style')
    <x-hc.card :flush="true">
        <div class="hc-filters"><div class="hc-filters-grid" style="--cols:3">
            <div class="hc-field">
                <label class="hc-label" for="st-from">{{ __('visits.statistics.from') }}</label>
                <input id="st-from" class="hc-input num ltr" type="date" wire:model.live="from">
                @error('from')<p class="hc-error" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="hc-field">
                <label class="hc-label" for="st-to">{{ __('visits.statistics.to') }}</label>
                <input id="st-to" class="hc-input num ltr" type="date" wire:model.live="to">
            </div>
            <div class="hc-field">
                <label class="hc-label" for="st-type">{{ __('visits.statistics.type') }}</label>
                <select id="st-type" class="hc-select" wire:model.live="type">
                    <option value="">{{ __('visits.statistics.all') }}</option>
                    @foreach($types as $t)<option value="{{ $t->id }}">{{ $en && $t->name_en ? $t->name_en : $t->name_ar }}</option>@endforeach
                </select>
            </div>
        </div></div>
        <div class="hc-toolbar" style="align-items:center;flex-wrap:wrap">
            @if($cityOptions->count() > 1)
                <div class="hc-chips" role="group" aria-label="{{ __('visits.schedule.cities') }}">
                    @foreach($cityOptions as $c)
                        <button type="button" class="hc-chip" wire:click="toggleCity({{ $c->id }})" aria-pressed="{{ in_array((int) $c->id, array_map('intval', $this->cities), true) ? 'true' : 'false' }}">{{ $en ? $c->name_en : $c->name_ar }} <span class="muted ltr">{{ $c->code }}</span></button>
                    @endforeach
                </div>
            @endif
            @if($canExport && ! ($filters['invalid'] ?? false))
                <span style="margin-inline-start:auto"></span>
                <x-hc.button variant="ghost" size="sm" icon="file" :href="route('visits.statistics.export', ['locale' => $locale] + $query + ['format' => 'xlsx'])">Excel</x-hc.button>
                <x-hc.button variant="ghost" size="sm" icon="file" :href="route('visits.statistics.export', ['locale' => $locale] + $query + ['format' => 'csv'])">CSV</x-hc.button>
            @endif
        </div>
        <div class="hc-table-wrap" wire:loading.class="opacity-60">
            @if($rows->isEmpty())
                <x-hc.empty :title="__('visits.statistics.none')" icon="users">{{ __('visits.schedule.none_hint') }}</x-hc.empty>
            @else
            <table class="hc-table stack">
                <thead><tr><th>{{ __('visits.statistics.visitor') }}</th>@foreach($stages as $s)<th class="num">{{ $s }}</th>@endforeach<th class="num">{{ __('visits.statistics.total') }}</th></tr></thead>
                <tbody>
                @foreach($rows as $i => $r)
                    <tr wire:key="v-{{ $i }}">
                        <td data-label="{{ __('visits.statistics.visitor') }}" class="full"><strong>{{ $r->name }}</strong></td>
                        @foreach($stages as $s)<td data-label="{{ $s }}" class="num">{{ $r->stages[$s] }}</td>@endforeach
                        <td data-label="{{ __('visits.statistics.total') }}" class="num"><strong>{{ $r->total }}</strong></td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr>
                    <th>{{ __('visits.statistics.totals') }}</th>@foreach($stages as $s)<th class="num">{{ $totals[$s] }}</th>@endforeach<th class="num">{{ $totals['total'] }}</th>
                </tr></tfoot>
            </table>
            @endif
        </div>
    </x-hc.card>
</div>
