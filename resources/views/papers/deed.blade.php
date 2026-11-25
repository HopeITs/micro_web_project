{{-- M4.20 wave 4: صك إقرار وتعهد (F11–F15), one sheet each, redrawn word for word. $d comes from DeedPaper. --}}
@php
    $L = fn ($key) => __('paper_deeds.'.$key);
    $p = $d['person'];
    $title = $L('deed_title').' · '.$d['number'];
    $back = route('applications.deeds', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
    // The non-Syrian copies carry two free lines in place of the labelled identity.
    $other = $p['other'] !== '';
@endphp
<x-paper.document :title="$title" :back="$back">
<x-paper.page :city="$d['city']['code']">
    <style>
        .deed { font-size: 10.2pt; line-height: 1.72; }
        .deed p { margin: 0; text-align: justify; }
        .deed .filled { font-weight: 700; border-bottom: 1px dotted #444; padding-inline: 1mm; }
        .deed .who { margin-bottom: 1mm; }
    </style>
    <div class="p-title">{{ $L('deed_title') }}</div>
    <div class="deed">
        <div class="who">
            <p>{{ $L('signer') }} <x-paper.fill :value="$p['name']" :w="52"/> {{ $L('son_of') }} <x-paper.fill :value="$p['father']" :w="24"/> {{ $L('mother') }} <x-paper.fill :value="$p['mother']" :w="24"/> {{ $L('born') }} <x-paper.fill :value="str_replace('-', '/', $p['birth'])" :w="26"/></p>
            @if($other)
                <p>{{ $p['doc_type'] !== '' ? $p['doc_type'] : $L('document') }} {{ $L('document_number') }} <x-paper.fill :value="$p['doc_number']" :w="60"/> {{ $L('issued') }} <x-paper.fill :value="$p['issue']" :w="50"/></p>
            @else
                <p>{{ $L('registry') }} <x-paper.fill :value="$p['registry']" :w="44"/> {{ $L('national') }} <x-paper.fill :value="$p['national']" :w="58"/> {{ $L('issued') }} <x-paper.fill :value="$p['issue']" :w="38"/></p>
            @endif
        </div>
        <p><b>{{ $L('pledge') }}</b></p>
        @foreach(__('paper_deeds.'.$d['kind']) as $n => $clause)
            <p><b>{{ $n + 1 }}-</b> @foreach((array) $clause as $line)@if(! $loop->first)<br>@endif @include('papers._clause', ['text' => $line, 'values' => $d['values']])@endforeach</p>
        @endforeach
        <p style="text-align:end;padding-inline-end:48mm;margin-top:3mm">{{ $L('acknowledged') }}</p>
    </div>
</x-paper.page>
</x-paper.document>
