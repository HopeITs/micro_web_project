<x-layouts.hc :title="__('lookups.names.'.$list)">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('lookups.index', ['locale' => app()->getLocale()]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('lookups.back') }}</a></p>
    <div class="hc-page-head">
        <div><p class="hc-eyebrow">{{ __('lookups.title') }} · {{ __('lookups.groups.'.$definition['group']) }}</p><h1>{{ __('lookups.names.'.$list) }}</h1></div>
        <x-hc.badge :tone="$definition['city'] ? 'info' : null" style="font-size:.9rem;padding:4px 14px">{{ $definition['city'] ? __('lookups.city_list') : __('lookups.shared_list') }}</x-hc.badge>
    </div>
    <livewire:lookup-editor :list="$list" />
</x-layouts.hc>
