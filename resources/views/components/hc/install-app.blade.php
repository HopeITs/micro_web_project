{{--
    F1 "Install the app" (docs/planning/ux-borrowed-features-2026-11.md). Hidden until app.js knows the
    browser can install: Chrome/Edge/Android get the browser's own install prompt; iPhone/iPad Safari
    get the "Share → Add to Home Screen" steps. Never shown inside the installed app. Plain JS (no
    Alpine), so it also works on the sign-in page.
--}}
@props(['variant' => 'menu'])
<button type="button" data-pwa-install hidden {{ $attributes->class([$variant === 'menu' ? 'hc-install-item' : 'hc-btn hc-btn-ghost hc-btn-sm']) }}>
    <x-hc.icon name="install" :size="18"/><span>{{ __('hc.pwa_install') }}</span>
</button>
@once
<dialog class="hc-install-dialog" data-pwa-ios aria-labelledby="pwa-ios-title">
    <h2 id="pwa-ios-title">{{ __('hc.pwa_ios_title') }}</h2>
    <ol>
        <li>{{ __('hc.pwa_ios_step1') }} <span class="hc-install-share"><x-hc.icon name="share" :size="18"/></span></li>
        <li>{{ __('hc.pwa_ios_step2') }}</li>
        <li>{{ __('hc.pwa_ios_step3') }}</li>
    </ol>
    <form method="dialog"><button type="submit" class="hc-btn hc-btn-primary">{{ __('hc.pwa_ok') }}</button></form>
</dialog>
@endonce
