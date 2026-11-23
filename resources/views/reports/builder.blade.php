<x-layouts.hc :title="__('report_builder.title')">
    <div class="hc-page-head">
        <div>
            <p class="hc-eyebrow">{{ __('hc.group_tools') }}</p>
            <h1>{{ __('report_builder.title') }}</h1>
            <p>{{ __('report_builder.intro') }}</p>
        </div>
    </div>
    <livewire:report-builder />
</x-layouts.hc>
