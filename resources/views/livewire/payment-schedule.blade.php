@php
    $en = app()->getLocale() === 'en';
    $name = fn ($ar, $enName) => $en && $enName ? $enName : $ar;
    $money = fn ($v) => number_format((float) $v, str_contains((string) $v, '.') ? 2 : 0);
    $locale = app()->getLocale();
@endphp
<div>
    @include('partials.filters-style')
    <div class="hc-counts hc-fin-tiles" role="group" aria-label="{{ __('finance.payments.view') }}">
        <x-hc.count-tile :count="$tiles['overdue']" :label="__('finance.payments.tiles.overdue')" tone="danger" icon="warning" wire:click="show('overdue')" :active="$tab === 'overdue'"/>
        <x-hc.count-tile :count="$tiles['month']" :label="__('finance.payments.tiles.month')" tone="warn" icon="calendar" wire:click="show('month')" :active="$tab === 'month'"/>
        <x-hc.count-tile :count="$tiles['paid_month']" :label="__('finance.payments.tiles.paid_month')" tone="ok" icon="check" wire:click="show('paid_month')" :active="$tab === 'paid_month'"/>
    </div>

    <x-hc.card :flush="true">
        <div class="hc-filters"><div class="hc-filters-grid" style="--cols:5">
            <div class="hc-field">
                <label class="hc-label" for="pay-view">{{ __('finance.payments.view') }}</label>
                <select id="pay-view" class="hc-select" wire:model.live="tab">
                    @foreach(\App\Services\Finance\PaymentScheduleService::VIEWS as $v)<option value="{{ $v }}">{{ __('finance.payments.views.'.$v) }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="pay-year">{{ __('finance.payments.year') }}</label>
                <select id="pay-year" class="hc-select" wire:model.live="year">
                    <option value="">{{ __('finance.payments.all') }}</option>
                    @for($y = now()->year + 2; $y >= 2018; $y--)<option value="{{ $y }}">{{ $y }}</option>@endfor
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="pay-month">{{ __('finance.payments.month') }}</label>
                <select id="pay-month" class="hc-select" wire:model.live="month">
                    <option value="">{{ __('finance.payments.all') }}</option>
                    @for($m = 1; $m <= 12; $m++)<option value="{{ $m }}">{{ $m }}</option>@endfor
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="pay-group">{{ __('finance.payments.group') }}</label>
                <select id="pay-group" class="hc-select" wire:model.live="group">
                    <option value="">{{ __('finance.payments.all') }}</option>
                    @foreach($groups as $g)<option value="{{ $g->id }}">{{ $name($g->name_ar, $g->name_en) }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="pay-currency">{{ __('finance.payments.currency') }}</label>
                <select id="pay-currency" class="hc-select" wire:model.live="currency">
                    <option value="">{{ __('finance.payments.all') }}</option>
                    @foreach(\App\Services\Finance\FinanceService::CURRENCIES as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                </select>
            </div>
        </div></div>
        <div class="hc-toolbar">
            <div class="hc-field grow">
                <label class="hc-label" for="pay-q">{{ __('hc.search') }}</label>
                {{-- A column pasted from Excel arrives as lines and an input drops line breaks, so they become commas (OR). --}}
                <div style="position:relative" x-data>
                    <input id="pay-q" class="hc-input" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('hc.search_placeholder') }}" title="{{ __('hc.search_multi_hint') }}" autocomplete="off" style="padding-inline-start:40px"
                           x-on:paste="const t = ($event.clipboardData || window.clipboardData).getData('text'); if (/[\r\n]/.test(t)) { $event.preventDefault(); $el.setRangeText(t.split(/[\r\n]+/).map(s => s.trim()).filter(Boolean).join(', '), $el.selectionStart, $el.selectionEnd, 'end'); $el.dispatchEvent(new Event('input', { bubbles: true })); }">
                    <x-hc.icon name="search" :size="18" style="position:absolute;inset-inline-start:12px;inset-block-start:12px;color:var(--muted)"/>
                </div>
                <span class="hc-hint">{{ __('hc.search_multi_hint') }}</span>
            </div>
        </div>
        <x-hc.filter-bar :bar="$filterBar"/>
        @if($relaxed)<p class="hc-hint" style="margin:0;padding:0 20px 8px">{{ __('hc.pay_view_relaxed') }}</p>@endif
        <div class="hc-toolbar" style="align-items:center">
            @if($cityOptions->count() > 1)
                <span class="hc-label">{{ __('finance.payments.cities') }}</span>
                <div class="hc-chips" role="group" aria-label="{{ __('finance.payments.cities') }}">
                    @foreach($cityOptions as $c)
                        <button type="button" class="hc-chip" wire:click="toggleCity({{ $c->id }})" aria-pressed="{{ in_array((int) $c->id, array_map('intval', $this->cities), true) ? 'true' : 'false' }}">{{ $en ? $c->name_en : $c->name_ar }} <span class="muted ltr">{{ $c->code }}</span></button>
                    @endforeach
                </div>
            @endif
            <span class="muted" style="margin-inline-start:auto" aria-live="polite">{{ __('hc.results', ['count' => number_format($rows->total())]) }}</span>
            @if($tab !== 'due' || $this->cities !== [] || $year !== '' || $month !== '' || $group !== '' || $currency !== '' || $search !== '' || $filterBar['chips'] !== [])
                <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('finance.payments.clear') }}</x-hc.button>
            @endif
        </div>

        <div class="hc-toolbar" style="align-items:center;padding-block-start:0">
            <x-hc.column-picker :picker="$columnPicker"/>
        </div>

        @if($totals->isNotEmpty())
        <div style="padding:12px 20px;border-block-end:1px solid var(--line)">
            <p class="hc-label" style="margin:0 0 6px">{{ __('finance.payments.totals') }}</p>
            <div class="hc-chips">
                @foreach($totals as $t)<x-hc.badge tone="brand"><span class="num">{{ $money($t->total) }}</span>&nbsp;{{ $t->currency }} · {{ __('finance.payments.count', ['count' => $t->installments]) }}</x-hc.badge>@endforeach
            </div>
            <p class="hc-hint" style="margin:6px 0 0">{{ __('finance.payments.currency_note') }}</p>
        </div>
        @endif

        <div class="hc-table-wrap" wire:loading.class="opacity-60">
            @if($rows->isEmpty())
                <x-hc.empty :title="__('finance.payments.none')" icon="calendar">{{ __('finance.payments.none_hint') }}</x-hc.empty>
            @else
            <table class="hc-table stack">
                <thead><tr>
                    @foreach($table['columns'] as $col)
                        @php($dir = $col['dir'])
                        <th scope="col" @class(['num' => $col['field']->numeric()]) aria-sort="{{ $dir === 'asc' ? 'ascending' : ($dir === 'desc' ? 'descending' : 'none') }}" wire:key="th-{{ $col['key'] }}">
                            @if($col['sortable'])
                                <button type="button" class="hc-sort @if($dir) is-sorted @endif" wire:click="sortBy('{{ $col['key'] }}', $event.shiftKey)" title="{{ __('hc.sort_hint') }}">
                                    <span>{{ $col['label'] }}</span>
                                    <span class="hc-sort-ind" aria-hidden="true">{{ $dir === 'asc' ? '▲' : ($dir === 'desc' ? '▼' : '↕') }}</span>
                                    @if($col['priority'])<sup class="hc-sort-pri">{{ $col['priority'] }}</sup>@endif
                                    @if($dir)<span class="sr-only">({{ __('hc.sort_'.$dir) }})</span>@endif
                                </button>
                            @else
                                {{ $col['label'] }}
                            @endif
                        </th>
                    @endforeach
                    <th></th>
                </tr></thead>
                <tbody>
                @foreach($rows as $r)
                    <tr wire:key="inst-{{ $r->id }}" @class(['hc-fin-overdue' => ! $r->row_paid && $r->row_due !== null && substr((string) $r->row_due, 0, 10) < now()->toDateString()])>
                        @foreach($table['columns'] as $col)
                            @php($cell = $col['field']->cell($r))
                            <td data-label="{{ $col['label'] }}" @class(['num' => $col['field']->numeric()])>
                                @if($cell['text'] === '')
                                    {{ __('hc.not_set') }}
                                @elseif($cell['badge'])
                                    <x-hc.badge :tone="$cell['tone']" :icon="$cell['icon']">{{ $cell['text'] }}</x-hc.badge>
                                @else
                                    <span @class([$cell['class'], 'ltr' => $cell['ltr']]) @if($cell['ltr']) dir="ltr" @endif>{{ $cell['text'] }}</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="full"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('finance.show', ['locale' => $locale, 'project' => $r->project_id]) }}"><x-hc.icon name="wallet" :size="16"/>{{ __('finance.payments.open') }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>

        @if($rows->total() > $table['per_page_choices'][0] || $rows->lastPage() > 1)
        <div class="hc-pagination">
            <span>{{ __('hc.page', ['page' => $rows->currentPage(), 'pages' => $rows->lastPage()]) }}</span>
            <label class="hc-per-page"><span>{{ __('hc.per_page') }}</span>
                <select class="hc-select" wire:model.live="perPage">
                    @foreach($table['per_page_choices'] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                </select>
            </label>
            @if($rows->lastPage() > 1)
            <nav aria-label="pagination">
                <x-hc.button variant="secondary" size="sm" wire:click="previousPage" :disabled="$rows->onFirstPage()">{{ __('hc.previous') }}</x-hc.button>
                <x-hc.button variant="secondary" size="sm" wire:click="nextPage" :disabled="! $rows->hasMorePages()">{{ __('hc.next') }}</x-hc.button>
            </nav>
            @endif
        </div>
        @endif
    </x-hc.card>
</div>
