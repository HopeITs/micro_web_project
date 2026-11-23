{{-- M4.18: محضر اجتماع لقاء المهن, filled from a final meeting. $d comes from MeetingPaper. --}}
@php
    $L = fn ($key) => __('paper_meeting.'.$key);
    $s = $d['state'];
    $title = $L('minutes_title').' · '.$d['city']['name'].' · '.$d['date'];
    $back = route('meetings.show', ['locale' => app()->getLocale(), 'meeting' => $d['meeting']]);
    $att = array_map(fn ($r) => ['project' => $r['project'], 'name' => $r['name'], 'speciality' => $r['speciality']], $s['attendees']);
@endphp
<x-paper.document :title="$title" :back="$back">
<x-paper.page :city="$d['city']['code']">
    <div class="p-title">{{ $L('minutes_title') }}</div>
    <div class="row" style="--c:1"><x-paper.field :label="$L('careers')" :value="implode('، ', $d['careers'])"/></div>
    <div class="row"><x-paper.field :label="$L('date')" :value="$d['date']"/><x-paper.field :label="$L('place')" :value="$d['place']"/></div>
    <div class="row" style="--c:3"><x-paper.field :label="$L('invited')" :value="$d['invited']"/><x-paper.field :label="$L('attendance')" :value="$d['attendance']"/><x-paper.field :label="$L('absence')" :value="$d['absence']"/></div>
    <div class="row"><x-paper.field :label="$L('paper_registrar')" :value="$d['paper_registrar']"/><x-paper.field :label="$L('program_registrar')" :value="$d['program_registrar']"/></div>

    <div class="bar">{{ $L('attendees') }}</div>
    <x-paper.grid :columns="['project' => [$L('project'), 18], 'name' => [$L('name'), 50], 'speciality' => [$L('speciality'), 32]]" :rows="$att" :min="5"/>

    @foreach($d['sections'] as $section)
        <div class="bar">{{ $section->label }}</div>
        <div class="lined" style="--n:4">{{ (string) ($s['minutes'][$section->id] ?? '') }}</div>
    @endforeach

    <div class="bar">{{ $L('notes') }}</div>
    <div class="lined" style="--n:4">{{ $s['notes'] }}</div>
</x-paper.page>
</x-paper.document>
