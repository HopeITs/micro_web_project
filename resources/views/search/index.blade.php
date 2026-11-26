<x-layouts.hc :title="__('search.title')">
    <div class="hc-page-head">
        <div><h1>{{ __('search.title') }}</h1><p>{{ __('search.intro') }}</p></div>
    </div>
    <p class="hc-hint">{{ __('explore.notice') }} <a class="row-link" href="{{ route('explore.index', ['locale' => app()->getLocale()]) }}">{{ __('explore.notice_link') }}</a></p>
    <livewire:search-page />
</x-layouts.hc>
