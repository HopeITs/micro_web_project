@php
    $en = app()->getLocale() === 'en';
    $name = fn ($row) => $en && $row->name_en ? $row->name_en : $row->name_ar;
    $locale = app()->getLocale();
@endphp
<div>
    @include('partials.filters-style')
    <x-hc.card :flush="true">
        <div class="hc-filters"><div class="hc-filters-grid" style="--cols:5">
            <div class="hc-field">
                <label class="hc-label" for="vr-view">{{ __('visits.results.view') }}</label>
                <select id="vr-view" class="hc-select" wire:model.live="view">
                    @foreach(\App\Services\Field\VisitResultsService::VIEWS as $v)<option value="{{ $v }}">{{ __('visits.results.views.'.$v) }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="vr-visit">{{ __('visits.results.visit') }}</label>
                <select id="vr-visit" class="hc-select" wire:model.live="visit">
                    @foreach(\App\Services\Field\VisitResultsService::VISITS as $v)<option value="{{ $v }}">{{ __('visits.results.visits.'.$v) }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="vr-kind">{{ __('visits.results.kind') }}</label>
                <select id="vr-kind" class="hc-select" wire:model.live="kind">
                    @foreach(['o', 'v'] as $k)<option value="{{ $k }}">{{ __('visits.results.kinds.'.$k) }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="vr-from">{{ __('visits.results.from') }}</label>
                <input id="vr-from" class="hc-input num ltr" type="date" wire:model.live="from">
            </div>
            <div class="hc-field">
                <label class="hc-label" for="vr-to">{{ __('visits.results.to') }}</label>
                <input id="vr-to" class="hc-input num ltr" type="date" wire:model.live="to">
            </div>
            <div class="hc-field">
                <label class="hc-label" for="vr-type">{{ __('visits.results.type') }}</label>
                <select id="vr-type" class="hc-select" wire:model.live="type">
                    <option value="">{{ __('visits.results.all') }}</option>
                    @foreach($types as $t)<option value="{{ $t->id }}">{{ $name($t) }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="vr-cat">{{ __('visits.results.category') }}</label>
                <select id="vr-cat" class="hc-select" wire:model.live="category">
                    <option value="">{{ __('visits.results.all') }}</option>
                    @foreach($categories as $t)<option value="{{ $t->id }}">{{ $name($t) }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="vr-sub">{{ __('visits.results.subcategory') }}</label>
                <select id="vr-sub" class="hc-select" wire:model.live="subcategory">
                    <option value="">{{ __('visits.results.all') }}</option>
                    @foreach($subcategories as $t)<option value="{{ $t->id }}">{{ $name($t) }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="vr-career">{{ __('visits.results.career') }}</label>
                <select id="vr-career" class="hc-select" wire:model.live="career" @disabled($careers->isEmpty())>
                    <option value="">{{ __('visits.results.all') }}</option>
                    @foreach($careers as $t)<option value="{{ $t->id }}">{{ $name($t) }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field" style="align-self:center">
                <label class="hc-check"><input type="checkbox" wire:model.live="last" @disabled(! str_starts_with($visit, 'M'))> {{ __('visits.results.last') }}</label>
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
            <span class="muted" style="margin-inline-start:auto" aria-live="polite">{{ __('visits.results.total', ['count' => number_format($total)]) }}</span>
            @if($canExport)
                <x-hc.button variant="ghost" size="sm" icon="file" :href="route('visits.results.export', ['locale' => $locale] + $query + ['format' => 'xlsx'])">Excel</x-hc.button>
                <x-hc.button variant="ghost" size="sm" icon="file" :href="route('visits.results.export', ['locale' => $locale] + $query + ['format' => 'csv'])">CSV</x-hc.button>
            @endif
            <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('visits.schedule.clear') }}</x-hc.button>
        </div>

        <div class="hc-table-wrap" wire:loading.class="opacity-60">
            @if($total === 0)
                <x-hc.empty :title="__('visits.results.none')" icon="chart">{{ __('visits.schedule.none_hint') }}</x-hc.empty>
            @elseif($filters['view'] === 'counts')
                <table class="hc-table">
                    <thead><tr><th>{{ __('visits.results.columns.question') }}</th><th>{{ __('visits.results.columns.answer') }}</th><th class="num">{{ __('visits.results.columns.count') }}</th></tr></thead>
                    <tbody>
                    @foreach($counts as $qi => $q)
                        @foreach($q->answers as $ai => $a)
                            <tr wire:key="c-{{ $qi }}-{{ $ai }}">
                                @if($ai === 0)<td rowspan="{{ count($q->answers) }}" style="vertical-align:top;max-width:28rem">{{ $q->question }}</td>@endif
                                <td>{{ $a->name }}</td><td class="num">{{ number_format($a->count) }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            @else
                <table class="hc-table">
                    <thead><tr>@foreach($columns as $c)<th style="min-width:8rem">{{ $c }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach($rows as $i => $row)
                        <tr wire:key="r-{{ $rows->currentPage() }}-{{ $i }}">@foreach($row as $cell)<td>{{ $cell === '' ? '—' : $cell }}</td>@endforeach</tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if($filters['view'] === 'answers' && isset($rows) && $rows->lastPage() > 1)
        <div class="hc-pagination">
            <span>{{ __('hc.page', ['page' => $rows->currentPage(), 'pages' => $rows->lastPage()]) }}</span>
            <div style="display:flex;gap:8px">
                @if(! $rows->onFirstPage())<x-hc.button variant="secondary" size="sm" wire:click="previousPage">{{ __('hc.previous') }}</x-hc.button>@endif
                @if($rows->hasMorePages())<x-hc.button variant="secondary" size="sm" wire:click="nextPage">{{ __('hc.next') }}</x-hc.button>@endif
            </div>
        </div>
        @endif
    </x-hc.card>
</div>
