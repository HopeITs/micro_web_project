@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $selectedCities = array_map('intval', $filters['cities'] ?? []);
@endphp
<x-layouts.hc :title="__('field.meeting.report')">
    <div class="hc-page-head"><div><h1>{{ __('field.meeting.report') }}</h1><p>{{ __('field.meeting.report_intro') }}</p></div>
        <x-hc.button variant="ghost" icon="arrow" :href="route('meetings.index', ['locale' => $locale])">{{ __('field.meeting.back') }}</x-hc.button></div>
    <x-hc.card style="margin-block-end:16px">
        <form method="GET" action="{{ route('meetings.report', ['locale' => $locale]) }}" class="hc-toolbar" style="align-items:end;flex-wrap:wrap;gap:12px;padding:0">
            <div class="hc-field"><label class="hc-label" for="r-year">{{ __('field.meeting.year') }}</label>
                <select id="r-year" class="hc-select" name="year" onchange="this.form.submit()"><option value="">{{ __('staff.tasks.any') }}</option>@foreach($years as $y)<option value="{{ $y }}" @selected((string) ($filters['year'] ?? '') === (string) $y)>{{ $y }}</option>@endforeach</select></div>
            <div class="hc-field"><label class="hc-label" for="r-career">{{ __('field.meeting.career') }}</label>
                <select id="r-career" class="hc-select" name="career" onchange="this.form.submit()"><option value="">{{ __('staff.tasks.any') }}</option>@foreach($careers as $c)<option value="{{ $c->id }}" @selected((string) ($filters['career'] ?? '') === (string) $c->id)>{{ $en && $c->name_en ? $c->name_en : $c->name_ar }}</option>@endforeach</select></div>
            @if($permitted->count() > 1)
                <fieldset class="hc-chips" style="border:0;padding:0;margin:0"><legend class="sr-only">{{ __('projects.city_filter') }}</legend>
                    @foreach($permitted as $c)<label class="hc-chip"><input type="checkbox" name="cities[]" value="{{ $c->id }}" onchange="this.form.submit()" @checked(in_array((int) $c->id, $selectedCities, true))> {{ $en ? $c->name_en : $c->name_ar }}</label>@endforeach
                </fieldset>
            @endif
        </form>
    </x-hc.card>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:12px;margin-block-end:16px">
        <x-hc.stat :label="__('field.meeting.stat_meetings')" :value="number_format($totals->meetings)"/>
        <x-hc.stat :label="__('field.meeting.invited')" :value="number_format($totals->invited)"/>
        <x-hc.stat :label="__('field.meeting.attendance')" :value="number_format($totals->attending)"/>
        <x-hc.stat :label="__('field.meeting.absent')" :value="number_format($totals->absent)"/>
    </div>
    @if($drafts > 0)<x-hc.alert type="info" style="margin-block-end:16px">{{ __('field.meeting.drafts_note', ['count' => $drafts]) }}</x-hc.alert>@endif

    <x-hc.card style="margin-block-end:16px"><h2 class="hc-h2">{{ __('field.meeting.per_career') }}</h2>
        @if($perCareer->isEmpty())<p class="muted">{{ __('field.meeting.report_none') }}</p>@else
        <div class="hc-table-wrap"><table class="hc-table"><thead><tr><th>{{ __('field.meeting.career') }}</th><th>{{ __('field.meeting.stat_meetings') }}</th><th>{{ __('field.meeting.attendance') }}</th></tr></thead>
            <tbody>@foreach($perCareer as $r)<tr><td>{{ $r->label }}</td><td class="num">{{ $r->meetings }}</td><td class="num">{{ $r->attending }}</td></tr>@endforeach</tbody></table></div>@endif
    </x-hc.card>

    <x-hc.card style="margin-block-end:16px"><h2 class="hc-h2">{{ __('field.meeting.interests') }}</h2><p class="muted">{{ __('field.meeting.interests_hint') }}</p>
        @forelse($interests as $i)
            <details style="margin-block-end:8px"><summary><strong>{{ $i->number }}. {{ $i->label }}</strong> — <span class="num">{{ $i->count }}</span></summary>
                <div class="hc-table-wrap"><table class="hc-table"><thead><tr><th>{{ __('field.meeting.name') }}</th><th>{{ __('field.meeting.speciality') }}</th><th>{{ __('field.meeting.project') }}</th><th>{{ __('staff.tasks.city') }}</th><th>{{ __('field.meeting.date') }}</th></tr></thead>
                    <tbody>@foreach($i->people as $p)<tr><td>{{ $p->name }}</td><td>{{ $p->speciality ?: '—' }}</td><td class="num ltr">{{ $p->project ?? '—' }}</td><td>{{ $p->city }}</td><td class="num ltr"><a href="{{ route('meetings.show', ['locale' => $locale, 'meeting' => $p->meeting]) }}">{{ $p->date }}</a></td></tr>@endforeach</tbody></table></div></details>
        @empty<p class="muted">{{ __('field.meeting.report_none') }}</p>@endforelse
    </x-hc.card>

    <x-hc.card><h2 class="hc-h2">{{ __('field.meeting.answers') }}</h2>
        @foreach($questions as $q)
            <details style="margin-block-end:8px"><summary><strong>{{ $q->number }}. {{ $q->label }}</strong></summary>
                <div class="hc-table-wrap"><table class="hc-table"><thead><tr><th>{{ __('field.meeting.option') }}</th><th>{{ __('field.meeting.stat_meetings') }}</th>@if($q->counted)<th>{{ __('field.meeting.persons') }}</th>@endif</tr></thead>
                    <tbody>@foreach($q->options as $o)<tr><td>{{ $o->label }}</td><td class="num">{{ $o->meetings }}</td>@if($q->counted)<td class="num">{{ $o->persons }}</td>@endif</tr>@endforeach</tbody></table></div></details>
        @endforeach
    </x-hc.card>
</x-layouts.hc>
