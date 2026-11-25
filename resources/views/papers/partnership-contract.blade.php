{{-- M4.20 wave 2: عقد شركة محاصة (F18) redrawn to the paper, two sheets. $d comes from FinancePaper. --}}
@php
    $L = fn ($key) => __('paper_finance.'.$key);
    $p = $d['person']; $rep = $d['rep']; $c = $d['contract'];
    $title = $L('pc_title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
    $dots = fn ($v, $w = 30) => new \Illuminate\Support\HtmlString('<span class="fill" style="min-width:'.$w.'mm">'.e($v).'</span>');
    $terms = array_map(fn ($i) => $L('pc_special_'.$i), range(1, 8));
@endphp
<x-paper.document :title="$title" :back="$back">
<x-paper.page :city="$d['city']['code']">
    <div class="p-title">{{ $L('pc_title') }}</div>
    <div class="legal">
        <p><b>{{ $L('pc_first') }}</b> {{ $L('mr') }} <x-paper.fill :value="$rep['name']" :w="44"/> @include('papers._identity', ['i' => $rep])</p>
        <p><b>{{ $L('pc_second') }}</b> {{ $L('mr') }} <x-paper.fill :value="$p['name']" :w="44"/> @include('papers._identity', ['i' => $p])</p>
        <p><b>{{ $L('pc_address') }}</b> <x-paper.fill :value="$c['address']" :w="110"/></p>
        <p style="margin-top:4mm"><b><u>{{ $L('pc_intro_title') }}</u></b></p>
        <p>{{ $L('pc_intro_1') }} <x-paper.fill :value="$c['work']" :w="50"/> {{ $L('pc_intro_2') }}</p>
        <p><b>1-</b> {{ $L('pc_1') }}</p>
        <p><b>2-</b> {{ $L('pc_2') }} <x-paper.fill :value="$c['purpose']" :w="90"/></p>
        <p><b>3-</b> {{ $L('pc_3') }} <x-paper.fill :value="$c['equipment']" :w="48"/> {{ $L('pc_3b') }}</p>
        <p><b>4-</b> {{ $L('pc_4') }}</p>
        <p><b>5-</b> {{ $L('pc_5a') }} <x-paper.fill :value="$c['profit_period']" :w="40"/> {{ $L('pc_5b') }}</p>
        <p><b>6-</b> {{ $L('pc_6a') }} <x-paper.fill :value="$c['duration']" :w="26"/> {{ $L('pc_6b') }} <x-paper.fill :value="$c['starts']" :w="30"/> {{ $L('pc_6c') }} <x-paper.fill :value="$c['ends']" :w="30"/> {{ $L('pc_6d') }}</p>
        <p><b>7-</b> {{ $L('pc_7') }}</p>
        <p><b>8-</b> {{ $L('pc_8') }}</p>
    </div>
</x-paper.page>
<x-paper.page :city="$d['city']['code']">
    <div class="legal">
        <p><b>9- {{ $L('pc_special') }}</b></p>
        @foreach($terms as $t)<p>- {{ $t }}</p>@endforeach
        <p><b>10-</b> {{ $L('pc_10') }} {{ $d['city']['name'] }}</p>
        <p><b>11-</b> {{ $L('pc_11') }}</p>
        <p><b>12-</b> {{ $L('pc_12') }}</p>
    </div>
    <div class="sigs" style="--c:4;margin-top:12mm"><div style="border:0">{{ $L('witness') }}</div><div style="border:0">{{ $L('witness') }}</div><div style="border:0">{{ $L('pc_first_sig') }}</div><div style="border:0">{{ $L('pc_second_sig') }}</div></div>
    <p class="note" style="margin-top:10mm">{{ $d['place'] }} · <bdi dir="ltr">{{ $d['date'] }}</bdi></p>
</x-paper.page>
</x-paper.document>
