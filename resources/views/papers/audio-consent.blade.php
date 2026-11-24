{{-- M4.20 wave 1: موافقة تسجيل صوتي (F04) redrawn to the paper: one sheet, filled with the beneficiary's name, the city and the print date. --}}
@php
    $L = fn ($key) => __('paper_project.'.$key);
    $title = $L('audio_title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
@endphp
<x-paper.document :title="$title" :back="$back">
<x-paper.page :city="$d['city']['code']">
    <div class="p-title">{{ $L('audio_title') }}</div>
    <p style="font-size:11pt;line-height:2;text-align:justify;margin:8mm 0">
        {{ $L('audio_intro') }} <span style="display:inline-block;min-width:60mm;border-bottom:1px dotted #444;font-weight:500;text-align:center">{{ $d['name'] }}</span>
        {{ $L('audio_body') }}
    </p>
    <div class="sigs" style="--c:2;margin-top:18mm">
        <div style="border:0">{{ $d['place'] }}<br><bdi dir="ltr" style="font-weight:500">{{ $d['date'] }}</bdi></div>
        <div>{{ $L('signature') }}</div>
    </div>
</x-paper.page>
</x-paper.document>
