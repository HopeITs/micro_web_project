{{-- M4.20: the Caritas Polska consent for a child (F25), Polish beside Arabic: one sheet for each child of the family under eighteen, or one blank sheet. --}}
@php
    $L = fn ($key) => __('paper_consent.child.'.$key);
    $pl = $L('pl');
    $title = $L('title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
    $children = $d['children'] === [] ? [['name' => '', 'name_latin' => '']] : $d['children'];
@endphp
<x-paper.document :title="$title" :back="$back">
@foreach($children as $child)
<section class="sheet consent" style="min-height:275mm;padding-top:6mm">
    @include('papers._consent-frame')
    <img class="head" src="{{ asset('assets/brand/caritas-polska-header.png') }}" alt="Hope Center · Caritas Polska">
    <div class="cols" dir="rtl">
        <div class="ar"><h3>{{ $L('title') }}</h3></div>
        <div class="pl"><h3>{{ $pl['title'] }}</h3></div>
        <div class="ar"><h3>{{ $L('controller') }}</h3></div>
        <div class="pl"><h3>{{ $pl['controller'] }}</h3></div>
        <div class="ar">
            <p>{{ $L('i') }} @include('papers._filled', ['value' => $d['name']]) {{ $L('as') }} @include('papers._filled', ['value' => $child['name']])</p>
            <p>{{ $L('agree') }} {{ $L('address_label') }} <b><bdi dir="ltr">{{ __('paper_consent.address') }}</bdi></b> {{ $L('use') }}</p>
            <p>{{ $L('for') }} @include('papers._filled', ['value' => $child['name']]) {{ $L('cases') }}</p>
            <ol>@foreach($L('uses') as [$lead, $rest])<li><b>{{ $lead }}</b>{{ $rest }}</li>@endforeach</ol>
        </div>
        <div class="pl" lang="pl">
            <p>{{ $pl['as'] }} @include('papers._filled', ['value' => $d['name_latin']]) {{ $pl['i'] }}</p>
            <p>@include('papers._filled', ['value' => $child['name_latin']]) {{ $pl['representative'] }}</p>
            <p>{{ $pl['agree'] }}</p>
            <p>{{ $pl['form'] }} @include('papers._filled', ['value' => $child['name_latin']])</p>
            <p>{{ $pl['rest'] }}</p>
            <ol>@foreach($pl['uses'] as $use)<li>{{ $use }}</li>@endforeach</ol>
        </div>
        <div class="ar">@foreach($L('after') as $line)<p>{{ $line }}</p>@endforeach</div>
        <div class="pl" lang="pl">@foreach($pl['after'] as $line)<p>{{ $line }}</p>@endforeach</div>
    </div>
    <div class="sigs" style="--c:3;margin-top:3mm" dir="rtl">
        <div>{{ $L('date') }}</div><div>{{ $L('name') }}<br><small>{{ $d['name'] }}</small></div><div>{{ $L('signature') }}</div>
    </div>
</section>
@endforeach
</x-paper.document>
