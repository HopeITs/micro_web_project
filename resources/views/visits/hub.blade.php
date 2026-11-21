<x-layouts.hc :title="__('visits.title').' '.\App\Services\ProjectNumberAllocator::display($code, (int) $project->project_number)">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('projects.show', ['locale' => app()->getLocale(), 'project' => $project->id]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('visits.back') }}</a></p>
    <div class="hc-page-head"><div><p class="hc-eyebrow"><x-hc.project-number :code="$code" :number="$project->project_number"/> · {{ $project->name }}</p><h1>{{ __('visits.title') }}</h1><p>{{ __('visits.intro') }}</p></div></div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:12px">{{ session('status') }}</x-hc.alert>@endif
    <livewire:project-visits :project-id="$project->id" />
</x-layouts.hc>
