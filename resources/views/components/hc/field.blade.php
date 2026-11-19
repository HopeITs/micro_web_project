@props(['label', 'for' => null, 'hint' => null, 'error' => null, 'required' => false])
@php($errorText = $error ?? ($for && isset($errors) ? $errors->first($for) : null))
<div {{ $attributes->merge(['class' => 'hc-field']) }}>
    <label class="hc-label" @if($for) for="{{ $for }}" @endif>{{ $label }}@if($required)<span class="req" aria-hidden="true">*</span>@endif</label>
    {{ $slot }}
    @if($errorText)<p class="hc-error" id="{{ $for }}-error" role="alert">{{ $errorText }}</p>@elseif($hint)<p class="hc-hint" id="{{ $for }}-hint">{{ $hint }}</p>@endif
</div>
