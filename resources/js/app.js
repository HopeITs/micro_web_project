// Alpine is provided by Livewire; do not initialize a second instance.
import Sortable from 'sortablejs';

// Column picker (x-hc.column-picker, smart-search phase 2): drag or arrow buttons reorder, checkboxes show/hide;
// every change sends the ticked keys in their order to the Livewire list's setColumns(). At least one stays shown.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('columnPicker', () => ({
        open: false,
        sortable: null,
        init() {
            this.sortable = Sortable.create(this.$refs.list, {
                handle: '.hc-column-handle', draggable: 'li', animation: 150,
                onEnd: (event) => { if (event.oldIndex !== event.newIndex) this.commit(); },
            });
        },
        destroy() { this.sortable?.destroy(); },
        keys() {
            return [...this.$refs.list.querySelectorAll('li')]
                .filter((li) => li.querySelector('input[type="checkbox"]')?.checked)
                .map((li) => li.dataset.key);
        },
        commit(event = null) {
            const keys = this.keys();
            if (keys.length === 0) { if (event?.target) event.target.checked = true; return; }
            this.$wire.setColumns(keys);
        },
        move(event, step) {
            const li = event.target.closest('li');
            const sibling = step < 0 ? li.previousElementSibling : li.nextElementSibling;
            if (!sibling) return;
            step < 0 ? sibling.before(li) : sibling.after(li);
            event.target.focus();
            this.commit();
        },
    }));
});

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

// Type-to-search list (x-hc.combo, M4.5 owner request): a long fixed list filtered as the user types, inside the field itself.
// Arabic is matched loosely: diacritics are ignored and أ/إ/آ=ا, ة=ه, ى=ي; every typed word must appear in the label or its group.
document.addEventListener('alpine:init', () => {
    const fold = (text) => String(text ?? '').toLowerCase().normalize('NFKD')
        .replace(/[ً-ْٰـ]/g, '').replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').replace(/[̀-ͯ]/g, '');

    window.Alpine.data('hcCombo', (options) => ({
        options: options.map((o) => ({ ...o, id: String(o.id), key: fold(o.label + ' ' + (o.group ?? '')) })),
        value: '', query: '', open: false, active: -1, limit: 80,
        init() {
            this.query = this.selected()?.label ?? '';
            this.$watch('value', () => { if (!this.open) this.query = this.selected()?.label ?? ''; });
        },
        selected() { return this.options.find((o) => o.id === String(this.value ?? '')); },
        filtered() {
            const words = fold(this.query).split(/\s+/).filter(Boolean);
            const typed = words.length > 0 && this.query !== (this.selected()?.label ?? '');
            const list = typed ? this.options.filter((o) => words.every((w) => o.key.includes(w))) : this.options;
            return list.slice(0, this.limit);
        },
        total() { return this.options.length; },
        show() { if (!this.open) { this.open = true; this.active = -1; this.$nextTick(() => this.$refs.input.select()); } },
        close() { this.open = false; this.active = -1; this.query = this.selected()?.label ?? ''; },
        choose(option) { this.value = option ? option.id : ''; this.query = option ? option.label : ''; this.open = false; this.active = -1; },
        clear() { this.choose(null); this.$refs.input.focus(); },
        move(step) {
            const list = this.filtered();
            if (!this.open) this.show();
            if (list.length === 0) return;
            this.active = (this.active + step + list.length) % list.length;
            this.$nextTick(() => this.$refs.list?.querySelector(`[data-index="${this.active}"]`)?.scrollIntoView({ block: 'nearest' }));
        },
        enter() { const list = this.filtered(); if (this.open && list.length > 0) this.choose(list[Math.max(this.active, 0)]); },
    }));
});

// Sign-in pages (x-hc.password): show/hide the password and warn when Caps Lock is on. Plain DOM, so it works without Livewire.
document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-password-toggle]') : null;
    if (!button) return;
    const input = document.getElementById(button.getAttribute('aria-controls') || '');
    if (!(input instanceof HTMLInputElement)) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(show));
    const label = show ? button.dataset.labelHide : button.dataset.labelShow;
    if (label) { button.setAttribute('aria-label', label); button.setAttribute('title', label); }
    input.focus({ preventScroll: true });
});
const capsWarning = (event) => {
    if (!(event instanceof KeyboardEvent) || typeof event.getModifierState !== 'function') return;
    const input = event.target instanceof HTMLInputElement ? event.target : null;
    if (!input || !input.closest('[data-password]')) return;
    const warning = document.getElementById(`${input.id}-caps`);
    if (!warning) return;
    warning.hidden = !event.getModifierState('CapsLock');
};
document.addEventListener('keydown', capsWarning);
document.addEventListener('keyup', capsWarning);
// Before a form is sent, hide the typed password again so it is not left visible on screen.
document.addEventListener('submit', (event) => {
    event.target instanceof HTMLFormElement && event.target.querySelectorAll('[data-password] input[type="text"]').forEach((input) => { input.type = 'password'; });
});
