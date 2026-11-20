<div>
    @php($en = app()->getLocale() === 'en')
    <x-hc.card :flush="true">
        <div class="hc-toolbar">
            <div class="hc-field grow">
                <label class="hc-label" for="q">{{ __('hc.search') }}</label>
                <div style="position:relative">
                    <input id="q" class="hc-input" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('people.search_placeholder') }}" autocomplete="off" style="padding-inline-start:40px">
                    <x-hc.icon name="search" :size="18" style="position:absolute;inset-inline-start:12px;inset-block-start:12px;color:var(--muted)"/>
                </div>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="projects">{{ __('people.projects_filter') }}</label>
                <select id="projects" class="hc-select" wire:model.live="projects">
                    <option value="">{{ __('hc.all') }}</option>
                    <option value="with">{{ __('people.with_projects') }}</option>
                    <option value="without">{{ __('people.without_projects') }}</option>
                </select>
            </div>
        </div>
        <div class="hc-toolbar" style="align-items:center">
            <span class="hc-label">{{ __('hc.cities') }}</span>
            <div class="hc-chips" role="group" aria-label="{{ __('hc.cities') }}">
                @foreach($cityOptions as $c)
                    <button type="button" class="hc-chip" wire:click="toggleCity({{ $c->id }})" aria-pressed="{{ in_array($c->id, $cities, true) ? 'true' : 'false' }}">{{ $en ? $c->name_en : $c->name_ar }} <span class="muted ltr">{{ $c->code }}</span></button>
                @endforeach
            </div>
            <span class="muted" style="margin-inline-start:auto" aria-live="polite">{{ __('hc.results', ['count' => number_format($people->total())]) }}</span>
            @if($search !== '' || $cities !== [] || $projects !== '')
                <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('hc.clear') }}</x-hc.button>
            @endif
        </div>

        <div class="hc-table-wrap" wire:loading.class="opacity-60">
            @if($people->isEmpty())
                <x-hc.empty :title="__('hc.no_results')">{{ __('people.no_results_hint') }}</x-hc.empty>
            @else
            <table class="hc-table stack">
                <thead><tr><th>{{ __('people.name') }}</th><th>{{ __('people.fields.national_number') }}</th><th>{{ __('people.birth_year') }}</th><th>{{ __('people.fields.mobile') }}</th><th>{{ __('people.family_number') }}</th><th>{{ __('hc.city') }}</th><th class="num">{{ __('people.projects') }}</th></tr></thead>
                <tbody>
                @foreach($people as $p)
                    <tr wire:key="person-{{ $p->id }}">
                        <td data-label="{{ __('people.name') }}"><a class="row-link" href="{{ route('people.show', ['locale' => app()->getLocale(), 'person' => $p->id]) }}">{{ \App\Services\People\PeopleService::fullName($p) }}</a></td>
                        <td data-label="{{ __('people.fields.national_number') }}" class="num ltr">{{ $p->national_number ?: '—' }}</td>
                        <td data-label="{{ __('people.birth_year') }}" class="num">{{ $p->birth_date ? substr($p->birth_date, 0, 4) : '—' }}</td>
                        <td data-label="{{ __('people.fields.mobile') }}" class="num ltr">{{ $p->mobile ?: '—' }}</td>
                        <td data-label="{{ __('people.family_number') }}" class="ltr">{{ $p->family_number ?: '—' }}</td>
                        <td data-label="{{ __('hc.city') }}">{{ $en ? $p->city_en : $p->city_ar }}</td>
                        <td data-label="{{ __('people.projects') }}" class="num">@if($p->projects)<x-hc.badge tone="brand">{{ $p->projects }}</x-hc.badge>@else<span class="muted">0</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>

        @if($people->lastPage() > 1)
        <div class="hc-pagination">
            <span>{{ __('hc.page', ['page' => $people->currentPage(), 'pages' => $people->lastPage()]) }}</span>
            <nav aria-label="pagination">
                <x-hc.button variant="secondary" size="sm" wire:click="previousPage" :disabled="$people->onFirstPage()">{{ __('hc.previous') }}</x-hc.button>
                <x-hc.button variant="secondary" size="sm" wire:click="nextPage" :disabled="! $people->hasMorePages()">{{ __('hc.next') }}</x-hc.button>
            </nav>
        </div>
        @endif
    </x-hc.card>
</div>
