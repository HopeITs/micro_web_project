@php
    $L = fn ($key) => __('paper_app.'.$key);
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $projectId]);
    $title = $L('title_pre').' · '.$d['number'];
@endphp
<x-paper.document :title="$title" :back="$back">
@include('papers._application-preliminary', ['d' => $d])
</x-paper.document>
