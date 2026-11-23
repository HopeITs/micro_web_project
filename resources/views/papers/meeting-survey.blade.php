{{-- M4.18: استبيان لقاء أصحاب المهن, filled from a final meeting: one sheet per section, the answers ticked or counted as on the form. --}}
@php
    $L = fn ($key) => __('paper_meeting.'.$key);
    $s = $d['state'];
    $en = $d['en'];
    $title = $L('survey_title').' · '.$d['city']['name'].' · '.$d['date'];
    $back = route('meetings.show', ['locale' => app()->getLocale(), 'meeting' => $d['meeting']]);
    $name = fn ($o) => $en && $o->name_en ? $o->name_en : $o->name_ar;
    $levels = [1 => __('field.meeting.level.1'), 2 => __('field.meeting.level.2'), 3 => __('field.meeting.level.3')];
    $filled = fn (array $rows) => array_values(array_filter($rows, fn ($r) => implode('', array_map('strval', $r)) !== ''));
@endphp
<x-paper.document :title="$title" :back="$back">
@foreach($d['sections'] as $i => $section)
<x-paper.page :city="$d['city']['code']">
    @if($i === 0)
        <div class="p-title">{{ $L('survey_title') }}</div>
        <div class="row" style="--c:1"><x-paper.field :label="$L('careers')" :value="implode('، ', $d['careers'])"/></div>
        <div class="row" style="--c:3"><x-paper.field :label="$L('date')" :value="$d['date']"/><x-paper.field :label="$L('place')" :value="$d['place']"/><x-paper.field :label="$L('attendance')" :value="$d['attendance']"/></div>
        <div class="row"><x-paper.field :label="$L('paper_registrar')" :value="$d['paper_registrar']"/><x-paper.field :label="$L('program_registrar')" :value="$d['program_registrar']"/></div>
    @endif
    <div class="bar">{{ $section->label }}</div>
    @foreach($section->questions as $q)
        <div class="q">{{ $q->number }}. {{ $q->label }}</div>
        @if(in_array($q->answer_type, ['option_number', 'multi_option'], true))
            @foreach($q->options as $o)
                @php($a = $s['opt'][$o->id] ?? [])
                @php($mark = $q->answer_type === 'option_number' ? (string) ($a['n'] ?? '') : (! empty($a['on']) ? '✓' : ''))
                <div class="ask"><span>{{ $name($o) }}@if(($a['text'] ?? '') !== '') — {{ $a['text'] }}@endif</span><span class="num" style="min-width:12mm;text-align:center;border:1px solid var(--line)">{{ $mark }}</span></div>
            @endforeach
        @elseif($q->answer_type === 'text')
            <div class="lined" style="--n:2">{{ $s['tx'][$q->id] ?? '' }}</div>
        @elseif($q->answer_type === 'competition')
            <x-paper.grid :columns="['level' => [$L('competition'), 30], 'n' => [$L('count'), 15], 'reason' => [$L('reason'), 55]]" :rows="collect($levels)->map(fn ($label, $k) => ['level' => $label, 'n' => $s['comp'][$k]['n'] ?? '', 'reason' => $s['comp'][$k]['reason'] ?? ''])->values()->all()" :min="3"/>
        @elseif($q->answer_type === 'season_table')
            <x-paper.grid :columns="['n' => [$L('count'), 15], 'from' => [$L('from'), 20], 'to' => [$L('to'), 20], 'reason' => [$L('reason'), 45]]" :rows="$filled($s['seasons'])" :min="2"/>
        @elseif($q->answer_type === 'brand_table')
            <x-paper.grid :columns="['brand' => $L('brand')]" :rows="array_map(fn ($b) => ['brand' => $b], array_values(array_filter(preg_split('/\R/u', (string) $s['brands']))))" :min="2"/>
        @elseif($q->answer_type === 'equipment_table')
            <x-paper.grid :columns="['spec' => [$L('spec'), 35], 'items' => [$L('items'), 65]]" :rows="$filled($s['equipment'])" :min="2"/>
        @elseif($q->answer_type === 'challenge_table')
            <x-paper.grid :columns="['name' => [$L('challenge'), 35], 'n' => [$L('count'), 15], 'solution' => [$L('solution'), 50]]" :rows="array_values(array_filter($s['challenges'], fn ($r) => ($r['n'] ?? '') !== '' || ($r['solution'] ?? '') !== ''))" :min="2"/>
        @elseif($q->answer_type === 'participant_table')
            <x-paper.grid :columns="['project' => [$L('project'), 18], 'name' => [$L('name'), 50], 'speciality' => [$L('speciality'), 32]]" :rows="$s['part'][$q->id] ?? []" :min="2"/>
        @endif
    @endforeach
</x-paper.page>
@endforeach
</x-paper.document>
