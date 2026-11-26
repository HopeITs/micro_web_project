<x-layouts.hc :title="__('report_builder.title')">
    <div class="hc-page-head">
        <div>
            <p class="hc-eyebrow">{{ __('hc.group_tools') }}</p>
            <h1>{{ __('report_builder.title') }}</h1>
            <p>{{ __('report_builder.intro') }}</p>
        </div>
    </div>
    <p class="hc-hint">{{ __('explore.notice') }} <a class="row-link" href="{{ route('explore.index', ['locale' => app()->getLocale()]) }}">{{ __('explore.notice_link') }}</a></p>
    <livewire:report-builder />
</x-layouts.hc>
