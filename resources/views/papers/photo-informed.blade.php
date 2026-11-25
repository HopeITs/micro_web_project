{{-- M4.20: Hope Center's informed consent for photos and recordings (F27). The ticks and choices are made by hand; the signed scan is attached. --}}
@php
    $L = fn ($key) => __('paper_consent.informed.'.$key);
    $title = $L('title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
    $children = implode('، ', array_column($d['children'], 'name'));
@endphp
<x-paper.document :title="$title" :back="$back">
<section class="sheet consent" style="min-height:275mm;padding-top:4mm">
    @include('papers._consent-frame')
    <style>
        .informed { font-size: 9.5pt; }
        .informed .box { display: inline-block; width: 3.4mm; height: 3.4mm; border: 1px solid #333; margin-inline-end: 2mm; vertical-align: -.6mm; }
        .informed table { width: 100%; border-collapse: collapse; }
        .informed td { border-bottom: 1px solid #999; padding: .9mm .5mm; vertical-align: middle; }
        .informed .sigs div { padding-bottom: 6mm; }
        .informed td.c { width: 19mm; white-space: nowrap; }
        .informed td.t { width: 38mm; }
    </style>
    <img class="head" src="{{ asset('assets/brand/checklist-header.png') }}" alt="Hope Center">
    <div class="informed" dir="rtl">
        <div class="p-title" style="font-size:14pt">{{ $L('title') }}</div>
        <p>{{ $L('read') }} <b>{{ $L('policy') }}</b><br>{{ $L('agree') }}</p>
        <p style="margin-top:3mm"><span class="box"></span> {{ $L('self') }} @include('papers._filled', ['value' => $d['name'], 'w' => 90])</p>
        <p style="margin-top:3mm"><span class="box"></span> {{ $L('children') }} @include('papers._filled', ['value' => $children, 'w' => 90]) <small>{{ $L('guardian') }}</small></p>
        <p style="margin-top:3mm;border-top:1.5px solid #555;padding-top:1mm"><small>{{ $L('event') }}</small> <b>{{ $d['project_name'] }}</b></p>
        <p>{{ $L('signing') }}</p>
        <table>
            @foreach($L('understand') as $line)<tr><td colspan="3"><span class="box"></span> {{ $line }}</td></tr>@endforeach
            @foreach($L('choices') as $line)<tr><td class="c"><span class="box"></span>{{ $L('yes') }}</td><td class="c"><span class="box"></span>{{ $L('no') }}</td><td>{{ $line }}</td></tr>@endforeach
            <tr><td class="c"><span class="box"></span>{{ $L('want') }}</td><td class="c"><span class="box"></span>{{ $L('not_want') }}</td><td>{{ $L('hide') }}</td></tr>
        </table>
        <p style="margin-top:3mm;border-bottom:1px solid #999">{{ $L('rights') }}</p>
        <p style="border-bottom:1px solid #999">{{ $L('withdraw') }} <b><bdi dir="ltr">{{ $L('email') }}</bdi></b> {{ $L('withdraw_end') }}</p>
        <div class="sigs" style="--c:2;margin-top:4mm"><div>{{ $L('name') }}<br><small>{{ $d['name'] }}</small></div><div style="border:0"></div></div>
        <div class="sigs" style="--c:2;margin-top:2mm"><div>{{ $L('signature') }}</div><div>{{ $L('date_place') }}<br><small>{{ $d['place'] }}</small></div></div>
    </div>
</section>
</x-paper.document>
