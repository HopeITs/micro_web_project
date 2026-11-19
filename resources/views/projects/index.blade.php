<x-layouts.hc :title="__('hc.projects_title')">
    <div class="hc-page-head">
        <div>
            <p class="hc-eyebrow">{{ __('hc.group_applications') }}</p>
            <h1>{{ __('hc.projects_title') }}</h1>
            <p>{{ __('hc.projects_intro') }}</p>
        </div>
    </div>
    <livewire:projects-index />
</x-layouts.hc>
