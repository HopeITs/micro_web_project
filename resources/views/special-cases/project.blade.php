<x-layouts.hc :title="__('special_cases.title').' '.\App\Services\ProjectNumberAllocator::display($code, (int) $project->project_number)">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('projects.show', ['locale' => app()->getLocale(), 'project' => $project->id]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('special_cases.back') }}</a></p>
    <div class="hc-page-head"><div><p class="hc-eyebrow"><x-hc.project-number :code="$code" :number="$project->project_number"/> · {{ $project->name }}</p><h1>{{ __('special_cases.title') }}</h1><p>{{ __('special_cases.intro') }}</p></div></div>
    <livewire:project-special-cases :project-id="$project->id" />
</x-layouts.hc>
