<x-layouts.hc :title="\App\Services\People\PeopleService::fullName($person)">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('people.show', ['locale' => app()->getLocale(), 'person' => $person->id]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('people.back_person') }}</a></p>
    <div class="hc-page-head"><div><p class="hc-eyebrow">{{ __('people.edit_title') }}</p><h1>{{ \App\Services\People\PeopleService::fullName($person) }}</h1></div></div>
    <livewire:person-form :person-id="$person->id" />
</x-layouts.hc>
