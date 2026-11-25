{{-- M4.20 wave 3: قائمة عرض أسعار (F08) redrawn to the paper. $d comes from PurchasePaper; the trader completes it by hand. --}}
@php
    $L = fn ($key) => __('paper_purchase.'.$key);
    $title = $L('quote_title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
    $columns = ['name' => ['product', 28], 'kind' => ['kind', 14], 'quantity' => ['quantity_asked', 13], 'price' => ['price', 14], 'total' => ['line_total', 16], 'notes' => ['notes', 15]];
@endphp
<x-paper.document :title="$title" :back="$back">
<x-paper.page :city="$d['city']['code']">
    <style>
        table.g.quote th { background: var(--bar); color: #fff; height: 9mm; }
        table.g.quote td { height: 7.4mm; }
        table.g.quote tfoot td { border: 0; }
        table.g.quote tfoot td.sum { border: 1px solid var(--line); height: 13mm; font-weight: 700; }
    </style>
    <div class="p-title">{{ $L('quote_title') }}</div>
    <div style="font-size:11pt">
        <div class="row" style="grid-template-columns:3fr 2fr"><x-paper.field :label="$L('quote_supplier')" :value="$d['supplier']"/><x-paper.field :label="$L('quote_date')" :value="$d['quoted_on']"/></div>
        <div class="row" style="grid-template-columns:3fr 2fr"><x-paper.field :label="$L('quote_to')" :value="$d['beneficiary']"/></div>
    </div>
    <table class="g quote" style="margin-top:6mm">
        <thead><tr>@foreach($columns as [$label, $width])<th style="width:{{ $width }}%">{{ $L($label) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach($d['items'] as $row)<tr>@foreach($columns as $key => $column)<td>{{ $row[$key] }}</td>@endforeach</tr>@endforeach
        @for($i = count($d['items']); $i < 11; $i++)<tr>@foreach($columns as $column)<td></td>@endforeach</tr>@endfor
        </tbody>
        <tfoot><tr><td colspan="3"></td><td class="sum">{{ $L('grand_total') }}</td><td class="sum total">{{ $d['grand_total'] }}</td><td class="sum"></td></tr></tfoot>
    </table>
    <p style="text-align:center;font-size:10.5pt;margin-top:6mm"><b>{{ $L('quote_note_label') }}</b> {{ $L('quote_note') }}</p>
    <div class="sigs" style="--c:2;margin-top:24mm;padding-inline:30mm"><div>{{ $L('trader_signature') }}</div><div style="border-bottom:0">{{ $L('stamp') }}</div></div>
</x-paper.page>
</x-paper.document>
