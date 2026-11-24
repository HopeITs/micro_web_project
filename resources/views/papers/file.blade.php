{{-- M4.18: the whole beneficiary file in procedure order: cover and contents, the application form, the checklist. $d is ApplicationPaper's data, $checklist ChecklistService::printable or null. --}}
@php
    $F = fn ($key) => __('papers.file.'.$key);
    $title = $F('title').' · '.$d['number'];
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $projectId]);
    $full = trim(implode(' ', array_filter([$d['person']['first_name'] ?? '', $d['person']['father_name'] ?? '', $d['person']['last_name'] ?? ''])));
    $contents = [
        [$d['preliminary'] ? __('paper_app.title_pre') : __('paper_app.title'), true],
        ['Check List', $checklist !== null],
    ];
@endphp
<x-paper.document :title="$title" :back="$back">
<style>
    .cl { padding-block: 4mm; font-size: 10pt; }
    .cl .letterhead { width: 100%; display: block; }
    .cl h1 { font-size: 20pt; font-weight: 700; text-align: center; margin: 4mm 0; }
    .cl section { margin-bottom: 5mm; }
    .cl .sec { background: #be1f24; color: #fff; text-align: center; font-weight: 700; font-size: 12pt; padding: .6mm 2mm; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .cl .sub { background: #f1f2f2; color: inherit; position: static; text-align: center; font-weight: 500; font-size: 10pt; padding: .4mm 2mm; margin: 1.5mm 0 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .cl table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .cl table.grid th, .cl table.grid td { border: 1px solid #888; border-top: 0; padding: 1mm 1.5mm; text-align: start; vertical-align: top; overflow-wrap: anywhere; background: none; font-size: 9.5pt; height: auto; }
    .cl table.grid th { font-weight: 500; }
    .cl b { font-weight: 500; }
    .cl .box { border: 1px solid #888; border-top: 0; padding: 1.2mm 1.5mm; white-space: pre-wrap; overflow-wrap: anywhere; }
    .cl .foot { color: #555; font-size: 8.5pt; }
    .cl tr { break-inside: avoid; }
</style>
<x-paper.page :city="$d['city']['code']">
    <div class="p-title">{{ $F('title') }}</div>
    <div class="row"><x-paper.field :label="__('paper_app.project_no')" :value="$d['number']"/><x-paper.field :label="$F('city')" :value="$d['city']['name']"/></div>
    <div class="row" style="--c:1"><x-paper.field :label="$F('beneficiary')" :value="$full"/></div>
    <div class="bar">{{ $F('contents') }}</div>
    <x-paper.grid :columns="['n' => ['#', 8], 'paper' => [$F('paper'), 62], 'in' => [$F('included'), 30]]"
        :rows="collect($contents)->map(fn ($c, $i) => ['n' => $i + 1, 'paper' => $c[0], 'in' => $c[1] ? __('people.yes') : $F('left_out')])->all()" :min="2"/>
    <p class="note">{{ $F('printed') }} {{ now()->format('Y-m-d H:i') }}</p>
</x-paper.page>
@include($d['preliminary'] ? 'papers._application-preliminary' : 'papers._application', ['d' => $d])
@if($checklist !== null)
    <section class="sheet cl">@include('checklists._paper', $checklist)</section>
@endif
</x-paper.document>
