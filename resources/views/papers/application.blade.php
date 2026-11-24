@php
    $L = fn ($key) => __('paper_app.'.$key);
    $back = route('projects.show', ['locale' => app()->getLocale(), 'project' => $projectId]);
    $title = $L('title').' · '.$d['number'];
@endphp
<x-paper.document :title="$title" :back="$back">
@include('papers._application', ['d' => $d])
</x-paper.document>
