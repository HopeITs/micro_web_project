{{-- M4.20 wave 2: كرت الدفعات (F21, Beneficiary Card): front, counter information and the instalment slots, three small cards to cut out. $d comes from FinancePaper. --}}
@php
    $L = fn ($key) => __('paper_finance.'.$key);
    $c = $d['counter'];
    $title = $L('card_title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
    // The back has twelve slots (two columns of six); more instalments make more back cards.
    $slots = $d['slots'] === [] ? [[]] : $d['slots'];
    $backs = array_chunk(array_pad($slots, (int) (ceil(max(1, count($slots)) / 12) * 12), null), 12);
@endphp
<x-paper.document :title="$title" :back="$back">
<style>
    .cards { width: 210mm; margin: 12px auto; background: #fff; padding: 10mm; display: grid; grid-template-columns: repeat(2, 90mm); gap: 6mm 8mm; justify-content: center; box-shadow: 0 1px 6px rgba(0,0,0,.35); }
    .card { width: 90mm; height: 58mm; border: 1px dashed #999; position: relative; padding: 3mm 5mm; overflow: hidden; break-inside: avoid; }
    .card .logo { text-align: center; color: var(--red); font-weight: 700; font-size: 15pt; letter-spacing: .5px; margin-bottom: 3mm; }
    .card .f { margin: 2.2mm 0; }
    .card .site { position: absolute; bottom: 2mm; inset-inline: 0; text-align: center; color: var(--red); font-weight: 700; font-size: 7.5pt; }
    .card .head { text-align: center; font-size: 8pt; color: var(--hint); }
    .card .info { text-align: center; font-size: 8.5pt; line-height: 1.35; }
    .card .info b { display: block; font-weight: 700; }
    .slots { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.4mm 5mm; font-size: 7.6pt; margin-top: 1mm; }
    .slot { display: flex; align-items: flex-end; gap: 1.5mm; border-bottom: 1px dotted #444; min-height: 6.6mm; }
    .slot .rb { flex: none; margin: 0 0 1.2mm; }
    @media print { .cards { width: auto; margin: 0; padding: 0; box-shadow: none; } .card { border-color: #bbb; } }
</style>
<div class="cards">
    <div class="card">
        <div class="logo">HOPE <span style="font-weight:400;font-size:8pt">Christian Hope Center</span></div>
        <x-paper.field :label="$L('card_name')" :value="$d['person']['triple']"/>
        <x-paper.field :label="$L('card_project')" :value="$d['number']"/>
        <x-paper.field :label="$L('card_donor')" :value="$d['donor']"/>
        <x-paper.field :label="$L('card_receipt')" :value="$d['receipt']"/>
        <div class="site">www.hcsyria.org</div>
    </div>
    <div class="card">
        <div class="info">
            <span class="head">{{ $L('card_hours') }}</span><b>{{ $c['hours'] }}</b>
            <span class="head">{{ $L('card_days_off') }}</span><b>{{ $c['days_off'] }}</b>
            <span class="head">{{ $L('card_address') }}</span><b>{{ $c['address'] }}</b>
            <span class="head">{{ $L('card_contact') }}</span><b dir="ltr">{{ $c['contact'] }}</b>
            <span class="head">{{ $L('card_complaints') }}</span><b dir="ltr">{{ $c['complaints'] }}</b>
        </div>
    </div>
    @foreach($backs as $page)
    <div class="card">
        <div class="slots">
            @foreach($page as $slot)
                <div class="slot"><i class="rb {{ ! empty($slot['paid']) ? 'on' : '' }}"></i><span class="num">@if($slot){{ $slot['date'] }} · {{ $slot['amount'] }}@endif</span></div>
            @endforeach
        </div>
    </div>
    @endforeach
</div>
</x-paper.document>
