<div>
    @php($en = app()->getLocale() === 'en')
    <x-hc.reach :reach="$reach" style="margin-block-end:16px"/>
    <div class="hc-counts hc-counts-path" role="group" aria-label="{{ __('hc.path_title') }}" style="margin-block-end:16px">
        @foreach($pathCounts as $group => $count)
            <x-hc.count-tile :count="$count" :label="__('hc.path_group_'.$group)" :tone="\App\Support\ProjectStatus::groupTone($group)" :active="$path === $group" wire:click="togglePath('{{ $group }}')"/>
        @endforeach
    </div>
    <x-hc.card :flush="true">
        {{-- X4: one compact toolbar (search and cities), the filter row (chips, + filter, ask in words), then the result line. --}}
        <div class="hc-toolbar hc-list-bar">
            <div class="hc-field grow">
                <label class="sr-only" for="q">{{ __('hc.search') }}</label>
                {{-- A column pasted from Excel arrives as lines and an input drops line breaks, so they become commas (OR). --}}
                <div class="hc-search-input" x-data>
                    <x-hc.icon name="search" :size="18"/>
                    <input id="q" class="hc-input" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('hc.search_placeholder') }}" title="{{ __('hc.search_multi_hint') }}" autocomplete="off"
                           x-on:paste="const t = ($event.clipboardData || window.clipboardData).getData('text'); if (/[\r\n]/.test(t)) { $event.preventDefault(); $el.setRangeText(t.split(/[\r\n]+/).map(s => s.trim()).filter(Boolean).join(', '), $el.selectionStart, $el.selectionEnd, 'end'); $el.dispatchEvent(new Event('input', { bubbles: true })); }">
                </div>
            </div>
            <div class="hc-chips hc-city-chips" role="group" aria-label="{{ __('hc.cities') }}">
                @foreach($cityOptions as $c)
                    <button type="button" class="hc-chip" wire:click="toggleCity({{ $c->id }})" aria-pressed="{{ in_array($c->id, $cities, true) ? 'true' : 'false' }}" title="{{ $c->code }}">{{ $en ? $c->name_en : $c->name_ar }}</button>
                @endforeach
            </div>
        </div>
        <x-hc.filter-bar :bar="$filterBar"/>
        <div class="hc-result-line">
            <span class="hc-result-count" aria-live="polite">{{ __('hc.results', ['count' => number_format($projects->total())]) }}</span>
            @if($search !== '' || $cities !== [] || $path !== '' || $filterBar['chips'] !== [])
                <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('hc.clear') }}</x-hc.button>
            @endif
            <div class="hc-result-tools"><x-hc.column-picker :picker="$columnPicker"/></div>
        </div>

        <div class="hc-table-wrap" wire:loading.class="opacity-60">
            @if($projects->isEmpty())
                <x-hc.empty :title="__('hc.no_results')">{{ __('hc.no_results_hint') }}</x-hc.empty>
            @else
            <x-hc.data-table :rows="$projects" :table="$table"/>
            @endif
        </div>

        @if($projects->total() > $table['per_page_choices'][0] || $projects->lastPage() > 1)
        <div class="hc-pagination">
            <span>{{ __('hc.page', ['page' => $projects->currentPage(), 'pages' => $projects->lastPage()]) }}</span>
            <label class="hc-per-page"><span>{{ __('hc.per_page') }}</span>
                <select class="hc-select" wire:model.live="perPage">
                    @foreach($table['per_page_choices'] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                </select>
            </label>
            @if($projects->lastPage() > 1)
            <nav aria-label="pagination">
                <x-hc.button variant="secondary" size="sm" wire:click="previousPage" :disabled="$projects->onFirstPage()">{{ __('hc.previous') }}</x-hc.button>
                <x-hc.button variant="secondary" size="sm" wire:click="nextPage" :disabled="! $projects->hasMorePages()">{{ __('hc.next') }}</x-hc.button>
            </nav>
            @endif
        </div>
        @endif
    </x-hc.card>
</div>
