@props(['title' => null])
@php
    $account = auth()->user();
    $locale = app()->getLocale();
    $other = $locale === 'ar' ? 'en' : 'ar';
    $route = request()->route();
    $languageUrl = $route?->getName() ? route($route->getName(), array_merge($route->parameters(), ['locale' => $other]) ) . (request()->getQueryString() ? '?'.request()->getQueryString() : '') : url($other);
    $nav = $account ? \App\Support\Navigation::for($account) : [];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @include('partials.pwa-head')
    <title>{{ $title ? $title.' · ' : '' }}{{ __('hc.brand') }}</title>
    <script>try { var t = localStorage.getItem('hc.theme'); if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t; } catch (e) {}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body x-data="{ nav: false, theme: document.documentElement.dataset.theme || 'auto',
        setTheme(t) { this.theme = t; if (t === 'auto') { delete document.documentElement.dataset.theme } else { document.documentElement.dataset.theme = t } try { localStorage.setItem('hc.theme', t) } catch (e) {} } }"
      @keydown.escape.window="nav = false">
<a class="skip-link" href="#main">{{ __('hc.skip') }}</a>
<header class="hc-header">
    <button class="hc-icon-btn hc-menu-toggle" type="button" @click="nav = !nav" :aria-expanded="nav" aria-controls="hc-sidebar" aria-label="{{ __('hc.menu') }}"><x-hc.icon name="menu"/></button>
    <a class="hc-brand" href="{{ route('home', ['locale' => $locale]) }}" aria-label="{{ __('hc.brand') }} · {{ __('hc.organization') }}">
        <img class="hc-logo-micro" src="{{ asset('assets/brand/micro-projects.svg') }}" alt="{{ __('hc.brand') }}" width="96" height="35">
    </a>
    <div class="hc-header-actions">
        @if($account)<livewire:command-palette />@endif
        <div style="position:relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
            <button class="hc-icon-btn" type="button" @click="open = !open" :aria-expanded="open" aria-label="{{ __('hc.theme') }}" title="{{ __('hc.theme') }}">
                <x-hc.icon name="sun" x-show="theme !== 'dark'"/><x-hc.icon name="moon" x-show="theme === 'dark'" x-cloak/>
            </button>
            <div class="hc-dropdown" x-show="open" x-cloak>
                <button type="button" @click="setTheme('light'); open = false"><x-hc.icon name="sun" :size="18"/>{{ __('hc.theme_light') }}</button>
                <button type="button" @click="setTheme('dark'); open = false"><x-hc.icon name="moon" :size="18"/>{{ __('hc.theme_dark') }}</button>
                <button type="button" @click="setTheme('auto'); open = false"><x-hc.icon name="settings" :size="18"/>{{ __('hc.theme_auto') }}</button>
            </div>
        </div>
        <a class="hc-icon-btn" href="{{ $languageUrl }}" lang="{{ $other }}" title="{{ $other === 'en' ? 'English' : 'العربية' }}" aria-label="{{ $other === 'en' ? 'English' : 'العربية' }}"><x-hc.icon name="globe"/></a>
        @if($account)
        <div style="position:relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
            <button class="hc-icon-btn" type="button" @click="open = !open" :aria-expanded="open" aria-label="{{ __('hc.account') }}"><x-hc.icon name="user"/></button>
            <div class="hc-dropdown" x-show="open" x-cloak>
                <div class="who"><strong class="ltr">{{ $account->username }}</strong>{{ __('hc.signed_in') }}</div>
                @if(Route::has('account.password'))<a href="{{ route('account.password', ['locale' => $locale]) }}"><x-hc.icon name="settings" :size="18"/>{{ __('account.change_title') }}</a>@endif
                <x-hc.install-app x-on:click="open = false"/>
                <form method="POST" action="{{ route('auth.logout', ['locale' => $locale]) }}">@csrf<button type="submit"><x-hc.icon name="logout" :size="18"/>{{ __('hc.logout') }}</button></form>
            </div>
        </div>
        @endif
    </div>
</header>
<button class="hc-backdrop" x-show="nav" x-cloak @click="nav = false" tabindex="-1" aria-label="{{ __('hc.close_menu') }}"></button>
<aside id="hc-sidebar" class="hc-sidebar" :class="{ 'is-open': nav }">
    <nav aria-label="{{ __('hc.navigation') }}">
        @foreach($nav as $group => $items)
            @if($group !== 'main')<p class="hc-nav-group">{{ __('hc.group_'.$group) }}</p>@endif
            @foreach($items as $item)
                @if($item['url'])
                    <a class="hc-nav-link" href="{{ $item['url'] }}" @if($item['current']) aria-current="page" @endif><x-hc.icon :name="$item['icon']"/><span>{{ __('hc.nav_'.$item['key']) }}</span></a>
                @else
                    <span class="hc-nav-link" aria-disabled="true" title="{{ __('hc.soon_title', ['task' => $item['task']]) }}"><x-hc.icon :name="$item['icon']"/><span>{{ __('hc.nav_'.$item['key']) }}</span><span class="soon">{{ __('hc.soon') }}</span></span>
                @endif
            @endforeach
        @endforeach
    </nav>
</aside>
<main id="main" class="hc-main" tabindex="-1">
    @if(app()->environment('local'))<x-hc.alert type="warning" class="mb-4" style="margin-block-end:16px">{{ __('hc.synthetic_notice') }}</x-hc.alert>@endif
    {{ $slot }}
</main>
@livewireScripts
</body>
</html>
