{{-- One internal grid of the application form, bound to form.grids.<kind>. Expects $kind, $options (per field). --}}
@php($definition = \App\Services\Applications\ApplicationFields::grid($kind))
@php($rows = $form['grids'][$kind] ?? [])
@php($gates = collect($definition['fields'])->pluck('when')->filter()->unique()->all())
@php($labelOf = function (string $field) use ($kind) { $own = __('applications.grid_fields.'.$kind.'.'.$field); return $own !== 'applications.grid_fields.'.$kind.'.'.$field ? $own : (__('people.fields.'.$field) !== 'people.fields.'.$field ? __('people.fields.'.$field) : __('applications.fields.'.$field)); })
<div class="hc-grid-block" style="margin-block:12px 4px" wire:key="grid-{{ $kind }}">
    <div style="display:flex;align-items:center;gap:8px;margin-block-end:6px">
        <h4 class="hc-label" style="margin:0">{{ __('applications.grids.'.$kind) }} <span class="muted">({{ count($rows) }})</span></h4>
        <span style="flex:1"></span>
        <x-hc.button size="sm" variant="secondary" icon="plus" wire:click="addRow('{{ $kind }}')">{{ __('applications.add_row') }}</x-hc.button>
    </div>
    @if($previousOnly ?? false)<p class="hc-hint" style="margin-block-end:6px">{{ __('applications.gate.previous_only') }}</p>@endif
    @if($rows !== [])
    <div class="hc-table-wrap"><table class="hc-table stack">
        <thead><tr>@foreach($definition['fields'] as $field => $spec)<th>{{ $labelOf($field) }}@if($spec['required'] ?? false)<span class="req" aria-hidden="true">*</span>@endif</th>@if($field === 'person_id')<th>{{ __('applications.relation') }}</th>@endif @endforeach@if($kind === 'materials')<th>{{ __('applications.budget.row_total') }}</th>@endif<th></th></tr></thead>
        <tbody>
        @foreach($rows as $i => $row)
            <tr wire:key="grid-{{ $kind }}-{{ $row['id'] ?? 'n' }}-{{ $i }}">
                @foreach($definition['fields'] as $field => $spec)
                    @php($label = $labelOf($field))
                    <td data-label="{{ $label }}" style="min-width:{{ in_array($spec['kind'], ['textarea', 'lookup'], true) ? '160px' : '110px' }}">
                        @if(isset($spec['when']) && ($row[$spec['when']] ?? '') !== '1')
                            <span class="muted" title="{{ __('applications.gate.column', ['question' => __('people.fields.'.$spec['when'])]) }}">—</span>
                        @else
                        @include('people._input', ['model' => "form.grids.$kind.$i.$field", 'id' => "g-$kind-$i-$field", 'options' => $options[$field] ?? collect(),
                            'spec' => ($previousOnly ?? false) && $field === 'status' ? array_merge($spec, ['options' => ['no']]) : $spec,
                            'live' => in_array($field, $gates, true), 'plainSelect' => true])
                        @endif
                        @error("form.grids.$kind.$i.$field")<p class="hc-error">{{ $message }}</p>@enderror
                    </td>
                    @if($field === 'person_id')<td data-label="{{ __('applications.relation') }}" style="min-width:110px">{{ collect($options['person_id'] ?? [])->firstWhere('id', ($row['person_id'] ?? '') === '' ? 'self' : $row['person_id'])?->relation }}</td>@endif
                @endforeach
                @if($kind === 'materials')@php($n = fn ($v) => is_numeric(str_replace(',', '', (string) $v)) ? str_replace(',', '', (string) $v) : '0')<td data-label="{{ __('applications.budget.row_total') }}"><strong class="num">{{ rtrim(rtrim(number_format((float) bcmul($n($row['quantity'] ?? ''), $n($row['price'] ?? ''), 4), 4, '.', ','), '0'), '.') }}</strong></td>@endif
                <td><button type="button" class="hc-icon-btn" wire:click="removeRow('{{ $kind }}', {{ $i }})" aria-label="{{ __('applications.remove_row') }}" title="{{ __('applications.remove_row') }}"><x-hc.icon name="x" :size="16"/></button></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
</div>
