<x-layouts.hc :title="__('people.new')">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('people.index', ['locale' => app()->getLocale()]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('people.back') }}</a></p>
    <div class="hc-page-head"><div><p class="hc-eyebrow">{{ __('people.title') }}</p><h1>{{ __('people.new') }}</h1><p>{{ __('people.new_intro') }}</p></div></div>
    <livewire:person-form />
</x-layouts.hc>
