{{-- M4.20 wave 3: مرتجع معدات وبضائع (F22) redrawn to the paper: twenty numbered lines. $d comes from PurchasePaper. --}}
@php
    $L = fn ($key) => __('paper_purchase.'.$key);
    $title = $L('return_title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
    $rows = $d['items'];
    for ($n = count($rows) + 1; $n <= 20; $n++) { $rows[] = ['no' => $n]; }
@endphp
<x-paper.document :title="$title" :back="$back">
<x-paper.page :city="$d['city']['code']">
    <div class="p-title">{{ $L('return_title') }}</div>
    <div style="font-size:10.5pt">
        <div class="row"><x-paper.field :label="$L('return_name')" :value="$d['beneficiary']"/><x-paper.field :label="$L('return_type')" :value="$d['type']"/></div>
        <div class="row"><x-paper.field :label="$L('return_number')" :value="$d['number']"/><x-paper.field :label="$L('return_date')" :value="$d['returned_on']"/></div>
    </div>
    <x-paper.grid :columns="['no' => [$L('return_count_col'), 9], 'name' => [$L('kind'), 19], 'brand' => [$L('brand'), 18], 'serial' => [$L('serial'), 18], 'state' => [$L('technical'), 18], 'notes' => [$L('return_notes'), 18]]" :rows="$rows" :min="20"/>
    <div class="sigs" style="--c:2;margin-top:6mm"><div>{{ $L('return_count') }}<br><small>{{ $d['count'] }}</small></div><div>{{ $L('sign_beneficiary') }}</div></div>
    <div class="sigs" style="--c:2;margin-top:6mm"><div>{{ $L('sign_purchasing') }}<br><small>{{ $d['officer'] }}</small></div><div>{{ $L('sign_projects') }}<br><small>{{ $d['manager'] }}</small></div></div>
</x-paper.page>
</x-paper.document>
