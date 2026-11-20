<div>
    @php($en = app()->getLocale() === 'en')
    <x-hc.card :flush="true">
        <div class="hc-toolbar">
            <div class="hc-field grow">
                <label class="hc-label" for="q">{{ __('hc.search') }}</label>
                <div style="position:relative">
                    <input id="q" class="hc-input" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('hc.search_placeholder') }}" autocomplete="off" style="padding-inline-start:40px">
                    <x-hc.icon name="search" :size="18" style="position:absolute;inset-inline-start:12px;inset-block-start:12px;color:var(--muted)"/>
                </div>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="stage">{{ __('hc.stage') }}</label>
                <select id="stage" class="hc-select" wire:model.live="stage">
                    <option value="">{{ __('hc.all') }}</option>
                    <option value="preliminary">{{ __('hc.stage_preliminary') }}</option>
                    <option value="full">{{ __('hc.stage_full') }}</option>
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="state">{{ __('hc.state') }}</label>
                <select id="state" class="hc-select" wire:model.live="state">
                    <option value="">{{ __('hc.any_state') }}</option>
                    @foreach($stateOptions as $s)<option value="{{ $s->id }}">{{ $en && $s->name_en ? $s->name_en : $s->name_ar }}</option>@endforeach
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
            <span class="muted" style="margin-inline-start:auto" aria-live="polite">{{ __('hc.results', ['count' => number_format($projects->total())]) }}</span>
            @if($search !== '' || $cities !== [] || $stage !== '' || $state !== '')
                <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('hc.clear') }}</x-hc.button>
            @endif
        </div>

        <div class="hc-table-wrap" wire:loading.class="opacity-60">
            @if($projects->isEmpty())
                <x-hc.empty :title="__('hc.no_results')">{{ __('hc.no_results_hint') }}</x-hc.empty>
            @else
            <table class="hc-table stack">
                <thead><tr><th>{{ __('hc.number') }}</th><th>{{ __('hc.beneficiary') }}</th><th>{{ __('hc.project') }}</th><th>{{ __('hc.city') }}</th><th>{{ __('hc.stage') }}</th><th>{{ __('hc.state') }}</th><th>{{ __('hc.request_date') }}</th><th class="num">{{ __('hc.requested') }}</th></tr></thead>
                <tbody>
                @foreach($projects as $p)
                    @php($stateName = $en && $p->state_en ? $p->state_en : $p->state_ar)
                    @php($tone = \App\Support\ProjectStatus::tone($p->state_en))
                    <tr wire:key="p-{{ $p->id }}">
                        <td data-label="{{ __('hc.number') }}"><a class="row-link" href="{{ route('projects.show', ['locale' => app()->getLocale(), 'project' => $p->id]) }}"><x-hc.project-number :code="$p->city_code" :number="$p->project_number"/></a></td>
                        <td data-label="{{ __('hc.beneficiary') }}">{{ trim($p->first_name.' '.$p->father_name.' '.$p->last_name) ?: __('hc.not_set') }}</td>
                        <td data-label="{{ __('hc.project') }}">{{ $p->name ?: __('hc.not_set') }}</td>
                        <td data-label="{{ __('hc.city') }}">{{ $en ? $p->city_en : $p->city_ar }}</td>
                        <td data-label="{{ __('hc.stage') }}"><x-hc.badge :tone="$p->application_stage === 'full' ? 'brand' : null">{{ __('hc.stage_'.$p->application_stage) }}</x-hc.badge></td>
                        <td data-label="{{ __('hc.state') }}">@if($stateName)<x-hc.badge :tone="$tone" :icon="\App\Support\ProjectStatus::icon($p->state_en)">{{ $stateName }}</x-hc.badge>@else{{ __('hc.not_set') }}@endif</td>
                        <td data-label="{{ __('hc.request_date') }}" class="num">{{ $p->request_date?->format('Y-m-d') ?? __('hc.not_set') }}</td>
                        <td data-label="{{ __('hc.requested') }}" class="num">{{ $p->requested_amount !== null ? number_format((float) $p->requested_amount) : __('hc.not_set') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>

        @if($projects->lastPage() > 1)
        <div class="hc-pagination">
            <span>{{ __('hc.page', ['page' => $projects->currentPage(), 'pages' => $projects->lastPage()]) }}</span>
            <nav aria-label="pagination">
                <x-hc.button variant="secondary" size="sm" wire:click="previousPage" :disabled="$projects->onFirstPage()">{{ __('hc.previous') }}</x-hc.button>
                <x-hc.button variant="secondary" size="sm" wire:click="nextPage" :disabled="! $projects->hasMorePages()">{{ __('hc.next') }}</x-hc.button>
            </nav>
        </div>
        @endif
    </x-hc.card>
</div>
