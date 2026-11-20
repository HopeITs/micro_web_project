{{-- F1 installable app: manifest in the page language, icons, and the service worker address (registered in app.js). --}}
<link rel="manifest" href="{{ route('pwa.manifest', ['locale' => app()->getLocale()]) }}">
<meta name="theme-color" content="#be1f24">
<meta name="application-name" content="{{ __('hc.brand') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ __('hc.brand') }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
<link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
<meta name="mp-service-worker" content="{{ asset('sw.js') }}">
