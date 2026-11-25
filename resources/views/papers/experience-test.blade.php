{{-- M4.20 wave 1: نموذج فحص الخبرة (F06) redrawn to the paper. Filled from the checklist's test when there is one; the nine-stage table stays blank (schema gap G7). --}}
@php
    $L = fn ($key) => __('paper_project.'.$key);
    $t = $d['test'];
    $title = $L('test_title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
    $date = $t['date'] === '' ? '......../......../............' : str_replace('-', '/', $t['date']);
@endphp
<x-paper.document :title="$title" :back="$back">
<x-paper.page :city="$d['city']['code']">
    <div class="p-title">{{ $L('test_title') }}</div>
    <p style="line-height:2;margin:3mm 0">
        {{ $L('test_p1') }} <b>{{ $d['name'] }}</b> {{ $L('test_no') }} <bdi dir="ltr"><b>{{ $d['number'] }}</b></bdi><br>
        {{ $L('test_p2') }} <bdi dir="ltr">{{ $date }}</bdi> {{ $L('test_field') }} <b>{{ $d['career'] }}</b><br>
        {{ $L('test_p3') }} <b>{{ $t['tester'] }}</b><br>
        {{ $L('test_p4') }}
    </p>
    <div class="sub" style="margin-block:4mm 1mm">{{ $L('stages') }}</div>
    <x-paper.grid :columns="['n' => ['#', 8], 'stage' => [$L('stage'), 36], 'able' => [$L('able'), 12], 'unable' => [$L('unable'), 12], 'notes' => [$L('notes'), 32]]" :rows="array_map(fn ($i) => ['n' => $i], range(1, 9))" :min="9"/>
    <p style="margin:5mm 0 3mm"><b>{{ $L('level') }}</b>
        <span class="opt">{{ $L('level_none') }}<i class="rb {{ $t['result'] === 'none' ? 'on' : '' }}"></i></span>
        <span class="opt">{{ $L('level_good') }}<i class="rb {{ $t['result'] === 'good' ? 'on' : '' }}"></i></span></p>
    @foreach(['applicant_notes' => 2, 'expert_notes' => 2, 'extra_notes' => 1] as $key => $n)
        <div class="q">{{ $L($key) }}</div><div class="lined" style="--n:{{ $n }}"></div>
    @endforeach
    <div class="sigs" style="--c:4">
        <div>{{ $L('sig_name') }}<br><b>{{ $L('sig_manager') }}</b></div><div>{{ $L('sig_name') }}<br><b>{{ $L('sig_expert') }}</b></div>
        <div>{{ $L('sig_name') }}<br><b>{{ $L('sig_beneficiary') }}</b></div><div>{{ $L('sig_name') }}<br><b>{{ $L('sig_volunteer') }}</b></div>
    </div>
</x-paper.page>
</x-paper.document>
