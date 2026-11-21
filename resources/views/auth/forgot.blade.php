@php($locale = app()->getLocale())
<x-layouts.auth :title="__('account.forgot_title')">
    <p class="muted">{{ __('account.forgot_intro') }}</p>
    @if(session('sent'))
        <x-hc.alert type="success">{{ __('account.forgot_sent', ['email' => session('sent'), 'minutes' => \App\Services\AccountPasswords::RESET_MINUTES]) }}</x-hc.alert>
    @endif
    @if($errors->any())<x-hc.alert type="danger">{{ $errors->first() }}</x-hc.alert>@endif
    <form method="POST" action="{{ route('auth.forgot.request', ['locale' => $locale]) }}" class="hc-auth-fields" novalidate>
        @csrf
        <x-hc.field :label="__('account.email')" for="email" :error="''">
            <div class="hc-input-icon">
                <x-hc.icon name="mail" :size="18"/>
                <input id="email" name="email" type="email" class="hc-input ltr" value="{{ old('email', session('sent')) }}" placeholder="{{ 'name@'.\App\Support\InstitutionEmail::domain() }}"
                       autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="190" required autofocus>
            </div>
        </x-hc.field>
        <x-hc.button type="submit" icon="mail" style="width:100%">{{ __('account.continue') }}</x-hc.button>
    </form>
    <p class="hc-hint">{{ __('account.forgot_no_email') }}</p>
    <p><a class="hc-link" href="{{ route('auth.login', ['locale' => $locale]) }}">{{ __('account.back_to_login') }}</a></p>
</x-layouts.auth>
