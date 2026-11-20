<div>
    @php
        $show = function ($row, $field, $spec) {
            return match ($spec['kind']) {
                'lookup' => $row->{$field.'_label'} ?? '—',
                'bool' => $row->{$field} === null ? '—' : ($row->{$field} ? __('people.yes') : __('people.no')),
                'decimal' => $row->{$field} === null ? '—' : number_format((float) $row->{$field}),
                'choice' => filled($row->{$field}) ? __('people.choices.'.$field.'.'.$row->{$field}) : '—',
                default => filled($row->{$field}) ? $row->{$field} : '—',
            };
        };
    @endphp
    <x-hc.section :title="__('people.details.'.$kind).' ('.$rows->count().')'" :open="$rows->isNotEmpty() || $adding">
        @if($rows->isEmpty() && ! $adding)<p class="muted" style="margin-block-end:8px">{{ __('people.no_rows') }}</p>@endif
        @if($rows->isNotEmpty())
        <div class="hc-table-wrap"><table class="hc-table stack">
            <thead><tr>@foreach($fields as $field => $spec)<th>{{ __('people.fields.'.$field) }}</th>@endforeach @if($editable)<th></th>@endif</tr></thead>
            <tbody>
            @foreach($rows as $row)
                @if($editing === (int) $row->id)
                <tr wire:key="{{ $kind }}-{{ $row->id }}-edit">
                    @foreach($fields as $field => $spec)
                    <td data-label="{{ __('people.fields.'.$field) }}">
                        @include('people._input', ['model' => 'edit.'.$field, 'id' => $kind.'-e-'.$field, 'options' => $options[$field] ?? collect()])
                        @error('edit.'.$field)<p class="hc-error">{{ $message }}</p>@enderror
                    </td>
                    @endforeach
                    <td style="white-space:nowrap"><x-hc.button size="sm" icon="check" wire:click="saveEdit">{{ __('people.save') }}</x-hc.button> <x-hc.button size="sm" variant="ghost" wire:click="cancel">{{ __('people.cancel') }}</x-hc.button></td>
                </tr>
                @else
                <tr wire:key="{{ $kind }}-{{ $row->id }}">
                    @foreach($fields as $field => $spec)<td data-label="{{ __('people.fields.'.$field) }}">{{ $show($row, $field, $spec) }}</td>@endforeach
                    @if($editable)
                    <td style="white-space:nowrap">
                        <x-hc.button size="sm" variant="ghost" wire:click="startEdit({{ $row->id }})">{{ __('people.edit') }}</x-hc.button>
                        <x-hc.button size="sm" variant="ghost" wire:click="remove({{ $row->id }})" wire:confirm="{{ __('people.confirm_delete_row') }}" style="color:var(--danger, #b42318)">{{ __('people.delete') }}</x-hc.button>
                    </td>
                    @endif
                </tr>
                @endif
            @endforeach
            </tbody>
        </table></div>
        @endif
        @if($editable)
            @if($adding)
            <form wire:submit="add" style="margin-block-start:12px;border-block-start:1px solid var(--line);padding-block-start:12px">
                <div class="hc-form-grid">
                @foreach($fields as $field => $spec)
                    <x-hc.field :label="__('people.fields.'.$field)" :for="$kind.'-d-'.$field" :required="$spec['required'] ?? false" :error="$errors->first('draft.'.$field)">
                        @include('people._input', ['model' => 'draft.'.$field, 'id' => $kind.'-d-'.$field, 'options' => $options[$field] ?? collect()])
                    </x-hc.field>
                @endforeach
                </div>
                <div style="margin-block-start:12px;display:flex;gap:8px"><x-hc.button type="submit" size="sm" icon="check">{{ __('people.add_row') }}</x-hc.button><x-hc.button size="sm" variant="ghost" wire:click="cancel">{{ __('people.cancel') }}</x-hc.button></div>
            </form>
            @else
            <div style="margin-block-start:8px"><x-hc.button size="sm" variant="secondary" icon="plus" wire:click="$set('adding', true)">{{ __('people.add_row') }}</x-hc.button></div>
            @endif
        @endif
    </x-hc.section>
</div>
