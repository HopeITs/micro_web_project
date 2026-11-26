@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $selectedCities = array_map('intval', $filters['cities'] ?? []);
    $canSend = app(\App\Services\Staff\NotificationInboxService::class)->sendCities(auth()->user())->isNotEmpty();
    $kinds = ['message', 'visit', 'clearance', 'payment', 'trophy', 'executive_file', 'state', 'purchases'];
    [$sort, $dir] = \App\Support\TableTools\TableSort::clean($filters['sort'] ?? null, $filters['dir'] ?? null, \App\Services\Staff\NotificationInboxService::SORT_KEYS);
@endphp
<x-layouts.hc :title="__('staff.notifications.title')">
    <div class="hc-page-head"><div><h1>{{ __('staff.notifications.title') }}</h1><p>{{ __('staff.notifications.intro') }}</p></div>
        @if($canSend)<x-hc.button icon="mail" :href="route('notifications.create', ['locale' => $locale])">{{ __('staff.notifications.send_title') }}</x-hc.button>@endif
    </div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
    <x-hc.card :flush="true">
        <x-hc.table-search list="notifications" :value="$filters['q']" :placeholder="__('tables.notifications_placeholder')"/>
        <form method="GET" action="{{ route('notifications.index', ['locale' => $locale]) }}" class="hc-toolbar" style="align-items:center;flex-wrap:wrap;gap:12px">
            @if($sort)<input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">@endif
            <div class="hc-field"><label class="hc-label" for="n-kind">{{ __('staff.notifications.kind') }}</label>
                <select id="n-kind" class="hc-select" name="kind" onchange="this.form.submit()"><option value="">{{ __('staff.tasks.any') }}</option>
                    @foreach($kinds as $k)<option value="{{ $k }}" @selected(($filters['kind'] ?? '') === $k)>{{ __('staff.notifications.kinds.'.$k) }}</option>@endforeach</select></div>
            @if($cities->count() > 1)
                <fieldset class="hc-chips" style="border:0;padding:0;margin:0"><legend class="sr-only">{{ __('projects.city_filter') }}</legend>
                    @foreach($cities as $c)<label class="hc-chip"><input type="checkbox" name="cities[]" value="{{ $c->id }}" onchange="this.form.submit()" @checked(in_array((int) $c->id, $selectedCities, true))> {{ $en ? $c->name_en : $c->name_ar }}</label>@endforeach
                </fieldset>
            @endif
            <span class="muted" style="margin-inline-start:auto">{{ __('staff.notifications.count', ['count' => number_format($rows->total())]) }}</span>
            <x-hc.button variant="ghost" size="sm" icon="file" :href="route('notifications.export', ['locale' => $locale, 'format' => 'xlsx'] + request()->except('format'))">Excel</x-hc.button>
            <x-hc.button variant="ghost" size="sm" icon="file" :href="route('notifications.export', ['locale' => $locale, 'format' => 'csv'] + request()->except('format'))">CSV</x-hc.button>
        </form>
        <form method="POST" action="{{ route('notifications.seen', ['locale' => $locale]) }}">@csrf
            @if($rows->count() === 0)
                <x-hc.empty :title="__('staff.notifications.empty')" icon="bell"/>
            @else
            <div class="hc-table-wrap"><table class="hc-table" data-smart="off">
                <thead><tr><th>{{ __('staff.notifications.mark') }}</th>
                    <x-hc.sort-th column="project" :label="__('staff.notifications.project')" :sort="$sort" :dir="$dir" link/>
                    <x-hc.sort-th column="kind" :label="__('staff.notifications.kind')" :sort="$sort" :dir="$dir" link/>
                    <x-hc.sort-th column="title" :label="__('staff.notifications.about')" :sort="$sort" :dir="$dir" link/>
                    <th>{{ __('staff.notifications.body') }}</th>
                    <x-hc.sort-th column="date" :label="__('staff.notifications.date')" :sort="$sort" :dir="$dir" link/>
                    <x-hc.sort-th column="sender" :label="__('staff.notifications.sent_by')" :sort="$sort" :dir="$dir" link/>
                    <th>{{ __('staff.notifications.open') }}</th></tr></thead>
                <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="{{ $row->id }}" aria-label="{{ __('staff.notifications.mark') }}: {{ $row->title ?: $row->body }}" style="width:22px;height:22px"></td>
                        <td>@if($row->project_id)<a class="num ltr" href="{{ route('projects.show', ['locale' => $locale, 'project' => $row->project_id]) }}">{{ $row->project_code }}-{{ $row->project_number }}</a>@else —@endif<div class="muted">{{ $en ? $row->city_name_en : $row->city_name_ar }}</div></td>
                        <td>{{ __('staff.notifications.kinds.'.$row->kind) }}</td>
                        <td>{{ $row->title ?: '—' }}</td>
                        <td style="white-space:pre-line">{{ $row->amount ?? $row->body }}</td>
                        <td class="num ltr">{{ substr((string) $row->notice_date, 0, 10) }}</td>
                        <td>{{ $row->sender ?: '—' }}</td>
                        <td>@if($row->project_id && $row->links['finance'])<a href="{{ route('finance.show', ['locale' => $locale, 'project' => $row->project_id]) }}">{{ __('staff.notifications.finance') }}</a> @endif
                            @if($row->project_id && $row->links['visits'])<a href="{{ route('visits.hub', ['locale' => $locale, 'project' => $row->project_id]) }}">{{ __('staff.notifications.visits') }}</a>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="hc-toolbar"><x-hc.button type="submit" icon="check">{{ __('staff.notifications.mark_seen') }}</x-hc.button></div>
            @endif
        </form>
        @if($rows->lastPage() > 1)
        <div class="hc-pagination">
            <span>{{ __('hc.page', ['page' => $rows->currentPage(), 'pages' => $rows->lastPage()]) }}</span>
            <div style="display:flex;gap:8px">
                @if(! $rows->onFirstPage())<x-hc.button variant="secondary" size="sm" :href="$rows->previousPageUrl()">{{ __('hc.previous') }}</x-hc.button>@endif
                @if($rows->hasMorePages())<x-hc.button variant="secondary" size="sm" :href="$rows->nextPageUrl()">{{ __('hc.next') }}</x-hc.button>@endif
            </div>
        </div>
        @endif
    </x-hc.card>
</x-layouts.hc>
