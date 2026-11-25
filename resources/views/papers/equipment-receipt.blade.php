{{-- M4.20 wave 3: تعهد استلام المعدات المطلوبة (F20) redrawn to the paper: the framed box holds the project's purchases and suppliers. --}}
@php
    $L = fn ($key) => __('paper_purchase.'.$key);
    $title = $L('receipt_title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
@endphp
<x-paper.document :title="$title" :back="$back">
<x-paper.page :city="$d['city']['code']">
    <div class="p-title">{{ $L('receipt_title') }}</div>
    <div style="border:1px solid var(--line);min-height:96mm;padding:2mm;margin:0 6mm">
        <x-paper.grid :columns="['name' => [$L('product'), 40], 'brand' => [$L('brand'), 20], 'quantity' => [$L('quantity'), 12], 'trader' => [$L('trader'), 28]]" :rows="$d['items']" :min="12"/>
    </div>
    <div style="font-size:11pt;line-height:1.9;margin-top:5mm">
        <p style="margin:0">{{ $L('receipt_declare') }}</p>
        <p class="q">{{ $L('receipt_undertake') }}</p>
        <ul style="margin:1mm 0">
            <li>{{ $L('receipt_u1') }}</li>
            <li>{{ $L('receipt_u2') }}</li>
        </ul>
        <div class="row" style="--c:1;margin-top:4mm;width:62%"><x-paper.field :label="$L('receipt_name')" :value="$d['beneficiary']"/></div>
        <div class="row" style="--c:1;width:62%"><x-paper.field :label="$L('signature')" :value="''"/></div>
        <div class="row" style="--c:1;width:62%"><x-paper.field :label="$L('date')" :value="$d['received_on']"/></div>
    </div>
    <div class="sigs" style="--c:2"><div>{{ $L('sign_projects') }}<br><small>{{ $d['manager'] }}</small></div><div>{{ $L('sign_purchasing') }}<br><small>{{ $d['officer'] }}</small></div></div>
</x-paper.page>
</x-paper.document>
