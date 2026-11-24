{{-- M4.20 wave 2: سند أمانة (F10) redrawn to the paper. $d comes from FinancePaper. --}}
@php
    $L = fn ($key) => __('paper_finance.'.$key);
    $p = $d['person']; $rep = $d['rep']; $a = $d['amount'];
    $title = $L('note_title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
@endphp
<x-paper.document :title="$title" :back="$back">
<x-paper.page :city="$d['city']['code']">
    <div class="p-title">{{ $L('note_title') }}</div>
    <div class="legal">
        <p>{{ $L('note_i') }} <x-paper.fill :value="$p['name']" :w="48"/> @include('papers._identity', ['i' => $p])</p>
        <p style="margin-top:6mm">{{ $L('note_admit') }} <x-paper.fill :value="$rep['name']" :w="48"/> @include('papers._identity', ['i' => $rep, 'mother' => true])</p>
        <p>{{ $L('note_amount') }} <x-paper.fill :value="trim($a['figures'].' '.$a['currency'])" :w="40"/> <x-paper.fill :value="$a['words']" :w="80"/></p>
        <p>{{ $L('note_body') }}</p>
        <p>{{ $L('note_consider') }} <x-paper.fill :value="$p['address']" :w="90"/> {{ $L('note_notice') }} {{ $L('note_sign') }}</p>
    </div>
    <div class="sigs" style="--c:3;margin-top:14mm"><div style="border:0">{{ $L('witness') }}</div><div style="border:0">{{ $L('witness') }}</div><div style="border:0">{{ $L('acknowledged') }}</div></div>
    <p class="note" style="margin-top:10mm">{{ $d['place'] }} · <bdi dir="ltr">{{ $d['date'] }}</bdi></p>
</x-paper.page>
</x-paper.document>
