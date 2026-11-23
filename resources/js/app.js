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

// Charts (x-hc.chart, smart-search phase 3). Chart.js is installed with npm and imported lazily: the chunk is only fetched on a page
// that has a chart, from our own origin (the CSP allows no CDN). The component reads its data from the figure's data-chart attribute,
// which Livewire updates on every render; a MutationObserver redraws in place (same type) or rebuilds (other type), and destroy()
// releases the instance, observers and listeners when the figure leaves the page, so nothing leaks across updates or navigation.
document.addEventListener('alpine:init', () => {
    let loading = null;
    const loadChart = () => (loading ??= import('chart.js/auto').then((module) => module.default ?? module.Chart));
    const FALLBACK = ['#2a63b8', '#d2690a', '#1d8a52', '#8a46b0', '#0f8fa3', '#be1f24', '#8f7500', '#5d6572'];
    const POINTS = ['circle', 'rect', 'triangle', 'rectRot', 'star', 'cross', 'crossRot', 'rectRounded'];
    const css = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;

    window.Alpine.data('hcChart', () => ({
        chart: null, kind: null, gone: false, observer: null, themeObserver: null, media: null, onTheme: null,
        init() {
            this.observer = new MutationObserver(() => this.draw());
            this.observer.observe(this.$el, { attributes: true, attributeFilter: ['data-chart'] });
            this.onTheme = () => this.draw(true);
            this.media = window.matchMedia('(prefers-color-scheme: dark)');
            this.media.addEventListener('change', this.onTheme);
            this.themeObserver = new MutationObserver(this.onTheme);
            this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
            this.draw();
        },
        destroy() {
            this.gone = true;
            this.observer?.disconnect();
            this.themeObserver?.disconnect();
            this.media?.removeEventListener('change', this.onTheme);
            this.chart?.destroy();
            this.chart = null;
        },
        settings() {
            try { return JSON.parse(this.$el.dataset.chart || 'null'); } catch (_) { return null; }
        },
        async draw(rebuild = false) {
            const cfg = this.settings();
            if (!cfg) return;
            const Chart = await loadChart();
            if (this.gone || !this.$refs || !this.$el.isConnected) return;
            const canvas = this.$el.querySelector('canvas');
            if (!canvas) return;

            const pie = cfg.type === 'pie' || cfg.type === 'doughnut';
            const kind = pie ? cfg.type : (cfg.type === 'line' ? 'line' : 'bar');
            const rtl = document.documentElement.dir === 'rtl';
            const ink = css('--ink-2', '#555');
            const grid = css('--line', '#ddd');
            const surface = css('--surface', '#fff');
            const palette = FALLBACK.map((fallback, i) => css(`--chart-${i + 1}`, fallback));
            const number = new Intl.NumberFormat(document.documentElement.lang || undefined, { maximumFractionDigits: 2 });
            const fmt = (value) => (value === null || value === undefined ? '—' : number.format(value) + (cfg.percent ? '%' : ''));
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
            Chart.defaults.color = ink;

            const datasets = cfg.datasets.map((set, i) => {
                const color = palette[i % palette.length];
                if (pie) {
                    return { label: set.label, data: set.data, backgroundColor: cfg.labels.map((_, j) => palette[j % palette.length]), borderColor: surface, borderWidth: 2 };
                }
                return kind === 'line'
                    ? { label: set.label, data: set.data, borderColor: color, backgroundColor: color, tension: 0.25, pointStyle: POINTS[i % POINTS.length], pointRadius: 5, pointHoverRadius: 7, spanGaps: false }
                    : { label: set.label, data: set.data, backgroundColor: color, borderColor: color, borderWidth: 0, borderRadius: 3 };
            });

            // Values written on the marks (no colour-only reading); skipped when there are too many marks to stay legible.
            const total = cfg.labels.length * cfg.datasets.length;
            const valueLabels = {
                id: 'hcValueLabels',
                afterDatasetsDraw(chart) {
                    if (pie || cfg.type === 'stacked' || total > 36) return;
                    const { ctx } = chart;
                    ctx.save();
                    ctx.fillStyle = ink;
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'bottom';
                    ctx.font = `600 11px ${Chart.defaults.font.family}`;
                    chart.data.datasets.forEach((set, i) => {
                        chart.getDatasetMeta(i).data.forEach((element, j) => {
                            const value = set.data[j];
                            if (value !== null && value !== undefined) ctx.fillText(fmt(value), element.x, element.y - 4);
                        });
                    });
                    ctx.restore();
                },
            };

            const options = {
                responsive: true,
                maintainAspectRatio: false,
                animation: reduced ? false : { duration: 350 },
                layout: { padding: { top: 18 } },
                plugins: {
                    legend: {
                        display: pie || cfg.datasets.length > 1,
                        rtl, textDirection: rtl ? 'rtl' : 'ltr',
                        labels: {
                            color: ink, usePointStyle: true, boxWidth: 10,
                            generateLabels: (chart) => {
                                const items = Chart.defaults.plugins.legend.labels.generateLabels(chart);
                                if (pie) items.forEach((item) => { item.text = `${item.text}: ${fmt(chart.data.datasets[0].data[item.index])}`; item.fontColor = ink; });
                                else items.forEach((item) => { item.fontColor = ink; });
                                return items;
                            },
                        },
                    },
                    tooltip: {
                        rtl, textDirection: rtl ? 'rtl' : 'ltr',
                        callbacks: { label: (item) => `${item.dataset.label ? item.dataset.label + ': ' : ''}${fmt(item.parsed.y ?? item.parsed)}` },
                    },
                },
                scales: pie ? {} : {
                    x: { stacked: cfg.type === 'stacked', reverse: rtl, grid: { display: false }, ticks: { color: ink } },
                    y: { stacked: cfg.type === 'stacked', beginAtZero: true, position: rtl ? 'right' : 'left', grid: { color: grid }, border: { display: false }, ticks: { color: ink, callback: (value) => fmt(value) } },
                },
            };

            if (this.chart && this.kind === cfg.type && !rebuild) {
                this.chart.data.labels = cfg.labels;
                this.chart.data.datasets = datasets;
                this.chart.options = options;
                this.chart.update();
                return;
            }
            this.chart?.destroy();
            this.kind = cfg.type;
            this.chart = new Chart(canvas, { type: kind, data: { labels: cfg.labels, datasets }, options, plugins: [valueLabels] });
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
