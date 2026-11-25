{{-- M4.20 wave 4: عقد بيع سيارة قطعي (F16): the paper has no letterhead and no footer. $d comes from SaleContractPaper. --}}
@php
    $L = fn ($key) => __('paper_deeds.car.'.$key);
    $p = $d['party'];
    $title = $L('title').' · '.$d['number'];
    $back = route('applications.sale-contracts', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
    $values = ['subject' => $d['subject'], 'car' => $d['car'], 'price' => $d['price'], 'notice' => $d['notice'], 'handover_on' => $d['handover_on'], 'handover_time' => $d['handover_time']];
@endphp
<x-paper.document :title="$title" :back="$back">
<section class="sheet" style="min-height:275mm;padding-top:10mm">
    <style>
        .deed { font-size: 10.5pt; line-height: 1.85; }
        .deed p { margin: 0; }
        .deed .filled { font-weight: 700; border-bottom: 1px dotted #444; padding-inline: 1mm; }
    </style>
    <div class="p-title" style="margin-bottom:8mm">{{ $L('title') }}</div>
    <div class="deed">
        <p>{{ $L('first') }} <x-paper.fill :value="$d['seller']" :w="150"/></p>
        <p><x-paper.fill :value="$d['seller_details']" :w="180"/></p>
        <p style="margin-top:2mm">{{ $L('second') }} <x-paper.fill :value="$p['name']" :w="44"/> {{ $L('son_of') }} <x-paper.fill :value="$p['father']" :w="24"/> {{ $L('mother') }} <x-paper.fill :value="$p['mother']" :w="24"/> {{ $L('born') }} <x-paper.fill :value="str_replace('-', '/', $p['birth'])" :w="26"/></p>
        <p>{{ $L('registry') }} <x-paper.fill :value="$p['registry']" :w="44"/>
            @if($p['other'] !== '') {{ $p['doc_type'] !== '' ? $p['doc_type'] : __('paper_deeds.document') }} <x-paper.fill :value="$p['doc_number']" :w="48"/> @else {{ $L('national') }} <x-paper.fill :value="$p['national']" :w="48"/> @endif
            {{ $L('issued') }} <x-paper.fill :value="$p['issue']" :w="36"/></p>
        <p>{{ $L('address') }} <x-paper.fill :value="$p['address']" :w="150"/></p>
        <p style="margin-top:5mm"><b>{{ $L('intro_label') }}</b> @include('papers._clause', ['text' => $L('intro'), 'values' => $values])</p>
        <p>{{ $L('wish') }}</p>
        @foreach(__('paper_deeds.car.clauses') as $n => $clause)
            <p><b>{{ $n + 1 }}-</b> @foreach((array) $clause as $line)@if(! $loop->first)<br>@endif @include('papers._clause', ['text' => $line, 'values' => $values])@endforeach</p>
        @endforeach
        @include('papers._contract-signatures')
    </div>
</section>
</x-paper.document>
