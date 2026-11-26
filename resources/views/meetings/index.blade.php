@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $selectedCities = array_map('intval', $filters['cities'] ?? []);
    [$sort, $dir] = \App\Support\TableTools\TableSort::clean($filters['sort'] ?? null, $filters['dir'] ?? null, \App\Services\Field\ProfessionMeetingService::SORT_KEYS);
    $headers = ['date' => __('field.meeting.date'), 'career' => __('field.meeting.career'), 'city' => __('staff.tasks.city'), 'place' => __('field.meeting.place'),
        'invited' => __('field.meeting.invited'), 'attendance' => __('field.meeting.attendance'), 'absent' => __('field.meeting.absent'), 'state' => __('field.meeting.state')];
@endphp
<x-layouts.hc :title="__('field.meeting.title')">
    <div class="hc-page-head"><div><h1>{{ __('field.meeting.title') }}</h1><p>{{ __('field.meeting.intro') }}</p></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <x-hc.button variant="secondary" icon="chart" :href="route('meetings.report', ['locale' => $locale])">{{ __('field.meeting.report') }}</x-hc.button>
            @foreach($creatable as $c)<x-hc.button icon="plus" :href="route('meetings.create', ['locale' => $locale, 'city' => $c->id])">{{ __('field.meeting.new') }}@if($creatable->count() > 1) · {{ $en ? $c->name_en : $c->name_ar }}@endif</x-hc.button>@endforeach
        </div></div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
    <x-hc.card :flush="true">
        <x-hc.table-search list="meetings" :value="$filters['q']" :placeholder="__('tables.meetings_placeholder')"/>
        <form method="GET" action="{{ route('meetings.index', ['locale' => $locale]) }}" class="hc-toolbar" style="align-items:end;flex-wrap:wrap;gap:12px">
            @if($sort)<input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">@endif
            <div class="hc-field"><label class="hc-label" for="f-year">{{ __('field.meeting.year') }}</label>
                <select id="f-year" class="hc-select" name="year" onchange="this.form.submit()"><option value="">{{ __('staff.tasks.any') }}</option>@foreach($years as $y)<option value="{{ $y }}" @selected((string) ($filters['year'] ?? '') === (string) $y)>{{ $y }}</option>@endforeach</select></div>
            <div class="hc-field"><label class="hc-label" for="f-career">{{ __('field.meeting.career') }}</label>
                <select id="f-career" class="hc-select" name="career" onchange="this.form.submit()"><option value="">{{ __('staff.tasks.any') }}</option>@foreach($careers as $c)<option value="{{ $c->id }}" @selected((string) ($filters['career'] ?? '') === (string) $c->id)>{{ $en && $c->name_en ? $c->name_en : $c->name_ar }}</option>@endforeach</select></div>
            <div class="hc-field"><label class="hc-label" for="f-state">{{ __('field.meeting.state') }}</label>
                <select id="f-state" class="hc-select" name="state" onchange="this.form.submit()"><option value="">{{ __('staff.tasks.any') }}</option><option value="draft" @selected(($filters['state'] ?? '') === 'draft')>{{ __('field.meeting.state_draft') }}</option><option value="final" @selected(($filters['state'] ?? '') === 'final')>{{ __('field.meeting.state_final') }}</option></select></div>
            @if($cities->count() > 1)
                <fieldset class="hc-chips" style="border:0;padding:0;margin:0"><legend class="sr-only">{{ __('projects.city_filter') }}</legend>
                    @foreach($cities as $c)<label class="hc-chip"><input type="checkbox" name="cities[]" value="{{ $c->id }}" onchange="this.form.submit()" @checked(in_array((int) $c->id, $selectedCities, true))> {{ $en ? $c->name_en : $c->name_ar }}</label>@endforeach
                </fieldset>
            @endif
            <span class="muted" style="margin-inline-start:auto">{{ __('field.meeting.count_label', ['count' => number_format($rows->total())]) }}</span>
        </form>
        @if($rows->total() === 0)
            <x-hc.empty :title="__('field.meeting.empty')" icon="meeting"/>
        @else
            <div class="hc-table-wrap"><table class="hc-table" data-smart="off">
                <thead><tr>@foreach($headers as $key => $title)<x-hc.sort-th :column="$key" :label="$title" :sort="$sort" :dir="$dir" link/>@endforeach</tr></thead>
                <tbody>@foreach($rows as $r)
                    <tr><td class="num ltr"><a href="{{ route('meetings.show', ['locale' => $locale, 'meeting' => $r->id]) }}">{{ substr((string) $r->meeting_date, 0, 10) }}</a></td>
                        <td>{{ implode('، ', $r->careers) ?: '—' }}</td><td>{{ $r->city }}</td><td>{{ $r->place ?: '—' }}</td>
                        <td class="num">{{ $r->invited_count ?? '—' }}</td><td class="num">{{ $r->attendance_count ?? '—' }}</td><td class="num">{{ $r->absence_count ?? '—' }}</td>
                        <td><x-hc.badge>{{ $r->record_state === 'final' ? __('field.meeting.state_final') : __('field.meeting.state_draft') }}</x-hc.badge></td></tr>
                @endforeach</tbody>
            </table></div>
            @if($rows->lastPage() > 1)
            <div class="hc-pagination"><span>{{ __('hc.page', ['page' => $rows->currentPage(), 'pages' => $rows->lastPage()]) }}</span>
                <div style="display:flex;gap:8px">@if(! $rows->onFirstPage())<x-hc.button variant="secondary" size="sm" :href="$rows->appends(request()->query())->previousPageUrl()">{{ __('hc.previous') }}</x-hc.button>@endif
                    @if($rows->hasMorePages())<x-hc.button variant="secondary" size="sm" :href="$rows->appends(request()->query())->nextPageUrl()">{{ __('hc.next') }}</x-hc.button>@endif</div></div>
            @endif
        @endif
    </x-hc.card>
</x-layouts.hc>
