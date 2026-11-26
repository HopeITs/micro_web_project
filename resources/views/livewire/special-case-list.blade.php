@php
    $en = app()->getLocale() === 'en';
    $name = fn ($row) => $en && $row->name_en ? $row->name_en : $row->name_ar;
    $locale = app()->getLocale();
    $invalid = $filters['invalid'] ?? false;
@endphp
<div>
    @include('partials.filters-style')
    <x-hc.card :flush="true">
        <div class="hc-filters"><div class="hc-filters-grid" style="--cols:4">
            <div class="hc-field">
                <label class="hc-label" for="sl-from">{{ __('special_cases.list.from') }}</label>
                <input id="sl-from" class="hc-input num ltr" type="date" wire:model.live="from">
            </div>
            <div class="hc-field">
                <label class="hc-label" for="sl-to">{{ __('special_cases.list.to') }}</label>
                <input id="sl-to" class="hc-input num ltr" type="date" wire:model.live="to">
            </div>
            <div class="hc-field">
                <label class="hc-label" for="sl-type">{{ __('special_cases.fields.type') }}</label>
                <select id="sl-type" class="hc-select" wire:model.live="type">
                    <option value="">{{ __('special_cases.list.all') }}</option>
                    @foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="sl-recorder">{{ __('special_cases.fields.recorder') }}</label>
                <select id="sl-recorder" class="hc-select" wire:model.live="recorder">
                    <option value="">{{ __('special_cases.list.all') }}</option>
                    @foreach($recorders as $r)<option value="{{ $r->id }}">{{ $r->username }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="sl-state">{{ __('special_cases.list.state') }}</label>
                <select id="sl-state" class="hc-select" wire:model.live="state">
                    <option value="">{{ __('special_cases.list.all') }}</option>
                    @foreach($states as $s)<option value="{{ $s->id }}">{{ $name($s) }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field grow">
                <label class="hc-label" for="sl-q">{{ __('special_cases.list.q') }}</label>
                <input id="sl-q" class="hc-input" type="search" wire:model.live.debounce.400ms="q" maxlength="100" placeholder="{{ __('special_cases.list.q_placeholder') }}" autocomplete="off">
            </div>
        </div>
        @error('from')<x-hc.alert type="danger" style="margin-block-start:12px">{{ $message }}</x-hc.alert>@enderror
        </div>
        <div class="hc-toolbar" style="align-items:center;flex-wrap:wrap">
            @if($cityOptions->count() > 1)
                <div class="hc-chips" role="group" aria-label="{{ __('special_cases.columns.city') }}">
                    @foreach($cityOptions as $c)
                        <button type="button" class="hc-chip" wire:click="toggleCity({{ $c->id }})" aria-pressed="{{ in_array((int) $c->id, array_map('intval', $this->cities), true) ? 'true' : 'false' }}">{{ $en ? $c->name_en : $c->name_ar }} <span class="muted ltr">{{ $c->code }}</span></button>
                    @endforeach
                </div>
            @endif
            <span class="muted" style="margin-inline-start:auto" aria-live="polite">{{ __('special_cases.list.total', ['count' => number_format($rows->total())]) }}</span>
            @if($canExport && ! $invalid)
                <x-hc.export-buttons to="special-cases.export" :query="$query" :private="$filters['q'] !== ''"/>
            @endif
            <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('special_cases.list.clear') }}</x-hc.button>
        </div>

        <div class="hc-table-wrap" wire:loading.class="opacity-60">
            @if($rows->total() === 0)
                <x-hc.empty :title="__('special_cases.list.none')" icon="clipboard">{{ __('special_cases.list.none_hint') }}</x-hc.empty>
            @else
                <table class="hc-table" data-smart="off">
                    <thead><tr>@foreach($headings as $i => $c)<x-hc.sort-th :column="\App\Services\Field\SpecialCaseListService::SORT_KEYS[$i]" :label="$c" :sort="$sortKey" :dir="$sortDir" :style="$i >= 10 ? 'min-width:14rem' : 'min-width:7rem'"/>@endforeach</tr></thead>
                    <tbody>
                    @foreach($rows as $i => $row)
                        <tr wire:key="sc-{{ $links[$i]['id'] }}">
                            @foreach($row as $j => $cell)
                                <td @class(['num ltr' => in_array($j, [1, 7], true)]) @style(['white-space:pre-line' => $j >= 11])>
                                    @if($j === 1)<a href="{{ route('special-cases.project', ['locale' => $locale, 'project' => $links[$i]['project']]) }}#case-{{ $links[$i]['id'] }}">{{ $cell }}</a>@else{{ $cell === '' ? '—' : $cell }}@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if($rows->lastPage() > 1)
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
