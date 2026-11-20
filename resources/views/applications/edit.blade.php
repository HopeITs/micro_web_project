<x-layouts.hc :title="__('applications.edit').' '.\App\Services\ProjectNumberAllocator::display($code, (int) $project->project_number)">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('projects.show', ['locale' => app()->getLocale(), 'project' => $project->id]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('hc.back') }}</a></p>
    <div class="hc-page-head"><div><p class="hc-eyebrow">{{ __('hc.group_applications') }}</p><h1>{{ __('applications.edit') }} · <x-hc.project-number :code="$code" :number="$project->project_number"/></h1></div></div>
    <livewire:application-form :project-id="$project->id" />
</x-layouts.hc>
