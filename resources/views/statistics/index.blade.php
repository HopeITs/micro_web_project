<x-layouts.hc :title="__('statistics.title')">
    <div class="hc-page-head">
        <div><h1>{{ __('statistics.title') }}</h1><p>{{ __('statistics.intro') }}</p></div>
    </div>
    <p class="hc-hint">{{ __('explore.notice') }} <a class="row-link" href="{{ route('explore.index', ['locale' => app()->getLocale()]) }}">{{ __('explore.notice_link') }}</a></p>
    <livewire:statistics-page />
</x-layouts.hc>
