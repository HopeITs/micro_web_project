{{-- M4.20 wave 1: Special Case (F05) redrawn to the paper; filled from the chosen case, blank lines without one. --}}
@php
    $L = fn ($key) => __('paper_project.'.$key);
    $c = $d['case'];
    $title = 'Special Case · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $d['projectId']]);
@endphp
<x-paper.document :title="$title" :back="$back">
<x-paper.page :city="$d['city']['code']">
    <div class="p-title" style="direction:ltr">Special Case</div>
    <div class="row"><x-paper.field :label="$L('full_name')" :value="$d['name']"/><x-paper.field :label="$L('mobile')" :value="$d['mobile']"/></div>
    <div class="row"><x-paper.field :label="$L('project_no')" :value="$d['number']"/><x-paper.field :label="$L('recorder')" :value="$c->recorder ?? ''"/></div>
    <div class="row" style="--c:1"><div class="f"><span class="l">{{ $L('review_type') }}</span><span class="opts"><x-paper.opts :options="['interview' => $L('interview'), 'phone' => $L('phone')]" :chosen="$d['mode']"/></span></div></div>
    <div class="bar">{{ $L('reason') }}</div>
    <div class="lined" style="--n:3">{{ $c->reason ?? '' }}</div>
    <div class="bar">{{ $L('details') }}</div>
    <div class="lined" style="--n:4">{{ $c->description ?? '' }}</div>
    <div class="sigs" style="--c:2"><div>{{ $L('sig_beneficiary') }}</div><div>{{ $L('sig_recorder') }}</div></div>
    <div class="bar">{{ $L('action') }}</div>
    <div class="lined" style="--n:6">{{ $c->action ?? '' }}</div>
    <div class="sigs" style="--c:2"><div style="border:0">{{ $L('date') }}: <bdi dir="ltr" style="font-weight:500">{{ $c->date ?? '' }}</bdi></div><div>{{ $L('sig_manager') }}</div></div>
</x-paper.page>
</x-paper.document>
