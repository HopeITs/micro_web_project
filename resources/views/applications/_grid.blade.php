{{-- One internal grid of the application form, bound to form.grids.<kind>. Expects $kind, $options (per field). --}}
@php($definition = \App\Services\Applications\ApplicationFields::grid($kind))
@php($rows = $form['grids'][$kind] ?? [])
@php($gates = collect($definition['fields'])->pluck('when')->filter()->unique()->all())
<div class="hc-grid-block" style="margin-block:12px 4px" wire:key="grid-{{ $kind }}">
    <div style="display:flex;align-items:center;gap:8px;margin-block-end:6px">
        <h4 class="hc-label" style="margin:0">{{ __('applications.grids.'.$kind) }} <span class="muted">({{ count($rows) }})</span></h4>
        <span style="flex:1"></span>
        <x-hc.button size="sm" variant="secondary" icon="plus" wire:click="addRow('{{ $kind }}')">{{ __('applications.add_row') }}</x-hc.button>
    </div>
    @if($previousOnly ?? false)<p class="hc-hint" style="margin-block-end:6px">{{ __('applications.gate.previous_only') }}</p>@endif
    @if($rows !== [])
    <div class="hc-table-wrap"><table class="hc-table stack">
        <thead><tr>@foreach($definition['fields'] as $field => $spec)<th>{{ __('people.fields.'.$field) !== 'people.fields.'.$field ? __('people.fields.'.$field) : __('applications.fields.'.$field) }}@if($spec['required'] ?? false)<span class="req" aria-hidden="true">*</span>@endif</th>@endforeach<th></th></tr></thead>
        <tbody>
        @foreach($rows as $i => $row)
            <tr wire:key="grid-{{ $kind }}-{{ $row['id'] ?? 'n' }}-{{ $i }}">
                @foreach($definition['fields'] as $field => $spec)
                    @php($label = __('people.fields.'.$field) !== 'people.fields.'.$field ? __('people.fields.'.$field) : __('applications.fields.'.$field))
                    <td data-label="{{ $label }}" style="min-width:{{ in_array($spec['kind'], ['textarea', 'lookup'], true) ? '160px' : '110px' }}">
                        @if(isset($spec['when']) && ($row[$spec['when']] ?? '') !== '1')
                            <span class="muted" title="{{ __('applications.gate.column', ['question' => __('people.fields.'.$spec['when'])]) }}">—</span>
                        @else
                        @include('people._input', ['model' => "form.grids.$kind.$i.$field", 'id' => "g-$kind-$i-$field", 'options' => $options[$field] ?? collect(),
                            'spec' => ($previousOnly ?? false) && $field === 'status' ? array_merge($spec, ['options' => ['no']]) : $spec,
                            'live' => in_array($field, $gates, true)])
                        @endif
                        @error("form.grids.$kind.$i.$field")<p class="hc-error">{{ $message }}</p>@enderror
                    </td>
                @endforeach
                <td><button type="button" class="hc-icon-btn" wire:click="removeRow('{{ $kind }}', {{ $i }})" aria-label="{{ __('applications.remove_row') }}" title="{{ __('applications.remove_row') }}"><x-hc.icon name="x" :size="16"/></button></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
</div>
