<x-layouts.hc :title="__('people.title')">
    <div class="hc-page-head">
        <div><p class="hc-eyebrow">{{ __('hc.group_applications') }}</p><h1>{{ __('people.title') }}</h1><p>{{ __('people.intro') }}</p></div>
        @if(app(\App\Services\People\PeopleService::class)->cityIds(auth()->user(), 'people.manage'))
            <x-hc.button icon="plus" :href="route('people.create', ['locale' => app()->getLocale()])">{{ __('people.new') }}</x-hc.button>
        @endif
    </div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
    @include('people._tabs', ['current' => 'individuals'])
    <livewire:people-index />
</x-layouts.hc>
