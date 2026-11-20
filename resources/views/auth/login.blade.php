@php($locale = app()->getLocale())
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @include('partials.pwa-head')
    <title>{{ __('hc.login_title') }} · {{ __('hc.brand') }}</title>
    <script>try { var t = localStorage.getItem('hc.theme'); if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t; } catch (e) {}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="hc-auth">
    <section class="hc-auth-art">
        <img class="hc-auth-logo" src="{{ asset('assets/brand/hope-center.svg') }}" alt="{{ __('hc.organization') }}" width="172" height="59">
        <div>
            <h1>{{ __('hc.login_heading') }}</h1>
            <p>{{ __('hc.login_intro') }}</p>
        </div>
        <p style="font-size:.85rem">{{ __('hc.organization') }}</p>
    </section>
    <main class="hc-auth-form">
        <form method="POST" action="{{ route('auth.attempt', ['locale' => $locale]) }}" novalidate>
            @csrf
            <img class="hc-auth-micro" src="{{ asset('assets/brand/micro-projects.svg') }}" alt="{{ __('hc.brand') }}" width="180" height="66">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <h2 style="font-size:1.4rem">{{ __('hc.login_title') }}</h2>
                <a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('auth.login', ['locale' => $locale === 'ar' ? 'en' : 'ar']) }}" lang="{{ $locale === 'ar' ? 'en' : 'ar' }}"><x-hc.icon name="globe" :size="16"/>{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
            </div>
            @if($errors->any())<x-hc.alert type="danger">{{ $errors->first() }}</x-hc.alert>@endif
            <x-hc.field :label="__('hc.username')" for="username" :error="''">
                <input id="username" name="username" class="hc-input ltr" value="{{ old('username') }}" autocomplete="username" required autofocus @if($errors->any()) aria-invalid="true" @endif>
            </x-hc.field>
            <x-hc.field :label="__('hc.password')" for="password" :error="''">
                <input id="password" name="password" type="password" class="hc-input ltr" autocomplete="current-password" required @if($errors->any()) aria-invalid="true" @endif>
            </x-hc.field>
            <x-hc.button type="submit" icon="arrow" style="width:100%">{{ __('hc.login') }}</x-hc.button>
            <p class="hc-hint">{{ __('hc.login_note') }}</p>
        </form>
        <div class="hc-auth-install"><x-hc.install-app variant="button"/></div>
    </main>
</div>
</body>
</html>
