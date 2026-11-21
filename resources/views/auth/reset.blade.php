@php($locale = app()->getLocale())
<x-layouts.auth :title="__('account.reset_title')">
    @if($valid)
        <p class="muted">{{ __('account.change_intro', ['min' => \App\Services\AccountPasswords::MIN_LENGTH]) }}</p>
        @if($errors->any())<x-hc.alert type="danger">{{ $errors->first() }}</x-hc.alert>@endif
        <form method="POST" action="{{ route('auth.reset.store', ['locale' => $locale, 'token' => $token]) }}" class="hc-auth-fields" novalidate>
            @csrf
            <x-hc.field :label="__('account.new_password')" for="password" :error="''">
                <x-hc.password id="password" autocomplete="new-password" required minlength="{{ \App\Services\AccountPasswords::MIN_LENGTH }}" autofocus :invalid="$errors->any()"/>
            </x-hc.field>
            <x-hc.field :label="__('account.confirm_password')" for="password_confirmation" :error="''">
                <x-hc.password id="password_confirmation" autocomplete="new-password" required/>
            </x-hc.field>
            <x-hc.button type="submit" icon="check" style="width:100%">{{ __('account.change') }}</x-hc.button>
        </form>
    @else
        <x-hc.alert type="danger">{{ __('account.expired') }}</x-hc.alert>
        <a class="hc-btn" href="{{ route('auth.forgot', ['locale' => $locale]) }}" style="justify-content:center">{{ __('account.forgot_title') }}</a>
    @endif
    <p><a class="hc-link" href="{{ route('auth.login', ['locale' => $locale]) }}">{{ __('account.back_to_login') }}</a></p>
</x-layouts.auth>
