{{-- One field of a people field set. Expects $model (wire:model path), $field, $spec, $options (Collection for lookups), $id, optional $live. --}}
@php($live = $live ?? false)
@php($wire = $live ? 'wire:model.live.debounce.400ms' : 'wire:model')
@switch($spec['kind'])
    @case('textarea')
        <textarea class="hc-textarea" id="{{ $id }}" {{ $wire }}="{{ $model }}" rows="2"></textarea>
        @break
    @case('date')
        <input class="hc-input ltr" type="date" id="{{ $id }}" {{ $wire }}="{{ $model }}" min="1900-01-01">
        @break
    @case('int')
    @case('decimal')
        <input class="hc-input ltr" type="text" inputmode="decimal" id="{{ $id }}" {{ $wire }}="{{ $model }}">
        @break
    @case('bool')
        <select class="hc-select" id="{{ $id }}" {{ $wire }}="{{ $model }}"><option value="">—</option><option value="1">{{ __('people.yes') }}</option><option value="0">{{ __('people.no') }}</option></select>
        @break
    @case('sex')
        <select class="hc-select" id="{{ $id }}" wire:model.live="{{ $model }}"><option value="">—</option><option value="male">{{ __('people.male') }}</option><option value="female">{{ __('people.female') }}</option></select>
        @break
    @case('lookup')
        <select class="hc-select" id="{{ $id }}" {{ $wire }}="{{ $model }}"><option value="">{{ __('people.choose') }}</option>@foreach($options as $o)<option value="{{ $o->id }}">{{ $o->label }}</option>@endforeach</select>
        @break
    @default
        <input class="hc-input @if(in_array($field, ['national_number', 'mobile', 'mobile_2', 'home_phone', 'full_name_en'], true)) ltr @endif" type="text" id="{{ $id }}" {{ $wire }}="{{ $model }}" maxlength="{{ $spec['max'] ?? 255 }}" autocomplete="off" @if(in_array($field, ['national_number', 'mobile', 'mobile_2', 'home_phone'], true)) inputmode="numeric" dir="ltr" @endif>
@endswitch
