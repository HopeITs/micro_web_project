<div>
    @php($en = app()->getLocale() === 'en')
    @include('partials.filters-style')
    <x-hc.card :flush="true">
        <div class="hc-toolbar">
            <div class="hc-field grow">
                <label class="hc-label" for="q">{{ __('hc.search') }}</label>
                {{-- A column pasted from Excel arrives as lines and an input drops line breaks, so they become commas (OR). --}}
                <div style="position:relative" x-data>
                    <input id="q" class="hc-input" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('people.search_placeholder') }}" title="{{ __('hc.search_multi_hint') }}" autocomplete="off" style="padding-inline-start:40px"
                           x-on:paste="const t = ($event.clipboardData || window.clipboardData).getData('text'); if (/[\r\n]/.test(t)) { $event.preventDefault(); $el.setRangeText(t.split(/[\r\n]+/).map(s => s.trim()).filter(Boolean).join(', '), $el.selectionStart, $el.selectionEnd, 'end'); $el.dispatchEvent(new Event('input', { bubbles: true })); }">
                    <x-hc.icon name="search" :size="18" style="position:absolute;inset-inline-start:12px;inset-block-start:12px;color:var(--muted)"/>
                </div>
                <span class="hc-hint">{{ __('hc.search_multi_hint') }}</span>
            </div>
        </div>
        <x-hc.filter-bar :bar="$filterBar"/>
        <div class="hc-toolbar" style="align-items:center">
            <span class="hc-label">{{ __('hc.cities') }}</span>
            <div class="hc-chips" role="group" aria-label="{{ __('hc.cities') }}">
                @foreach($cityOptions as $c)
                    <button type="button" class="hc-chip" wire:click="toggleCity({{ $c->id }})" aria-pressed="{{ in_array($c->id, $cities, true) ? 'true' : 'false' }}">{{ $en ? $c->name_en : $c->name_ar }} <span class="muted ltr">{{ $c->code }}</span></button>
                @endforeach
            </div>
            <span class="muted" style="margin-inline-start:auto" aria-live="polite">{{ __('hc.results', ['count' => number_format($people->total())]) }}</span>
            @if($search !== '' || $cities !== [] || $filterBar['chips'] !== [])
                <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('hc.clear') }}</x-hc.button>
            @endif
        </div>

        <div class="hc-toolbar" style="align-items:center;padding-block-start:0">
            <x-hc.column-picker :picker="$columnPicker"/>
        </div>

        <div class="hc-table-wrap" wire:loading.class="opacity-60">
            @if($people->isEmpty())
                <x-hc.empty :title="__('hc.no_results')">{{ __('people.no_results_hint') }}</x-hc.empty>
            @else
            <x-hc.data-table :rows="$people" :table="$table"/>
            @endif
        </div>

        @if($people->total() > $table['per_page_choices'][0] || $people->lastPage() > 1)
        <div class="hc-pagination">
            <span>{{ __('hc.page', ['page' => $people->currentPage(), 'pages' => $people->lastPage()]) }}</span>
            <label class="hc-per-page"><span>{{ __('hc.per_page') }}</span>
                <select class="hc-select" wire:model.live="perPage">
                    @foreach($table['per_page_choices'] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                </select>
            </label>
            @if($people->lastPage() > 1)
            <nav aria-label="pagination">
                <x-hc.button variant="secondary" size="sm" wire:click="previousPage" :disabled="$people->onFirstPage()">{{ __('hc.previous') }}</x-hc.button>
                <x-hc.button variant="secondary" size="sm" wire:click="nextPage" :disabled="! $people->hasMorePages()">{{ __('hc.next') }}</x-hc.button>
            </nav>
            @endif
        </div>
        @endif
    </x-hc.card>
</div>
