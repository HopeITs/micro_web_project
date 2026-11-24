{{-- M4.20 wave 2: طلب صرف (F19, the newer layout with the 10% notes) redrawn to the paper. $d comes from FinancePaper. --}}
@php
    $L = fn ($key) => __('paper_finance.'.$key);
    $p = $d['person']; $r = $d['request'];
    $title = $L('disb_title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
@endphp
<x-paper.document :title="$title" :back="$back">
<x-paper.page :city="$d['city']['code']">
    <div class="p-title">{{ $L('disb_title') }}</div>
    <div class="row"><x-paper.field :label="$L('disb_date')" :value="$r['date']"/><x-paper.field :label="$L('disb_number')" :value="$r['number']"/></div>

    <div class="bar">{{ $L('disb_project') }}</div>
    <div class="row"><x-paper.field :label="$L('disb_name')" :value="$p['triple']"/><x-paper.field :label="$L('disb_name_en')" :value="$p['name_en']"/></div>
    <div class="row"><x-paper.field :label="$L('disb_project_name')" :value="$d['project']->name"/><x-paper.field :label="$L('disb_project_no')" :value="$d['number']"/></div>
    <div class="row"><x-paper.field :label="$L('disb_mobile')" :value="$p['mobile']"/><x-paper.field :label="$L('disb_national')" :value="$p['national'] !== '' ? $p['national'] : $p['doc_number']"/></div>
    <div class="row"><x-paper.field :label="$L('disb_donor')" :value="$d['donor']"/><x-paper.field :label="$L('disb_first')" :value="$d['first_due']"/></div>

    <div class="bar">{{ $L('disb_budget') }}</div>
    <div class="row"><x-paper.field :label="$L('disb_requested')" :value="$d['requested']"/><x-paper.field :label="$L('disb_hold')" :value="$r['hold']"/></div>
    <div class="row"><x-paper.field :label="$L('disb_rate')" :value="$d['rate'] !== '' ? $d['rate'].'%' : ''"/><x-paper.field :label="$L('disb_installment')" :value="$d['installment']"/></div>
    <p class="note">* {{ $L('disb_note1') }}</p>
    <p class="note">* {{ $L('disb_note2') }}</p>
    <div class="sigs" style="--c:2;margin-top:4mm"><div>{{ $L('sig_manager') }}<br><small>{{ $d['manager'] }}</small></div><div>{{ $L('sig_finance') }}<br><small>{{ $d['finance'] }}</small></div></div>

    <div class="bar">{{ $L('disb_extra') }}</div>
    <div class="row" style="--c:3"><x-paper.field :label="$L('disb_extra_amount')" :value="$r['extra']"/><x-paper.field :label="$L('disb_extra_reason')" :value="$r['extra_reason']"/><x-paper.field :label="$L('disb_manager_sig')" :value="''"/></div>

    <div class="bar">{{ $L('disb_invoices') }}</div>
    <x-paper.grid :columns="['amount' => [$L('inv_amount'), 22], 'number' => [$L('inv_number'), 16], 'description' => [$L('inv_description'), 36], 'notes' => [$L('inv_notes'), 26]]" :rows="$d['invoices']" :min="7"/>
    <div class="row" style="--c:1"><x-paper.field :label="$L('disb_total')" :value="$d['invoice_total']"/></div>
    <p class="note">* {{ $L('disb_note3') }}</p>
    <div class="sigs" style="--c:3"><div>{{ $L('sig_manager_short') }}<br><small>{{ $d['manager'] }}</small></div><div>{{ $L('sig_purchasing') }}<br><small>{{ $d['purchasing'] }}</small></div><div>{{ $L('sig_finance_short') }}<br><small>{{ $d['finance'] }}</small></div></div>
</x-paper.page>
</x-paper.document>
