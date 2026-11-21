{{-- A member list of the checklist: key persons (k: name, role, notes) or team/committee (t/c: opinion and reason). --}}
@php($rows = $form['members'][$location] ?? [])
<x-hc.section :title="$title.' ('.count($rows).')'" :step="$step">
    @isset($dateField)
        <div class="hc-form-grid" style="margin-block-end:12px">
            <x-hc.field :label="__('checklists.fields.'.$dateField)" :for="'cl-'.$dateField" :error="$errors->first('form.header.'.$dateField)">
                <input class="hc-input ltr" type="date" id="cl-{{ $dateField }}" wire:model="form.header.{{ $dateField }}" min="1900-01-01">
            </x-hc.field>
        </div>
    @endisset
    @if($rows !== [])
    <div class="hc-table-wrap"><table class="hc-table stack">
        <thead><tr>
            <th>{{ __('checklists.member') }}</th>
            @if($location === 'k')<th>{{ __('checklists.name_notes') }}</th><th>{{ __('checklists.role_notes') }}</th>@else<th>{{ __('checklists.opinion') }}</th>@endif
            <th>{{ $location === 'k' ? __('checklists.fields.notes') : __('checklists.reason') }}</th><th></th>
        </tr></thead>
        <tbody>
        @foreach($rows as $i => $row)
            <tr wire:key="m-{{ $location }}-{{ $row['id'] ?? 'n' }}-{{ $i }}">
                <td data-label="{{ __('checklists.member') }}" style="min-width:200px">
                    @include('people._input', ['model' => "form.members.$location.$i.checklist_member_id", 'id' => "m-$location-$i", 'field' => 'member', 'spec' => ['kind' => 'lookup'], 'options' => $memberOptions[$location]])
                    @error("form.members.$location.$i.checklist_member_id")<p class="hc-error">{{ $message }}</p>@enderror
                </td>
                @if($location === 'k')
                    <td data-label="{{ __('checklists.name_notes') }}"><input class="hc-input" wire:model="form.members.{{ $location }}.{{ $i }}.name_notes" maxlength="255" aria-label="{{ __('checklists.name_notes') }}"></td>
                    <td data-label="{{ __('checklists.role_notes') }}"><input class="hc-input" wire:model="form.members.{{ $location }}.{{ $i }}.role_notes" maxlength="255" aria-label="{{ __('checklists.role_notes') }}"></td>
                @else
                    <td data-label="{{ __('checklists.opinion') }}" style="min-width:200px">
                        <div class="hc-choice" role="radiogroup" aria-label="{{ __('checklists.opinion') }}">
                            @foreach($opinions as $o)<label><input type="radio" value="{{ $o->id }}" wire:model="form.members.{{ $location }}.{{ $i }}.checklist_opinion_id">{{ $o->label }}</label>@endforeach
                        </div>
                    </td>
                @endif
                <td data-label="{{ $location === 'k' ? __('checklists.fields.notes') : __('checklists.reason') }}" style="min-width:180px"><input class="hc-input" wire:model="form.members.{{ $location }}.{{ $i }}.notes" aria-label="{{ __('checklists.fields.notes') }}"></td>
                <td><button type="button" class="hc-icon-btn" wire:click="removeMember('{{ $location }}', {{ $i }})" aria-label="{{ __('applications.remove_row') }}"><x-hc.icon name="x" :size="16"/></button></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
    <div style="margin-block-start:8px"><x-hc.button size="sm" variant="secondary" icon="plus" wire:click="addMember('{{ $location }}')">{{ __('checklists.add_member') }}</x-hc.button></div>
</x-hc.section>
