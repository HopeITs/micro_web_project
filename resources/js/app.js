// Alpine is provided by Livewire; do not initialize a second instance.

// Keep an entered value selected even if Livewire/Alpine is still loading.
document.addEventListener('input', (event) => {
    if (!(event.target instanceof HTMLElement) || !event.target.matches('[data-null-value]')) return;
    const checkbox = event.target.closest('[data-null-toggle]')?.querySelector('input[type="checkbox"]');
    if (checkbox instanceof HTMLInputElement && checkbox.checked) {
        checkbox.checked = false;
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
    }
});

// Searchable pickers (x-ui.search-select): the server is searched as the user types; no fixed option list.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('searchSelect', (config) => ({
        url: config.url, params: config.params || {}, navigate: Boolean(config.navigate),
        selectedId: String(config.value ?? ''), selectedLabel: config.label || '', query: config.label || '',
        results: [], open: false, loading: false, failed: false, active: -1, controller: null, lastQuery: null,
        show() { this.open = true; if (this.lastQuery === null) this.search(); },
        close() { this.open = false; this.active = -1; if (this.selectedId !== '' && this.query !== this.selectedLabel) this.query = this.selectedLabel; },
        clear() { this.selectedId = ''; this.selectedLabel = ''; this.query = ''; this.search(); },
        async search() {
            const term = this.query.trim();
            if (this.selectedId !== '' && term !== this.selectedLabel) { this.selectedId = ''; this.selectedLabel = ''; }
            this.open = true;
            if (term === this.lastQuery && !this.failed) return;
            this.controller?.abort();
            this.controller = new AbortController();
            this.loading = true; this.failed = false; this.lastQuery = term;
            const query = new URLSearchParams({ ...this.params, q: term });
            try {
                const response = await fetch(`${this.url}?${query}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: this.controller.signal });
                if (!response.ok) throw new Error(String(response.status));
                this.results = await response.json();
                this.active = this.results.length ? 0 : -1;
            } catch (error) {
                if (error.name === 'AbortError') return;
                this.results = []; this.failed = true;
            } finally {
                if (!this.controller.signal.aborted) this.loading = false;
            }
        },
        move(step) {
            if (!this.open) { this.show(); return; }
            if (!this.results.length) return;
            this.active = (this.active + step + this.results.length) % this.results.length;
            this.$nextTick(() => document.getElementById(`${this.$refs.input.id}-option-${this.active}`)?.scrollIntoView({ block: 'nearest' }));
        },
        pick(event) {
            if (!this.open || this.active < 0 || !this.results[this.active]) return;
            event.preventDefault();
            this.choose(this.results[this.active]);
        },
        choose(item) {
            if (this.navigate && item.url) { window.location.assign(item.url); return; }
            this.selectedId = String(item.id); this.selectedLabel = item.label; this.query = item.label;
            this.open = false; this.active = -1;
        },
    }));
});

// F1 installable app (docs/planning/ux-borrowed-features-2026-11.md): register the service worker
// (shell files + offline page only, see public/sw.js) and drive every "Install the app" button.
(() => {
    const workerUrl = document.querySelector('meta[name="mp-service-worker"]')?.content;
    if (workerUrl && 'serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', () => navigator.serviceWorker.register(workerUrl).catch(() => undefined));
    }

    const standalone = () => window.matchMedia('(display-mode: standalone)').matches
        || window.matchMedia('(display-mode: window-controls-overlay)').matches
        || window.navigator.standalone === true;
    const appleMobile = /iphone|ipad|ipod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    let deferredPrompt = null;

    const buttons = () => document.querySelectorAll('[data-pwa-install]');
    const refresh = () => {
        const show = !standalone() && (deferredPrompt !== null || appleMobile);
        buttons().forEach((button) => { button.hidden = !show; });
    };

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt = event;
        refresh();
    });
    window.addEventListener('appinstalled', () => { deferredPrompt = null; refresh(); });

    document.addEventListener('click', async (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-pwa-install]') : null;
        if (!button) return;
        if (deferredPrompt) {
            const prompt = deferredPrompt;
            deferredPrompt = null;
            await prompt.prompt();
            await prompt.userChoice.catch(() => undefined);
            refresh();
            return;
        }
        const dialog = document.querySelector('[data-pwa-ios]');
        if (dialog instanceof HTMLDialogElement) dialog.showModal();
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', refresh); else refresh();
    document.addEventListener('livewire:navigated', refresh);
})();

// F3 Ctrl+K search (resources/views/livewire/command-palette.blade.php). Recent items are pages and
// commands only (never a person or a project), kept in this browser.
document.addEventListener('alpine:init', () => {
    const RECENT_KEY = 'mp.cmdk.recent';
    const readRecent = () => { try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]').slice(0, 5); } catch (_) { return []; } };

    window.Alpine.data('commandPalette', () => ({
        open: false,
        active: -1,
        recent: readRecent(),
        show() {
            this.open = true;
            this.active = -1;
            this.$nextTick(() => { this.$refs.input.focus(); this.$refs.input.select(); });
        },
        hide() { this.open = false; },
        globalKey(event) {
            const typing = event.target instanceof HTMLElement && event.target.closest('input, textarea, select, [contenteditable="true"]');
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                this.open ? this.hide() : this.show();
            } else if (event.key === '/' && !typing && !this.open) {
                event.preventDefault();
                this.show();
            }
        },
        items() { return [...this.$refs.list.querySelectorAll('[data-cmdk-item]')]; },
        move(step) {
            const items = this.items();
            if (!items.length) return;
            this.active = (this.active + step + items.length) % items.length;
            items.forEach((item, i) => {
                item.classList.toggle('is-active', i === this.active);
                item.setAttribute('aria-selected', i === this.active ? 'true' : 'false');
            });
            items[this.active].scrollIntoView({ block: 'nearest' });
        },
        choose() {
            const items = this.items();
            const target = items[this.active] || items[0];
            if (target) target.click();
        },
        remember(item) {
            const list = [item, ...readRecent().filter((r) => r.url !== item.url)].slice(0, 5);
            try { localStorage.setItem(RECENT_KEY, JSON.stringify(list)); } catch (_) {}
            this.recent = list;
        },
    }));
});
