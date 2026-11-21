{{-- One visit question: the label, the stored choice as radio pills (none for a text-only question) and its note. --}}
@php($key = $model.'.'.$slot)
<div class="hc-field" wire:key="{{ $key }}">
    @if($label)<span class="hc-label">{{ $label }}</span>@endif
    @if($domain !== null)
        <div class="hc-choice" role="radiogroup" @if($label) aria-label="{{ $label }}" @endif>
            @foreach($domain as $value => $choice)
                <label><input type="radio" name="{{ $key }}" value="{{ $value }}" wire:model="{{ $key }}.value" @disabled(! $manage)> {{ __('visits.choice.'.$choice) }}</label>
            @endforeach
        </div>
        @error($key.'.value')<p class="hc-error" role="alert">{{ $message }}</p>@enderror
    @endif
    <input class="hc-input" style="margin-block-start:6px" aria-label="{{ __('visits.fields.note') }}" placeholder="{{ $domain === null ? __('visits.fields.answer') : __('visits.fields.note') }}" wire:model="{{ $key }}.text" @disabled(! $manage)>
    @error($key.'.text')<p class="hc-error" role="alert">{{ $message }}</p>@enderror
</div>
