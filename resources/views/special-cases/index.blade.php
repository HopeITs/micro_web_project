<x-layouts.hc :title="__('special_cases.list.title')">
    <div class="hc-page-head">
        <div><h1>{{ __('special_cases.list.title') }}</h1><p>{{ __('special_cases.list.intro') }}</p></div>
        {{-- Add a case from here too (owner request 22 Nov 2026): choose the project by number or beneficiary name. --}}
        @if(app(\App\Services\AccountAccess::class)->cities(auth()->user(), 'special_cases.manage')->isNotEmpty())
            <livewire:project-picker target="special-cases.project" capability="special_cases.manage" :label="__('special_cases.list.add')" :query="['new' => 1]" />
        @endif
    </div>
    <livewire:special-case-list />
</x-layouts.hc>
