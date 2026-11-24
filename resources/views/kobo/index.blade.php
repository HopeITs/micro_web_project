<x-layouts.hc :title="__('kobo.title')">
    <div class="hc-page-head"><div><h1>{{ __('kobo.title') }}</h1><p>{{ __('kobo.intro') }}</p></div>
        <livewire:project-picker target="kobo.visit.create" capability="visits.manage" :label="__('kobo.new_visit')" />
    </div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:12px">{{ session('status') }}</x-hc.alert>@endif
    <livewire:kobo-index />
</x-layouts.hc>
