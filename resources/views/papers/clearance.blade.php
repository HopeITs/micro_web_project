{{-- M4.20 wave 2: صك براءة ذمّة (F23) redrawn to the paper. $d comes from FinancePaper. --}}
@php
    $L = fn ($key) => __('paper_finance.'.$key);
    $p = $d['person']; $rep = $d['rep']; $a = $d['amount'];
    $title = $L('clear_title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
@endphp
<x-paper.document :title="$title" :back="$back">
<x-paper.page :city="$d['city']['code']">
    <div class="p-title">{{ $L('clear_title') }}</div>
    <div class="legal">
        <p>{{ $L('clear_we') }} <b>{{ $L('centre_ar') }}</b> <b>(Christian Hope Center)</b> {{ $L('clear_acting') }} <x-paper.fill :value="$rep['name']" :w="48"/> @include('papers._identity', ['i' => $rep])</p>
        <p style="margin-top:3mm">{{ $L('clear_free') }} <x-paper.fill :value="$p['name']" :w="48"/> @include('papers._identity', ['i' => $p])</p>
        <p>{{ $L('clear_loan') }} <x-paper.fill :value="trim($a['figures'].' '.$a['currency'])" :w="50"/></p>
        <p><x-paper.fill :value="$a['words']" :w="110"/></p>
        <p>{{ $L('clear_body') }}</p>
    </div>
    <div class="sigs" style="--c:2;margin-top:10mm"><div style="border:0"><b>{{ $L('centre_ar') }}</b><br><b>(Christian Hope Center)</b><br>{{ $L('acting') }}<br><x-paper.fill :value="$rep['name']" :w="50"/></div><div style="border:0"><x-paper.fill :value="$p['name']" :w="50"/></div></div>
    <p class="note" style="margin-top:10mm">{{ $d['place'] }} · <bdi dir="ltr">{{ $d['date'] }}</bdi></p>
</x-paper.page>
</x-paper.document>
