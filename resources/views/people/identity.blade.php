<x-layouts.hc :title="__('identity.title').' · '.\App\Services\People\PeopleService::fullName($person)">
    <livewire:identity-review :person-id="$person->id" :attachment-id="$attachment" />
</x-layouts.hc>
