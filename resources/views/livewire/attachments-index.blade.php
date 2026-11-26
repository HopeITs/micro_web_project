<div>
    @php($en = app()->getLocale() === 'en')
    @php($locale = app()->getLocale())
    @include('partials.filters-style')
    <x-hc.card :flush="true">
        <div class="hc-filters"><div class="hc-filters-grid" style="--cols:3">
            <div class="hc-field grow">
                <label class="hc-label" for="q">{{ __('hc.search') }}</label>
                <div style="position:relative">
                    <input id="q" class="hc-input" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('attachments.search_placeholder') }}" autocomplete="off" style="padding-inline-start:40px">
                    <x-hc.icon name="search" :size="18" style="position:absolute;inset-inline-start:12px;inset-block-start:12px;color:var(--muted)"/>
                </div>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="category">{{ __('attachments.category') }}</label>
                <select id="category" class="hc-select" wire:model.live="category">
                    <option value="">{{ __('hc.all') }}</option>
                    @foreach($categoryOptions as $c)<option value="{{ $c }}">{{ __('attachments.categories.'.$c) }}</option>@endforeach
                </select>
            </div>
        </div></div>
        <div class="hc-toolbar" style="align-items:center">
            <span class="hc-label">{{ __('hc.cities') }}</span>
            <div class="hc-chips" role="group" aria-label="{{ __('hc.cities') }}">
                @foreach($cityOptions as $c)
                    <button type="button" class="hc-chip" wire:click="toggleCity({{ $c->id }})" aria-pressed="{{ in_array($c->id, $cities, true) ? 'true' : 'false' }}">{{ $en ? $c->name_en : $c->name_ar }} <span class="muted ltr">{{ $c->code }}</span></button>
                @endforeach
            </div>
            <span class="muted" style="margin-inline-start:auto" aria-live="polite">{{ __('hc.results', ['count' => number_format($rows->total())]) }}</span>
            @if($search !== '' || $cities !== [] || $category !== '')
                <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('hc.clear') }}</x-hc.button>
            @endif
        </div>

        <div class="hc-table-wrap" wire:loading.class="opacity-60">
            @if($rows->isEmpty())
                <x-hc.empty :title="__('hc.no_results')" icon="file">{{ __('attachments.no_results_hint') }}</x-hc.empty>
            @else
            @php($headers = ['name' => __('attachments.name'), 'type' => __('attachments.type'), 'owner' => __('attachments.owner'), 'city' => __('hc.city'), 'uploaded' => __('attachments.uploaded')])
            <x-hc.sort-select :columns="$headers" :sort="$sortKey" :dir="$sortDir"/>
            <table class="hc-table stack" data-smart="off">
                <thead><tr>@foreach($headers as $key => $title)<x-hc.sort-th :column="$key" :label="$title" :sort="$sortKey" :dir="$sortDir"/>@endforeach<th></th></tr></thead>
                <tbody>
                @foreach($rows as $r)
                    @php($url = route('attachments.file', ['locale' => $locale, 'attachment' => $r->id]))
                    <tr wire:key="a-{{ $r->id }}">
                        <td data-label="{{ __('attachments.name') }}" style="overflow-wrap:anywhere">
                            @if($r->available)<a class="row-link" href="{{ $url }}" target="_blank" rel="noopener" dir="auto">{{ $r->original_name ?: '#'.$r->id }}</a>
                            @else<span dir="auto">{{ $r->original_name ?: '#'.$r->id }}</span> <x-hc.badge tone="warn" title="{{ __('attachments.not_moved_hint') }}">{{ __('attachments.not_moved') }}</x-hc.badge>@endif
                        </td>
                        <td data-label="{{ __('attachments.type') }}">{{ $r->type_label ?? __('attachments.uncategorised') }}</td>
                        <td data-label="{{ __('attachments.owner') }}">
                            @if($r->project_id)
                                <a class="row-link" href="{{ route('projects.show', ['locale' => $locale, 'project' => $r->project_id]) }}#attachments"><x-hc.project-number :code="$r->city_code" :number="$r->project_number"/></a>
                                @if($r->project_name)<span class="muted"> · {{ $r->project_name }}</span>@endif
                            @else
                                <a class="row-link" href="{{ route('people.show', ['locale' => $locale, 'person' => $r->person_id]) }}#attachments">{{ \App\Services\People\PeopleService::fullName($r) }}</a>
                            @endif
                        </td>
                        <td data-label="{{ __('hc.city') }}">{{ $en ? $r->city_en : $r->city_ar }}</td>
                        <td data-label="{{ __('attachments.uploaded') }}"><span class="num">{{ $r->uploaded_at ? substr((string) $r->uploaded_at, 0, 10) : '—' }}</span>@if($r->uploader) <span class="muted">· {{ $r->uploader }}</span>@endif</td>
                        <td>@if($r->available)<x-hc.button size="sm" variant="ghost" icon="install" :href="$url.'?download=1'">{{ __('attachments.download') }}</x-hc.button>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>

        @if($rows->lastPage() > 1)
        <div class="hc-pagination">
            <span>{{ __('hc.page', ['page' => $rows->currentPage(), 'pages' => $rows->lastPage()]) }}</span>
            <nav aria-label="pagination">
                <x-hc.button variant="secondary" size="sm" wire:click="previousPage" :disabled="$rows->onFirstPage()">{{ __('hc.previous') }}</x-hc.button>
                <x-hc.button variant="secondary" size="sm" wire:click="nextPage" :disabled="! $rows->hasMorePages()">{{ __('hc.next') }}</x-hc.button>
            </nav>
        </div>
        @endif
    </x-hc.card>
</div>
