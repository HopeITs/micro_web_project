<x-layouts.hc :title="__('visits.kinds.'.$kind).' '.\App\Services\ProjectNumberAllocator::display($code, (int) $project->project_number)">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('visits.hub', ['locale' => app()->getLocale(), 'project' => $project->id]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('visits.back_visits') }}</a></p>
    <div class="hc-page-head"><div><p class="hc-eyebrow"><x-hc.project-number :code="$code" :number="$project->project_number"/> · {{ $project->name }}</p><h1>{{ __('visits.kinds.'.$kind) }}</h1></div></div>
    <livewire:visit-form :project-id="$project->id" :kind="$kind" />
</x-layouts.hc>
