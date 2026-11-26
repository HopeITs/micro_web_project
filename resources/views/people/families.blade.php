<x-layouts.hc :title="__('families.title')">
    <div class="hc-page-head">
        <div><p class="hc-eyebrow">{{ __('hc.group_applications') }}</p><h1>{{ __('people.title') }}</h1><p>{{ __('families.intro') }}</p></div>
        @if(app(\App\Services\People\PeopleService::class)->cityIds(auth()->user(), 'people.manage'))
            {{-- A new household starts with its beneficiary. --}}
            <x-hc.button icon="plus" :href="route('people.create', ['locale' => app()->getLocale()])">{{ __('families.new') }}</x-hc.button>
        @endif
    </div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
    @include('people._tabs', ['current' => 'families'])
    <livewire:families-index />
</x-layouts.hc>
