{{--
    Adding (new or registered person) or editing a household member (FamilyPanel). The columns of the desktop family grid; the head's
    relation stays "مستفيد". Fields are bound to member.* when adding and edit.* when editing.
--}}
@php
    $locale = app()->getLocale();
    $isNew = $adding;
    $prefix = $isNew ? 'member' : 'edit';
    $values = $isNew ? $member : $edit;
    $uid = 'm'.$familyId.'-'.$headId.'-';
    $isHead = ! $isNew && $row?->is_head;
    $personFields = \App\Services\People\PersonFields::personFlat();
    $familyFields = \App\Services\People\PersonFields::MEMBER;
    $newPerson = ! $isNew || $mode === 'new';
    $order = array_merge(
        ['relation_id'],
        $newPerson ? ['first_name', 'father_name', 'last_name', 'sex', 'birth_date', 'national_number', 'person_state_id'] : ($picked && $picked->person_state_id === null ? ['person_state_id'] : []),
        ['education_level_id', 'education_specialty_id', 'has_work', 'work_name'],
    );
    $title = $isNew ? __('families.drawer_add') : __('families.drawer_edit', ['name' => $row?->name]);
@endphp
<div class="fam-drawer-root" wire:key="{{ $uid }}drawer-{{ $isNew ? 'add' : $editing }}" x-data x-trap="true" @keydown.escape.window="$wire.cancel()">
    <button type="button" class="fam-drawer-backdrop" wire:click="cancel" tabindex="-1" aria-label="{{ __('people.cancel') }}"></button>
    <form class="fam-drawer" role="dialog" aria-modal="true" aria-labelledby="{{ $uid }}title" wire:submit="{{ $isNew ? 'add' : 'saveEdit' }}">
        <header>
            <div>
                <h2 id="{{ $uid }}title">{{ $title }}</h2>
                <p class="muted">{{ $home['head_name'] === null ? __('families.no_head_title') : __('families.family_of', ['name' => $home['head_name']]) }}</p>
            </div>
            <button type="button" class="hc-icon-btn" wire:click="cancel" aria-label="{{ __('people.cancel') }}"><x-hc.icon name="x"/></button>
        </header>
        <div class="fam-drawer-body">
            @if($notice && $isNew)<x-hc.alert type="success">{{ $notice }}</x-hc.alert>@endif
            @error('member')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror

            @if($isNew)
            <div class="hc-chips" role="group" aria-label="{{ __('families.drawer_add') }}">
                <button type="button" class="hc-chip" wire:click="$set('mode', 'new')" aria-pressed="{{ $mode === 'new' ? 'true' : 'false' }}">{{ __('people.member_new') }}</button>
                <button type="button" class="hc-chip" wire:click="$set('mode', 'existing')" aria-pressed="{{ $mode === 'existing' ? 'true' : 'false' }}">{{ __('people.member_existing') }}</button>
            </div>
            @endif

            @if($isNew && $mode === 'existing')
            <x-hc.field :label="__('people.pick_person')" :for="$uid.'pick'" :error="$errors->first('member.person_id')">
                <input class="hc-input" id="{{ $uid }}pick" type="search" wire:model.live.debounce.300ms="pickSearch" placeholder="{{ __('people.pick_placeholder') }}" autocomplete="off">
                @if($picks->isNotEmpty())
                <div class="hc-chips">
                    @foreach($picks as $p)<button type="button" class="hc-chip" wire:key="{{ $uid }}pick-{{ $p->id }}" wire:click="pick({{ $p->id }})" aria-pressed="{{ (int) ($member['person_id'] ?? 0) === $p->id ? 'true' : 'false' }}">{{ $p->name }} @if($p->birth_year)<span class="muted num">{{ $p->birth_year }}</span>@endif</button>@endforeach
                </div>
                @endif
            </x-hc.field>
            @if($picked)
                <x-hc.alert type="info" :title="\App\Services\People\PeopleService::fullName($picked)">
                    @forelse($pickedHomes as $other)
                        {{ __('families.already_in', ['name' => $other->head_name ?? __('families.no_head'), 'number' => $other->family_number]) }}@if(! $loop->last)<br>@endif
                    @empty
                        {{ __('families.in_no_family') }}
                    @endforelse
                </x-hc.alert>
            @endif
            @endif

            <div class="hc-form-grid">
                @foreach($order as $field)
                    @php($spec = $familyFields[$field] ?? $personFields[$field])
                    @continue(isset($spec['when']) && ($values[$spec['when']] ?? '') !== '1')
                    <x-hc.field :label="__('people.fields.'.$field)" :for="$uid.$field" :required="$spec['required'] ?? false" :error="$errors->first($prefix.'.'.$field)"
                        :hint="$isNew && in_array($field, $suggested, true) ? __('families.suggested') : ($field === 'birth_date' ? __('families.birth_hint') : null)" wire:key="{{ $uid }}{{ $prefix }}-{{ $field }}">
                        @if($field === 'relation_id' && $isHead)
                            <p class="fam-fixed" id="{{ $uid }}{{ $field }}">{{ $row->relation ?? __('families.head') }} <x-hc.badge tone="brand">{{ __('families.head') }}</x-hc.badge></p>
                        @elseif($field === 'birth_date')
                            <input class="hc-input ltr" id="{{ $uid }}{{ $field }}" type="text" dir="ltr" inputmode="numeric" maxlength="10" placeholder="1990" wire:model="{{ $prefix }}.{{ $field }}" autocomplete="off">
                        @else
                            @include('people._input', ['model' => $prefix.'.'.$field, 'id' => $uid.$field, 'field' => $field, 'spec' => $spec, 'options' => $options[$field] ?? collect(),
                                'live' => in_array($field, ['relation_id', 'has_work'], true) || ($isNew && in_array($field, ['first_name', 'father_name', 'last_name', 'national_number'], true))])
                        @endif
                    </x-hc.field>
                @endforeach
            </div>

            @if($duplicates)
            <x-hc.alert type="warning" :title="__('people.duplicates_title')">
                @foreach($duplicates as $d)
                    <span class="fam-dup">
                        <a class="hc-link" href="{{ route('people.show', ['locale' => $locale, 'person' => $d->id]) }}" target="_blank" rel="noopener">{{ $d->name }}</a>
                        <span class="muted">{{ __('people.dup_'.$d->reason) }}</span>
                        @if($isNew && ! $home['members']->contains('person_id', $d->id))
                            <button type="button" class="hc-chip" wire:click="useExisting({{ $d->id }})">{{ __('families.use_existing') }}</button>
                        @elseif($isNew)
                            <span class="muted">{{ __('families.already_here') }}</span>
                        @endif
                    </span>
                @endforeach
                @if(! $isNew)
                    <span class="hc-choice"><label><input type="checkbox" wire:model.live="confirmed">{{ __('people.not_same_person') }}</label></span>
                @endif
            </x-hc.alert>
            @endif
            @error('confirm')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
        </div>
        <footer>
            <x-hc.button type="submit" icon="check" wire:loading.attr="disabled">{{ $isNew ? __('people.add_member') : __('people.save') }}</x-hc.button>
            @if($isNew)<x-hc.button variant="secondary" wire:click="add(true)" wire:loading.attr="disabled">{{ __('families.add_another') }}</x-hc.button>@endif
            <x-hc.button variant="ghost" wire:click="cancel">{{ __('people.cancel') }}</x-hc.button>
        </footer>
    </form>
</div>
