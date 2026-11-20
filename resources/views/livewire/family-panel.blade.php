<div>
    @php
        $locale = app()->getLocale();
        $newFields = array_intersect_key(\App\Services\People\PersonFields::personFlat(), array_flip(['first_name', 'father_name', 'last_name', 'sex', 'birth_date', 'national_number']));
    @endphp
    <x-hc.card :title="__('people.family')" :subtitle="$family ? __('people.family_number').': '.$family->family_number : null" :flush="true" id="family">
        @error('member')<div style="padding:0 20px"><x-hc.alert type="danger">{{ $message }}</x-hc.alert></div>@enderror
        <div class="hc-table-wrap">
        <table class="hc-table stack">
            <thead><tr><th>{{ __('people.name') }}</th><th>{{ __('people.fields.relation_id') }}</th><th>{{ __('people.birth_year') }}</th><th>{{ __('people.fields.education_level_id') }}</th><th>{{ __('people.fields.has_work') }}</th>@if($editable)<th></th>@endif</tr></thead>
            <tbody>
            @forelse($members as $m)
                @if($editing === (int) $m->id)
                <tr wire:key="m-{{ $m->id }}-edit">
                    <td data-label="{{ __('people.name') }}">{{ \App\Services\People\PeopleService::fullName($m) }}</td>
                    <td data-label="{{ __('people.fields.relation_id') }}">@include('people._input', ['model' => 'edit.relation_id', 'field' => 'relation_id', 'spec' => \App\Services\People\PersonFields::MEMBER['relation_id'], 'id' => 'e-rel', 'options' => $options['relation_id']])</td>
                    <td class="num">{{ $m->birth_date ? substr($m->birth_date, 0, 4) : '—' }}</td>
                    <td data-label="{{ __('people.fields.education_level_id') }}">
                        @include('people._input', ['model' => 'edit.education_level_id', 'field' => 'education_level_id', 'spec' => \App\Services\People\PersonFields::MEMBER['education_level_id'], 'id' => 'e-lvl', 'options' => $options['education_level_id']])
                        @include('people._input', ['model' => 'edit.education_specialty_id', 'field' => 'education_specialty_id', 'spec' => \App\Services\People\PersonFields::MEMBER['education_specialty_id'], 'id' => 'e-spec', 'options' => $options['education_specialty_id']])
                    </td>
                    <td data-label="{{ __('people.fields.has_work') }}">
                        @include('people._input', ['model' => 'edit.has_work', 'field' => 'has_work', 'spec' => \App\Services\People\PersonFields::MEMBER['has_work'], 'id' => 'e-work', 'options' => collect(), 'live' => true])
                        @if(($edit['has_work'] ?? '') === '1')
                        @include('people._input', ['model' => 'edit.work_name', 'field' => 'work_name', 'spec' => \App\Services\People\PersonFields::MEMBER['work_name'], 'id' => 'e-wname', 'options' => collect()])
                        @endif
                    </td>
                    <td style="white-space:nowrap"><x-hc.button size="sm" icon="check" wire:click="saveEdit">{{ __('people.save') }}</x-hc.button> <x-hc.button size="sm" variant="ghost" wire:click="cancel">{{ __('people.cancel') }}</x-hc.button></td>
                </tr>
                @else
                <tr wire:key="m-{{ $m->id }}">
                    <td data-label="{{ __('people.name') }}">
                        @if((int) $m->person_id === $personId)<strong>{{ \App\Services\People\PeopleService::fullName($m) }}</strong>
                        @else<a class="row-link" href="{{ route('people.show', ['locale' => $locale, 'person' => $m->person_id]) }}">{{ \App\Services\People\PeopleService::fullName($m) }}</a>@endif
                        @if($m->national_number)<span class="muted num ltr" style="font-size:.85em"> {{ $m->national_number }}</span>@endif
                    </td>
                    <td data-label="{{ __('people.fields.relation_id') }}">{{ $m->relation ?? '—' }}</td>
                    <td data-label="{{ __('people.birth_year') }}" class="num">{{ $m->birth_date ? substr($m->birth_date, 0, 4) : '—' }}</td>
                    <td data-label="{{ __('people.fields.education_level_id') }}">{{ collect([$m->education_level, $m->education_specialty])->filter()->implode(' · ') ?: '—' }}</td>
                    <td data-label="{{ __('people.fields.has_work') }}">{{ $m->has_work === null ? '—' : ($m->has_work ? __('people.yes') : __('people.no')) }}@if($m->work_name) · {{ $m->work_name }}@endif</td>
                    @if($editable)
                    <td style="white-space:nowrap">
                        <x-hc.button size="sm" variant="ghost" wire:click="startEdit({{ $m->id }}, {{ \Illuminate\Support\Js::from(['relation_id' => $m->relation_id, 'education_level_id' => $m->education_level_id, 'education_specialty_id' => $m->education_specialty_id, 'has_work' => $m->has_work === null ? null : (bool) $m->has_work, 'work_name' => $m->work_name]) }})">{{ __('people.edit') }}</x-hc.button>
                        @if((int) $m->person_id !== $personId)
                        <x-hc.button size="sm" variant="ghost" wire:click="remove({{ $m->id }})" wire:confirm="{{ __('people.confirm_remove_member') }}" style="color:var(--danger, #b42318)">{{ __('people.remove_member') }}</x-hc.button>
                        @endif
                    </td>
                    @endif
                </tr>
                @endif
            @empty
                <tr><td colspan="6" class="muted">{{ __('people.no_family') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        @if($editable && $family)
        <x-slot:footer>
            @if($adding)
            <form wire:submit="add">
                <div class="hc-chips" role="group" style="margin-block-end:12px">
                    <button type="button" class="hc-chip" wire:click="$set('mode', 'new')" aria-pressed="{{ $mode === 'new' ? 'true' : 'false' }}">{{ __('people.member_new') }}</button>
                    <button type="button" class="hc-chip" wire:click="$set('mode', 'existing')" aria-pressed="{{ $mode === 'existing' ? 'true' : 'false' }}">{{ __('people.member_existing') }}</button>
                </div>
                <div class="hc-form-grid">
                @if($mode === 'new')
                    @foreach($newFields as $field => $spec)
                        <x-hc.field :label="__('people.fields.'.$field)" :for="'m-'.$field" :required="$spec['required'] ?? false" :error="$errors->first('member.'.$field)">
                            @include('people._input', ['model' => 'member.'.$field, 'id' => 'm-'.$field, 'options' => collect()])
                        </x-hc.field>
                    @endforeach
                @else
                    <x-hc.field :label="__('people.pick_person')" for="m-pick" :error="$errors->first('member.person_id')" style="grid-column:1/-1">
                        <input class="hc-input" id="m-pick" type="search" wire:model.live.debounce.300ms="pickSearch" placeholder="{{ __('people.pick_placeholder') }}" autocomplete="off">
                        @if($picked)<p style="margin-block-start:6px"><x-hc.badge tone="brand">{{ \App\Services\People\PeopleService::fullName($picked) }}</x-hc.badge></p>@endif
                        @if($picks->isNotEmpty())
                        <div class="hc-chips" style="margin-block-start:8px">
                            @foreach($picks as $p)<button type="button" class="hc-chip" wire:click="pick({{ $p->id }})" aria-pressed="{{ (int) ($member['person_id'] ?? 0) === $p->id ? 'true' : 'false' }}">{{ $p->name }} @if($p->birth_year)<span class="muted num">{{ $p->birth_year }}</span>@endif</button>@endforeach
                        </div>
                        @endif
                    </x-hc.field>
                @endif
                @foreach(\App\Services\People\PersonFields::MEMBER as $field => $spec)
                    @continue(isset($spec['when']) && ($member[$spec['when']] ?? '') !== '1')
                    <x-hc.field :label="__('people.fields.'.$field)" :for="'m-'.$field" :error="$errors->first('member.'.$field)">
                        @include('people._input', ['model' => 'member.'.$field, 'id' => 'm-'.$field, 'options' => $options[$field] ?? collect(), 'live' => $field === 'has_work'])
                    </x-hc.field>
                @endforeach
                </div>
                <div style="margin-block-start:12px;display:flex;gap:8px"><x-hc.button type="submit" size="sm" icon="check">{{ __('people.add_member') }}</x-hc.button><x-hc.button size="sm" variant="ghost" wire:click="cancel">{{ __('people.cancel') }}</x-hc.button></div>
            </form>
            @else
            <x-hc.button size="sm" variant="secondary" icon="plus" wire:click="$set('adding', true)">{{ __('people.add_member') }}</x-hc.button>
            @endif
        </x-slot:footer>
        @endif
    </x-hc.card>
</div>
