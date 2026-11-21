@php($locale = app()->getLocale())
<x-layouts.auth :title="__('hc.login_title')">
    <form method="POST" action="{{ route('auth.attempt', ['locale' => $locale]) }}" class="hc-auth-fields" novalidate>
        @csrf
        @if(session('status'))<x-hc.alert type="success">{{ session('status') }}</x-hc.alert>@endif
        @if($errors->any())<x-hc.alert type="danger">{{ $errors->first() }}</x-hc.alert>@endif
        <x-hc.field :label="__('hc.login_email')" for="login" :error="''" :hint="__('hc.login_email_hint')">
            <div class="hc-input-icon">
                <x-hc.icon name="mail" :size="18"/>
                <input id="login" name="login" type="text" inputmode="email" class="hc-input ltr" value="{{ old('login') }}" placeholder="{{ 'name@'.\App\Support\InstitutionEmail::domain() }}"
                       autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="200" required autofocus aria-describedby="login-hint" @if($errors->any()) aria-invalid="true" @endif>
            </div>
        </x-hc.field>
        <x-hc.field :label="__('hc.password')" for="password" :error="''">
            <x-hc.password id="password" maxlength="1024" required :invalid="$errors->any()"/>
        </x-hc.field>
        <div class="hc-auth-row">
            <label class="hc-check" title="{{ __('hc.remember_hint') }}">
                <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                <span>{{ __('hc.remember_me') }}</span>
            </label>
            <a class="hc-link" href="{{ route('auth.forgot', ['locale' => $locale]) }}">{{ __('hc.forgot') }}</a>
        </div>
        <x-hc.button type="submit" icon="arrow" style="width:100%">{{ __('hc.login') }}</x-hc.button>
        <p class="hc-hint">{{ __('hc.login_note') }}</p>
    </form>
    <x-slot:after><div class="hc-auth-install"><x-hc.install-app variant="button"/></div></x-slot:after>
</x-layouts.auth>
