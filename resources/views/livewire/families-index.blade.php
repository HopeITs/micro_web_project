<div class="fam-index">
    @php
        $en = app()->getLocale() === 'en';
        $locale = app()->getLocale();
        $columns = ['head' => __('families.head'), 'number' => __('people.family_number'), 'members' => __('families.members'), 'residents' => __('families.residents'), 'working' => __('families.working'), 'children' => __('families.children')];
        $chosen = array_map('intval', $cities);
    @endphp
    <div class="hc-counts fam-counts">
        <x-hc.count-tile :count="$totals['households']" :label="__('families.tiles.households')" icon="users" :active="$quick === ''" wire:click="setQuick('')"/>
        <x-hc.count-tile :count="$totals['attention']" :label="__('families.tiles.attention')" tone="warn" icon="warning" :active="$quick === 'attention'" wire:click="setQuick('attention')"/>
        <x-hc.count-tile :count="$totals['shared']" :label="__('families.tiles.shared')" tone="info" icon="copy" :active="$quick === 'shared'" wire:click="setQuick('shared')"/>
        <x-hc.count-tile :count="$totals['noworker']" :label="__('families.tiles.noworker')" icon="wallet" :active="$quick === 'noworker'" wire:click="setQuick('noworker')"/>
    </div>
    <p class="fam-summary muted">{{ __('families.summary', ['members' => number_format($totals['members']), 'children' => number_format($totals['children']), 'age' => \App\Services\People\FamilyService::CHILD_AGE]) }}</p>

    <x-hc.card :flush="true">
        <div class="hc-toolbar">
            <div class="hc-field grow">
                <label class="hc-label" for="fam-q">{{ __('hc.search') }}</label>
                {{-- A column pasted from Excel arrives as lines and an input drops line breaks, so they become commas (OR). --}}
                <div style="position:relative" x-data>
                    <input id="fam-q" class="hc-input" type="search" wire:model.live.debounce.350ms="search" placeholder="{{ __('families.search_placeholder') }}" title="{{ __('hc.search_multi_hint') }}" autocomplete="off" style="padding-inline-start:40px"
                           x-on:paste="const t = ($event.clipboardData || window.clipboardData).getData('text'); if (/[\r\n]/.test(t)) { $event.preventDefault(); $el.setRangeText(t.split(/[\r\n]+/).map(s => s.trim()).filter(Boolean).join(', '), $el.selectionStart, $el.selectionEnd, 'end'); $el.dispatchEvent(new Event('input', { bubbles: true })); }">
                    <x-hc.icon name="search" :size="18" style="position:absolute;inset-inline-start:12px;inset-block-start:12px;color:var(--muted)"/>
                </div>
                <span class="hc-hint">{{ __('families.search_hint') }}</span>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="fam-size">{{ __('families.size') }}</label>
                <select class="hc-select" id="fam-size" wire:model.live="size">
                    <option value="">{{ __('families.any_size') }}</option>
                    @foreach(array_keys(\App\Services\People\FamilyService::SIZES) as $key)<option value="{{ $key }}">{{ __('families.sizes.'.$key) }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="fam-sort">{{ __('families.sort') }}</label>
                <select class="hc-select" id="fam-sort" wire:model.live="sort">
                    @foreach(array_keys(\App\Services\People\FamilyService::SORTS) as $key)<option value="{{ $key }}">{{ __('families.sorts.'.$key) }}</option>@endforeach
                </select>
            </div>
        </div>
        <div class="hc-toolbar" style="align-items:center">
            <span class="hc-label">{{ __('hc.cities') }}</span>
            <div class="hc-chips" role="group" aria-label="{{ __('hc.cities') }}">
                @foreach($cityOptions as $c)
                    <button type="button" class="hc-chip" wire:key="city-{{ $c->id }}" wire:click="toggleCity({{ $c->id }})" aria-pressed="{{ in_array((int) $c->id, $chosen, true) ? 'true' : 'false' }}">{{ $en ? $c->name_en : $c->name_ar }} <span class="muted ltr">{{ $c->code }}</span></button>
                @endforeach
            </div>
            <div class="hc-chips" role="group" aria-label="{{ __('families.quick') }}">
                @foreach(['children', 'projects', 'noprojects'] as $key)
                    <button type="button" class="hc-chip" wire:click="setQuick('{{ $key }}')" aria-pressed="{{ $quick === $key ? 'true' : 'false' }}">{{ __('families.quicks.'.$key) }}</button>
                @endforeach
            </div>
            <span class="muted" style="margin-inline-start:auto" aria-live="polite">{{ __('families.results', ['count' => number_format($households->total())]) }}</span>
            @if($search !== '' || $chosen !== [] || $quick !== '' || $size !== '')
                <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('hc.clear') }}</x-hc.button>
            @endif
        </div>

        <div class="hc-table-wrap" wire:loading.class="opacity-60">
        @if($households->isEmpty())
            <x-hc.empty :title="__('hc.no_results')" icon="users">{{ __('families.no_results_hint') }}</x-hc.empty>
        @else
            {{-- Server-side search, sort and pages: the client-side table tools stay off. --}}
            <table class="hc-table fam-table" data-smart="off">
                <thead><tr>
                    <th><span class="sr-only">{{ __('families.show_members') }}</span></th>
                    @foreach($columns as $key => $label)
                        <th scope="col" @class(['num' => ! in_array($key, ['head', 'number'], true)]) aria-sort="{{ $sort === $key ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                            <button type="button" class="hc-sort @if($sort === $key) is-sorted @endif" wire:click="sortBy('{{ $key }}')">
                                <span>{{ $label }}</span><span class="hc-sort-ind" aria-hidden="true">{{ $sort === $key ? ($direction === 'asc' ? '▲' : '▼') : '↕' }}</span>
                            </button>
                        </th>
                    @endforeach
                    <th scope="col">{{ __('people.projects') }}</th>
                </tr></thead>
                @foreach($households as $h)
                @php($url = route('families.show', ['locale' => $locale, 'family' => $h->family_id]).'#h-'.$h->head_id)
                <tbody class="fam-group" wire:key="hh-{{ $h->family_id }}-{{ $h->head_id }}" x-data="{ open: false }" :class="{ 'is-open': open }">
                    <tr class="fam-row">
                        <td class="fam-c-toggle">
                            <button type="button" class="hc-icon-btn" @click="open = !open" :aria-expanded="open" aria-controls="fm-{{ $h->family_id }}-{{ $h->head_id }}" aria-label="{{ __('families.show_members') }}: {{ $h->head_name ?? $h->family_number }}"><x-hc.icon name="chevron" class="fam-chev"/></button>
                        </td>
                        <td class="fam-c-head">
                            <a class="row-link" href="{{ $url }}">{{ $h->head_name ?? __('families.no_head') }}</a>
                            <span class="fam-sub">{{ $en ? $h->city->name_en : $h->city->name_ar }}@if($h->mobile) · <a class="ltr fam-tel" dir="ltr" href="tel:{{ preg_replace('/[^\d+]/', '', $h->mobile) }}">{{ $h->mobile }}</a>@endif</span>
                            @if($h->matched->isNotEmpty())
                                <span class="fam-match">{{ __('families.matched') }} {{ $h->matched->take(3)->pluck('name')->implode('، ') }}</span>
                            @endif
                            @if($h->heads === 0 || $h->incomplete > 0 || $h->counts_differ)
                            <span class="fam-notes">
                                @if($h->heads === 0)<x-hc.badge tone="danger" icon="warning">{{ __('families.no_head_short') }}</x-hc.badge>@endif
                                @if($h->incomplete > 0)<x-hc.badge tone="warn" icon="warning">{{ __('families.incomplete', ['count' => $h->incomplete]) }}</x-hc.badge>@endif
                                @if($h->counts_differ)<x-hc.badge tone="info" icon="info">{{ __('families.counts_differ') }}</x-hc.badge>@endif
                            </span>
                            @endif
                        </td>
                        <td class="fam-c-number" data-label="{{ __('people.family_number') }}">
                            <span class="ltr num">{{ $h->family_number }}</span>
                            @if($h->shared)<x-hc.badge tone="warn" icon="copy">{{ __('families.shared_badge', ['count' => $h->holders]) }}</x-hc.badge>@endif
                        </td>
                        <td class="num fam-c-count" data-label="{{ __('families.members') }}">{{ $h->members }}</td>
                        <td class="num fam-c-count" data-label="{{ __('families.residents') }}">{{ $h->residents }}</td>
                        <td class="num fam-c-count" data-label="{{ __('families.working') }}">{{ $h->working }}</td>
                        <td class="num fam-c-count" data-label="{{ __('families.children') }}">{{ $h->children }}</td>
                        <td class="fam-c-projects" data-label="{{ __('people.projects') }}">
                            @if($h->latest)
                                <x-hc.project-number :code="$h->latest->code" :number="$h->latest->project_number"/>
                                @if($h->projects > 1)<span class="muted num" title="{{ __('families.projects_total', ['count' => $h->projects]) }}">+{{ $h->projects - 1 }}</span>@endif
                                @if($h->latest->state)<x-hc.badge :tone="\App\Support\ProjectStatus::tone($h->latest->state_en)" :icon="\App\Support\ProjectStatus::icon($h->latest->state_en)">{{ $h->latest->state }}</x-hc.badge>@endif
                            @else
                                <span class="muted">{{ __('families.no_projects') }}</span>
                            @endif
                        </td>
                    </tr>
                    <tr class="fam-detail" id="fm-{{ $h->family_id }}-{{ $h->head_id }}" x-show="open" x-cloak>
                        <td colspan="8">
                            <ul class="fam-mini">
                                @foreach($h->people as $m)
                                    <li @class(['is-head' => $m->is_head, 'is-match' => $h->matched->contains('person_id', $m->person_id)])>
                                        <a href="{{ route('people.show', ['locale' => $locale, 'person' => $m->person_id]) }}">{{ $m->name }}</a>
                                        @if($m->is_head)<x-hc.badge tone="brand">{{ __('families.head') }}</x-hc.badge>@else<span class="fam-role">{{ $m->relation ?? __('families.no_relation') }}</span>@endif
                                        <span class="muted">{{ collect([$m->age === null ? null : __('families.age_years', ['age' => $m->age]), $m->state, $m->working ? __('families.works') : null])->filter()->implode(' · ') }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <x-hc.button size="sm" variant="secondary" icon="users" :href="$url">{{ __('families.open') }}</x-hc.button>
                        </td>
                    </tr>
                </tbody>
                @endforeach
            </table>
        @endif
        </div>

        @if($households->total() > \App\Services\People\FamilyService::PER_PAGE[0])
        <div class="hc-pagination">
            <span>{{ __('hc.page', ['page' => $households->currentPage(), 'pages' => $households->lastPage()]) }}</span>
            <label class="hc-per-page"><span>{{ __('hc.per_page') }}</span>
                <select class="hc-select" wire:model.live="perPage">@foreach(\App\Services\People\FamilyService::PER_PAGE as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select>
            </label>
            @if($households->lastPage() > 1)
            <nav aria-label="pagination">
                <x-hc.button variant="secondary" size="sm" wire:click="previousPage" :disabled="$households->onFirstPage()">{{ __('hc.previous') }}</x-hc.button>
                <x-hc.button variant="secondary" size="sm" wire:click="nextPage" :disabled="! $households->hasMorePages()">{{ __('hc.next') }}</x-hc.button>
            </nav>
            @endif
        </div>
        @endif
    </x-hc.card>
</div>
