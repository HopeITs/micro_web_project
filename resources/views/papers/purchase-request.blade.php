{{-- M4.20 wave 3: طلب مشتريات (F09) redrawn to the paper. $d comes from PurchasePaper. --}}
@php
    $L = fn ($key) => __('paper_purchase.'.$key);
    $title = $L('request_title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
    // The paper numbers its fifteen lines, filled or not.
    $rows = $d['items'];
    for ($n = count($rows) + 1; $n <= 15; $n++) { $rows[] = ['no' => $n]; }
@endphp
<x-paper.document :title="$title" :back="$back">
<x-paper.page :city="$d['city']['code']">
    <div class="p-title">{{ $L('request_title') }}</div>
    <div style="font-size:11pt">
        <div class="row" style="--c:1"><x-paper.field :label="$L('request_intro')" :value="$d['number']"/></div>
        <div class="row"><x-paper.field :label="$L('request_for')" :value="$d['beneficiary']"/><x-paper.field :label="$L('request_phone')" :value="$d['mobile']"/></div>
        <div class="row" style="grid-template-columns:2fr 3fr"><x-paper.field :label="$L('request_trade')" :value="$d['trade']"/><x-paper.field :label="$L('request_area')" :value="$d['area']"/></div>
    </div>
    <x-paper.grid :columns="['no' => [$L('no'), 9], 'material' => [$L('material'), 42], 'kind' => [$L('kind'), 21], 'quantity' => [$L('quantity'), 12], 'state' => [$L('material_state'), 16]]" :rows="$rows" :min="15"/>
    <p class="q">{{ $L('request_undertake') }}</p>
    <ul style="margin:1mm 0;font-size:10.5pt;line-height:1.8">
        <li>{{ $L('request_u1') }}</li>
        <li>{{ $L('request_u2') }}</li>
        <li>{{ $L('request_u3') }}</li>
    </ul>
    <div class="row" style="--c:1;font-size:11pt;margin-top:5mm"><x-paper.field :label="$L('request_signature')" :value="$d['beneficiary']"/></div>
    <div class="sigs" style="--c:3">
        @foreach(['manager' => 'sig_projects', 'finance' => 'sig_finance', 'officer' => 'sig_purchasing'] as $key => $label)<div>{{ $L($label) }}<br><small>{{ $d[$key] }}</small></div>@endforeach
    </div>
</x-paper.page>
</x-paper.document>
