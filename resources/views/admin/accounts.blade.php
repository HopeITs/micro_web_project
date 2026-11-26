@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $cityName = fn ($c) => $c ? ($en ? $c->name_en : $c->name_ar) : '';
    [$sort, $dir] = \App\Support\TableTools\TableSort::clean($filters['sort'] ?? null, $filters['dir'] ?? null, \App\Services\Staff\AccountAdminService::SORT_KEYS);
    $headers = ['username' => __('admin.username'), 'email' => __('admin.email'), 'roles' => __('admin.roles_col'), 'identities' => __('admin.identities'), 'status' => __('admin.status'), 'created' => __('admin.created')];
@endphp
<x-layouts.hc :title="__('admin.title')">
    <div class="hc-page-head">
        <div><p class="hc-eyebrow">{{ __('hc.group_tools') }}</p><h1>{{ __('admin.title') }}</h1><p>{{ __('admin.intro') }}</p></div>
        <x-hc.button icon="plus" :href="route('admin.accounts.new', ['locale' => $locale])">{{ __('admin.new') }}</x-hc.button>
    </div>
    @include('admin._nav')

    <x-hc.card :flush="true">
        @include('partials.filters-style')
        <form class="hc-filters" method="GET" action="{{ route('admin.accounts.index', ['locale' => $locale]) }}"><div class="hc-filters-grid" style="--cols:3">
            @if($sort)<input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">@endif
            <div class="hc-field grow">
                <label class="hc-label" for="q">{{ __('admin.search') }}</label>
                <input id="q" name="q" class="hc-input" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="{{ __('admin.search_placeholder') }}" autocomplete="off">
            </div>
            <div class="hc-field">
                <label class="hc-label" for="city">{{ __('admin.city') }}</label>
                <select id="city" name="city" class="hc-select"><option value="">{{ __('admin.any') }}</option>
                    @foreach($cities as $c)<option value="{{ $c->id }}" @selected((int) ($filters['city'] ?? 0) === (int) $c->id)>{{ $cityName($c) }}</option>@endforeach</select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="role">{{ __('admin.role') }}</label>
                <select id="role" name="role" class="hc-select"><option value="">{{ __('admin.any') }}</option>
                    @foreach(array_keys(\App\Services\AccessRoles::ROLES) as $r)<option value="{{ $r }}" @selected(($filters['role'] ?? '') === $r)>{{ __('admin.bundles.'.$r) }}</option>@endforeach</select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="status">{{ __('admin.status') }}</label>
                <select id="status" name="status" class="hc-select"><option value="">{{ __('admin.any') }}</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('admin.active') }}</option>
                    <option value="disabled" @selected(($filters['status'] ?? '') === 'disabled')>{{ __('admin.disabled') }}</option></select>
            </div>
            <div class="hc-filters-actions"><x-hc.button type="submit" variant="secondary" icon="search">{{ __('admin.filter') }}</x-hc.button></div>
        </div></form>
        <div class="hc-toolbar" style="padding-block:0 12px"><span class="muted" aria-live="polite">{{ __('hc.results', ['count' => number_format($rows->total())]) }}</span></div>

        <div class="hc-table-wrap">
            @if($rows->isEmpty())
                <x-hc.empty :title="__('admin.none')">{{ __('hc.no_results_hint') }}</x-hc.empty>
            @else
            <x-hc.sort-select :columns="\Illuminate\Support\Arr::except($headers, 'roles')" :sort="$sort" :dir="$dir" link/>
            <table class="hc-table stack" data-smart="off">
                <thead><tr>@foreach($headers as $key => $title)@if($key === 'roles')<th>{{ $title }}</th>@else<x-hc.sort-th :column="$key" :label="$title" :sort="$sort" :dir="$dir" :num="$key === 'identities'" link/>@endif @endforeach</tr></thead>
                <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td data-label="{{ __('admin.username') }}"><a class="row-link" href="{{ route('admin.accounts.show', ['locale' => $locale, 'account' => $row->id]) }}"><bdi class="ltr">{{ $row->username }}</bdi></a> @if($row->self)<span class="muted">{{ __('admin.self_mark') }}</span>@endif</td>
                        <td data-label="{{ __('admin.email') }}" class="ltr">{{ $row->email ?: '—' }}</td>
                        <td data-label="{{ __('admin.roles_col') }}">
                            @forelse(collect($row->roles)->groupBy('role') as $role => $items)
                                <x-hc.badge :tone="$role === 'administrator' ? 'brand' : null" title="{{ collect($items)->map(fn ($i) => $cityName($i->city))->implode('، ') }}">{{ __('admin.bundles.'.$role) }} <span class="muted">{{ count($items) === $cities->count() ? __('admin.all_short') : collect($items)->map(fn ($i) => $i->city?->code)->implode(' ') }}</span></x-hc.badge>
                            @empty<span class="muted">{{ __('admin.unassigned') }}</span>@endforelse
                        </td>
                        <td data-label="{{ __('admin.identities') }}" class="num">{{ $row->links }}</td>
                        <td data-label="{{ __('admin.status') }}">@if($row->disabled_at)<x-hc.badge tone="danger">{{ __('admin.disabled') }}</x-hc.badge>@else<x-hc.badge tone="ok">{{ __('admin.active') }}</x-hc.badge>@endif</td>
                        <td data-label="{{ __('admin.created') }}" class="num">{{ substr((string) $row->created_at, 0, 10) }}</td>
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
                @if($rows->onFirstPage())<x-hc.button variant="secondary" size="sm" disabled>{{ __('hc.previous') }}</x-hc.button>@else<x-hc.button variant="secondary" size="sm" :href="$rows->previousPageUrl()">{{ __('hc.previous') }}</x-hc.button>@endif
                @if($rows->hasMorePages())<x-hc.button variant="secondary" size="sm" :href="$rows->nextPageUrl()">{{ __('hc.next') }}</x-hc.button>@else<x-hc.button variant="secondary" size="sm" disabled>{{ __('hc.next') }}</x-hc.button>@endif
            </nav>
        </div>
        @endif
    </x-hc.card>
</x-layouts.hc>
