@props(['title'])
@php($locale = app()->getLocale())
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @include('partials.pwa-head')
    <title>{{ $title }} · {{ __('hc.brand') }}</title>
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
        <div class="hc-auth-panel">
            <img class="hc-auth-micro" src="{{ asset('assets/brand/micro-projects.svg') }}" alt="{{ __('hc.brand') }}" width="180" height="66">
            <div class="hc-auth-head">
                <h2>{{ $title }}</h2>
                <a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ url(preg_replace('#^/(ar|en)#', '/'.($locale === 'ar' ? 'en' : 'ar'), request()->getPathInfo())) }}" lang="{{ $locale === 'ar' ? 'en' : 'ar' }}"><x-hc.icon name="globe" :size="16"/>{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
            </div>
            {{ $slot }}
        </div>
        @isset($after){{ $after }}@endisset
    </main>
</div>
</body>
</html>
