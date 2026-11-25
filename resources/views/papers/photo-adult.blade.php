{{-- M4.20: the Caritas Polska photo and voice consent of an adult (F24), Polish beside Arabic. $d comes from ProjectPaper. --}}
@php
    $L = fn ($key) => __('paper_consent.adult.'.$key);
    $pl = $L('pl');
    $title = $L('title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
@endphp
<x-paper.document :title="$title" :back="$back">
<section class="sheet consent" style="min-height:275mm;padding-top:6mm">
    @include('papers._consent-frame')
    <img class="head" src="{{ asset('assets/brand/caritas-polska-header.png') }}" alt="Hope Center · Caritas Polska">
    {{-- Arabic on the right, Polish on the left, whatever the page language. --}}
    <div class="cols" dir="rtl">
        <div class="ar"><h3>{{ $L('title') }}</h3></div>
        <div class="pl"><h3>{{ $pl['title'] }}</h3></div>
        <div class="ar">
            <p>{{ $L('i') }} @include('papers._filled', ['value' => $d['name']])</p>
            <p>{{ $L('agree') }} ({{ $L('address_label') }} <b><bdi dir="ltr">{{ __('paper_consent.address') }}</bdi></b>) {{ $L('use') }}</p>
            <p>{{ $L('cases') }}</p>
            <ol>@foreach($L('uses') as [$lead, $rest])<li><b>{{ $lead }}</b>{{ $rest }}</li>@endforeach</ol>
        </div>
        <div class="pl" lang="pl">
            <p><b>{{ $pl['i'] }}</b> @include('papers._filled', ['value' => $d['name_latin']])</p>
            <p>{{ $pl['agree'] }}</p>
            <ol>@foreach($pl['uses'] as $use)<li>{{ $use }}</li>@endforeach</ol>
        </div>
        <div class="ar">@foreach($L('after') as $line)<p>{{ $line }}</p>@endforeach</div>
        <div class="pl" lang="pl">@foreach($pl['after'] as $line)<p>{{ $line }}</p>@endforeach</div>
    </div>
    <div class="sigs" style="--c:3;margin-top:3mm" dir="rtl">
        <div>{{ $L('name') }}<br><small>{{ $d['name'] }}</small></div><div>{{ $L('date') }}</div><div>{{ $L('signature') }}</div>
    </div>
</section>
</x-paper.document>
