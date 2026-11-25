{{-- M4.20 wave 4: عقد بيع وشراء (F17) redrawn to the paper. $d comes from SaleContractPaper. --}}
@php
    $L = fn ($key) => __('paper_deeds.sale.'.$key);
    $p = $d['party'];
    $title = $L('title').' · '.$d['number'];
    $back = route('applications.sale-contracts', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
    $values = ['price' => $d['price'], 'court' => $d['court']];
    // The second party's line: the identity as far as it is known.
    $identity = $p['name'] === '' ? '' : collect([
        $p['name'], $p['father'] !== '' ? __('paper_finance.son_of').' '.$p['father'] : '', $p['mother'] !== '' ? __('paper_finance.and_mother').' '.$p['mother'] : '',
        $p['birth'] !== '' ? __('paper_finance.born').' '.str_replace('-', '/', $p['birth']) : '', $p['registry'] !== '' ? __('paper_finance.registry').' '.$p['registry'] : '',
        $p['other'] !== '' ? trim(($p['doc_type'] !== '' ? $p['doc_type'] : __('paper_finance.document')).' '.$p['doc_number']) : ($p['national'] !== '' ? __('paper_finance.national').' '.$p['national'] : ''),
        $p['other'] === '' && $p['issue'] !== '' ? __('paper_finance.issued').' '.$p['issue'] : '',
    ])->filter()->implode(' ');
@endphp
<x-paper.document :title="$title" :back="$back">
<x-paper.page :city="$d['city']['code']">
    <style>
        .deed { font-size: 11pt; line-height: 1.95; }
        .deed p { margin: 0; }
        .deed .filled { font-weight: 700; border-bottom: 1px dotted #444; padding-inline: 1mm; }
    </style>
    <div class="p-title" style="margin-bottom:7mm">{{ $L('title') }}</div>
    <div class="deed">
        <p>{{ $L('first') }} <x-paper.fill :value="$d['seller']" :w="150"/></p>
        <p><x-paper.fill :value="$d['seller_details']" :w="180"/></p>
        <p style="margin-top:2mm">{{ $L('second') }} <x-paper.fill :value="$identity" :w="150"/></p>
        <p><x-paper.fill :value="$p['address']" :w="180"/></p>
        <p style="margin-top:3mm"><b>{{ $L('capacity') }}</b></p>
        <p>• {{ $L('subject') }}</p>
        <div class="lined" style="--n:3">{{ $d['subject'] }}</div>
        <p style="margin-top:2mm">{{ $L('to') }}</p>
        @foreach(__('paper_deeds.sale.clauses') as $n => [$label, $text])
            <p><b>{{ $n + 1 }}- {{ $label }}</b> @include('papers._clause', ['text' => $text, 'values' => $values])</p>
        @endforeach
        @include('papers._contract-signatures')
    </div>
</x-paper.page>
</x-paper.document>
