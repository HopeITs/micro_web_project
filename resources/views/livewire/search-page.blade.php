@php
    $en = app()->getLocale() === 'en';
    $lists = ['fund_type', 'project_type', 'subtype', 'status', 'donor', 'donor_group', 'category', 'subcategory', 'career', 'parish', 'marital', 'home_street', 'project_street', 'age'];
    $meetingLists = ['category', 'subcategory', 'career'];
    $dates = $filterKind === 'meeting' ? ['meeting_from', 'meeting_to'] : ['request_from', 'request_to', 'fund_from', 'fund_to'];
    $amounts = ['amount_from', 'amount_to', 'usd_from', 'usd_to'];
    $shownLists = $filterKind === 'meeting' ? $meetingLists : $lists;
@endphp
<div>
    @include('partials.filters-style')
    <x-hc.card :flush="true">
        <div class="hc-filters">
            <div class="hc-filters-grid" style="--cols:3">
                <div class="hc-field">
                    <label class="hc-label" for="sp-mode">{{ __('search.mode') }}</label>
                    <select id="sp-mode" class="hc-select" wire:model.live="mode">
                        @foreach($groups as $group => $items)
                            <optgroup label="{{ __('search.groups.'.$group) }}">
                                @foreach($items as $key => $m)<option value="{{ $key }}">{{ __('search.modes.'.$key) }}</option>@endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div class="hc-field grow">
                    <label class="hc-label" for="sp-term">{{ __('search.term') }}</label>
                    <input id="sp-term" class="hc-input" type="search" wire:model.live.debounce.500ms="term" maxlength="100" placeholder="{{ __('search.term_placeholder') }}" autocomplete="off">
                </div>
            </div>

            @if($filterKind !== 'none')
            <div x-data="{ open: window.matchMedia('(min-width: 900px)').matches }" style="margin-block-start:12px">
                <button type="button" class="hc-btn hc-btn-ghost hc-btn-sm" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-controls="sp-filters">
                    <x-hc.icon name="settings" :size="18"/>{{ $activeFilters > 0 ? __('search.filters_active', ['count' => $activeFilters]) : __('search.filters') }}
                </button>
                <div id="sp-filters" x-show="open" x-cloak style="margin-block-start:12px">
                    <div class="hc-filters-grid" style="--cols:4">
                        @foreach($shownLists as $key)
                            <div class="hc-field">
                                <label class="hc-label" for="sp-{{ $key }}">{{ __('search.f.'.$key) }}</label>
                                <select id="sp-{{ $key }}" class="hc-select" multiple size="4" wire:model.live="filters.{{ $key }}" aria-describedby="sp-multi-hint">
                                    @foreach($options[$key] ?? [] as $o)<option value="{{ $o['id'] }}">{{ $o['label'] }}</option>@endforeach
                                </select>
                            </div>
                        @endforeach
                        @if($filterKind === 'project')
                            <div class="hc-field">
                                <label class="hc-label" for="sp-sex">{{ __('search.f.sex') }}</label>
                                <select id="sp-sex" class="hc-select" multiple size="2" wire:model.live="filters.sex" aria-describedby="sp-multi-hint">
                                    @foreach($options['sex'] as $o)<option value="{{ $o['id'] }}">{{ $o['label'] }}</option>@endforeach
                                </select>
                            </div>
                            <div class="hc-field">
                                <label class="hc-label" for="sp-partnership">{{ __('search.f.partnership') }}</label>
                                <select id="sp-partnership" class="hc-select" wire:model.live="filters.partnership">
                                    <option value="">{{ __('search.all') }}</option>
                                    <option value="individual">{{ __('search.f.individual') }}</option>
                                    <option value="partnership">{{ __('search.f.partnered') }}</option>
                                </select>
                            </div>
                        @endif
                        @foreach($dates as $key)
                            <div class="hc-field">
                                <label class="hc-label" for="sp-{{ $key }}">{{ __('search.f.'.$key) }}</label>
                                <input id="sp-{{ $key }}" class="hc-input num ltr" type="date" wire:model.live="filters.{{ $key }}">
                            </div>
                        @endforeach
                        @if($filterKind === 'project')
                            @foreach($amounts as $key)
                                <div class="hc-field">
                                    <label class="hc-label" for="sp-{{ $key }}">{{ __('search.f.'.$key) }}</label>
                                    <input id="sp-{{ $key }}" class="hc-input num ltr" type="number" min="0" step="any" inputmode="decimal" wire:model.live.debounce.500ms="filters.{{ $key }}">
                                </div>
                            @endforeach
                        @endif
                    </div>
                    <p id="sp-multi-hint" class="muted" style="font-size:.82rem;margin-block-start:8px">{{ __('search.multi_hint') }}</p>
                </div>
            </div>
            @endif
        </div>

        <div class="hc-toolbar" style="align-items:center;flex-wrap:wrap">
            @if($cityOptions->count() > 1)
                <div class="hc-chips" role="group" aria-label="{{ __('search.cities') }}">
                    @foreach($cityOptions as $c)
                        <button type="button" class="hc-chip" wire:click="toggleCity({{ $c->id }})" aria-pressed="{{ in_array((int) $c->id, $this->cities, true) ? 'true' : 'false' }}">{{ $en ? $c->name_en : $c->name_ar }} <span class="muted ltr">{{ $c->code }}</span></button>
                    @endforeach
                </div>
            @endif
            @if($searchMode->hasLastSwitch())
                <label class="hc-check" style="display:inline-flex;gap:6px;align-items:center"><input type="checkbox" wire:model.live="last"> {{ __('search.last') }}</label>
            @endif
            <span class="muted" style="margin-inline-start:auto" aria-live="polite">{{ __($searchMode->hasProject() ? 'search.count_projects' : 'search.count_rows', ['count' => number_format($total)]) }}</span>
            @if($canExport)
                <x-hc.button variant="ghost" size="sm" icon="file" wire:click="exportTable('xlsx')">{{ __('search.export_excel') }}</x-hc.button>
                <x-hc.button variant="ghost" size="sm" icon="file" wire:click="exportTable('csv')">{{ __('search.export_csv') }}</x-hc.button>
            @endif
            @if($filterKind !== 'none' || $searchMode->hasLastSwitch())
                <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('search.clear') }}</x-hc.button>
            @endif
        </div>

        @if($summary !== null && $searchMode->key() === 'applications')
            <div class="hc-card-body" style="display:flex;gap:24px;flex-wrap:wrap;border-block-end:1px solid var(--line)" aria-label="{{ __('search.summary.applications') }}">
                <span><strong class="num">{{ number_format($summary['projects']) }}</strong> <span class="muted">{{ __('search.summary.projects') }}</span></span>
                <span><strong class="num">{{ number_format($summary['members']) }}</strong> <span class="muted">{{ __('search.summary.members') }}</span></span>
            </div>
        @elseif($summary !== null && $searchMode->key() === 'loans')
            <div class="hc-card-body" style="border-block-end:1px solid var(--line)">
                <p class="muted" style="font-size:.85rem;margin-block-end:8px">{{ __('search.summary.loans_title') }}</p>
                <div class="hc-table-wrap" role="region" tabindex="0" aria-label="{{ __('search.summary.loans_title') }}">
                    <table class="hc-table">
                        <thead><tr>
                            <th scope="col">{{ __('search.summary.currency') }}</th>
                            @foreach(['count', 'loans', 'repayments', 'returned', 'net', 'repayment_loans', 'balance'] as $k)<th scope="col">{{ __('search.summary.'.$k) }}</th>@endforeach
                        </tr></thead>
                        <tbody>
                        @foreach($summary as $currency => $v)
                            <tr>
                                <th scope="row">{{ __('search.summary.'.$currency) }}</th>
                                <td class="num ltr">{{ number_format($v['count']) }}</td>
                                @foreach(['loans', 'repayments', 'returned', 'net', 'repayment_loans', 'balance'] as $k)<td class="num ltr">{{ number_format((float) $v[$k], 2) }}</td>@endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if($rows->total() === 0)
            <x-hc.empty :title="__('search.none')" icon="search">{{ __('search.none_hint') }}</x-hc.empty>
        @else
            {{-- The table scrolls inside its own region, never the page. --}}
            <div class="hc-table-wrap" role="region" tabindex="0" aria-label="{{ __('search.table') }}" wire:loading.class="opacity-60">
                <table class="hc-table">
                    <thead><tr>@foreach($headings as $h)<th scope="col">{{ $h }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach($cells as $i => $line)
                        <tr wire:key="sr-{{ $searchMode->key() }}-{{ $rows->currentPage() }}-{{ $i }}">
                            @foreach($line['cells'] as $j => $cell)
                                <td @class(['num ltr' => in_array($j, $numeric, true)]) @style(['white-space:pre-line;min-width:14rem' => is_string($cell) && mb_strlen($cell) > 60])>
                                    @if($j === $linkColumn && $line['url'])
                                        <a class="row-link" href="{{ $line['url'] }}">{{ $cell === '' ? '—' : $cell }}</a>
                                    @else
                                        {{ $cell === '' || $cell === null ? '—' : $cell }}
                                    @endif
                                    @if($line['flag'] && $j === 2)
                                        <x-hc.badge tone="warn" icon="users" title="{{ __('search.duplicate_hint') }}">{{ __('search.duplicate') }}</x-hc.badge>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted" style="font-size:.8rem;padding:6px 20px">{{ __('search.scroll_hint') }}</p>

            @if($rows->lastPage() > 1)
            <div class="hc-pagination">
                <span>{{ __('hc.page', ['page' => $rows->currentPage(), 'pages' => $rows->lastPage()]) }}</span>
                <div style="display:flex;gap:8px">
                    @if(! $rows->onFirstPage())<x-hc.button variant="secondary" size="sm" wire:click="previousPage">{{ __('hc.previous') }}</x-hc.button>@endif
                    @if($rows->hasMorePages())<x-hc.button variant="secondary" size="sm" wire:click="nextPage">{{ __('hc.next') }}</x-hc.button>@endif
                </div>
            </div>
            @endif
        @endif
    </x-hc.card>
</div>
