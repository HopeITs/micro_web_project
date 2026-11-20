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
    @case('choice')
        <select class="hc-select" id="{{ $id }}" {{ $wire }}="{{ $model }}"><option value="">—</option>@foreach($spec['options'] as $code)<option value="{{ $code }}">{{ __('people.choices.'.$field.'.'.$code) }}</option>@endforeach</select>
        @break
    @case('lookup')
        @php($dependent = $live || isset($spec['parent']) || ($parents ?? false))
        @if(count($options) > 8)
            {{-- Long list: type to search inside the field. Rebuilt when a parent narrows the list. --}}
            @php($key = $id.'-'.md5(collect($options)->pluck('id')->implode(',')))
            @if($dependent)
                <x-hc.combo :id="$id" :options="$options" wire:model.live="{{ $model }}" wire:key="{{ $key }}"/>
            @else
                <x-hc.combo :id="$id" :options="$options" wire:model="{{ $model }}" wire:key="{{ $key }}"/>
            @endif
        @else
            <select class="hc-select" id="{{ $id }}" @if($dependent) wire:model.live="{{ $model }}" @else {{ $wire }}="{{ $model }}" @endif><option value="">{{ __('people.choose') }}</option>@foreach($options as $o)<option value="{{ $o->id }}">@if($o->group ?? null){{ $o->group }} · @endif{{ $o->label }}</option>@endforeach</select>
        @endif
        @break
    @default
        <input class="hc-input @if(in_array($field, ['national_number', 'mobile', 'mobile_2', 'home_phone', 'full_name_en'], true)) ltr @endif" type="text" id="{{ $id }}" {{ $wire }}="{{ $model }}" maxlength="{{ $spec['max'] ?? 255 }}" autocomplete="off" @if(in_array($field, ['national_number', 'mobile', 'mobile_2', 'home_phone'], true)) inputmode="numeric" dir="ltr" @endif>
@endswitch
