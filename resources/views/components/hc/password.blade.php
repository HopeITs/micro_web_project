{{-- A password input with a show/hide button and a Caps Lock warning (resources/js/app.js, no framework needed). --}}
@props(['id', 'name' => null, 'autocomplete' => 'current-password', 'invalid' => false])
<div class="hc-password" data-password>
    <input {{ $attributes->merge(['class' => 'hc-input ltr']) }} id="{{ $id }}" name="{{ $name ?? $id }}" type="password" autocomplete="{{ $autocomplete }}" spellcheck="false" autocapitalize="none" @if($invalid) aria-invalid="true" @endif aria-describedby="{{ $id }}-caps">
    <button type="button" class="hc-password-toggle" data-password-toggle aria-controls="{{ $id }}" aria-pressed="false"
            aria-label="{{ __('hc.show_password') }}" title="{{ __('hc.show_password') }}" data-label-show="{{ __('hc.show_password') }}" data-label-hide="{{ __('hc.hide_password') }}">
        <span class="when-hidden"><x-hc.icon name="eye" :size="18"/></span><span class="when-shown"><x-hc.icon name="eye-off" :size="18"/></span>
    </button>
</div>
<p class="hc-caps" id="{{ $id }}-caps" data-caps-warning hidden><x-hc.icon name="warning" :size="14"/>{{ __('hc.caps_lock') }}</p>
