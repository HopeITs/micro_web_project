<x-layouts.hc :title="__('applications.new')">
    <div class="hc-page-head"><div><p class="hc-eyebrow">{{ __('hc.group_applications') }}</p><h1>{{ __('applications.new') }}</h1><p>{{ __('applications.new_intro') }}</p></div></div>
    @unless($draft)<livewire:application-drafts />@endunless
    <livewire:application-form :person-id="$personId" :draft="$draft" />
</x-layouts.hc>
