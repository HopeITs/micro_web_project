// Alpine is provided by Livewire; do not initialize a second instance.
import Sortable from 'sortablejs';
import './modules/project';
import './modules/people';
import './modules/tables';
import './modules/insights';

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
// Optional attributes on the figure (statistics page): data-height (pixels, as the drawing area is outside Livewire's updates),
// data-horizontal="1" (bars lie down, for long labels), data-other="series"|"slice" (the last series or slice is "others": grey),
// data-keys (JSON, one per label; a press on a mark then sends "hc-chart-pick" with the key, "" = nothing to pick).
document.addEventListener('alpine:init', () => {
    let loading = null;
    const loadChart = () => (loading ??= import('chart.js/auto').then((module) => module.default ?? module.Chart));
    const FALLBACK = ['#2a63b8', '#d2690a', '#1d8a52', '#8a46b0', '#0f8fa3', '#be1f24', '#8f7500', '#5d6572'];
    const POINTS = ['circle', 'rect', 'triangle', 'rectRot', 'star', 'cross', 'crossRot', 'rectRounded'];
    const charts = new WeakMap(); // Chart instances stay outside Alpine's reactive state (a proxied chart recurses forever)
    const looks = new WeakMap(); // per canvas: the colours and number format of the last draw, for the value labels
    const css = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;

    window.Alpine.data('hcChart', () => ({
        kind: null, gone: false, observer: null, themeObserver: null, media: null, onTheme: null,
        init() {
            this.observer = new MutationObserver(() => this.draw());
            this.observer.observe(this.$el, { attributes: true, attributeFilter: ['data-chart', 'data-height', 'data-horizontal', 'data-other', 'data-keys', 'data-total-label'] });
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
            charts.get(this.$el)?.destroy();
            charts.delete(this.$el);
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

            const figure = this.$el;
            const pie = cfg.type === 'pie' || cfg.type === 'doughnut';
            const kind = pie ? 'doughnut' : (cfg.type === 'line' ? 'line' : 'bar'); // X4: every round chart is a doughnut with its total in the centre
            const horizontal = kind === 'bar' && figure.dataset.horizontal === '1';
            const pickable = figure.dataset.keys !== undefined;
            const other = figure.dataset.other || '';
            const shape = horizontal ? `${cfg.type}-h` : cfg.type;
            const height = Number(figure.dataset.height || 0);
            if (height > 0) figure.querySelector('.hc-chart-canvas').style.height = `${height}px`;
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

            const neutral = palette[palette.length - 1];
            const muted = css('--muted', '#6b7079');
            const line = css('--line', '#e7e9ee');
            // X4 "Soft & airy": a colour fading to a lighter tint along the value axis (bars), or to nothing (line areas).
            const tint = (hex, alpha) => {
                const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
                if (!m) return hex;
                const n = parseInt(m[1], 16);
                return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
            };
            const fade = (color, from, to, area) => (context) => {
                const { chart } = context;
                const box = chart.chartArea;
                if (!box) return tint(color, from);
                const g = area || !horizontal
                    ? chart.ctx.createLinearGradient(0, box.bottom, 0, box.top)
                    : chart.ctx.createLinearGradient(rtl ? box.right : box.left, 0, rtl ? box.left : box.right, 0);
                g.addColorStop(0, tint(color, from));
                g.addColorStop(1, tint(color, to));
                return g;
            };
            const single = cfg.datasets.length === 1;
            const datasets = cfg.datasets.map((set, i) => {
                const color = other === 'series' && i === cfg.datasets.length - 1 ? neutral : palette[i % palette.length];
                if (pie) {
                    const slice = (j) => (other === 'slice' && j === cfg.labels.length - 1 ? neutral : palette[j % palette.length]);
                    return { label: set.label, data: set.data, backgroundColor: cfg.labels.map((_, j) => slice(j)), hoverBackgroundColor: cfg.labels.map((_, j) => slice(j)), borderColor: surface, borderWidth: 3, borderRadius: kind === 'doughnut' ? 6 : 0, hoverOffset: 6, spacing: kind === 'doughnut' ? 1 : 0 };
                }
                if (kind === 'line') {
                    return { label: set.label, data: set.data, borderColor: color, backgroundColor: single ? fade(color, 0, 0.22, true) : tint(color, 0.06), fill: single ? 'origin' : false,
                        tension: 0.35, borderWidth: 2.5, pointStyle: POINTS[i % POINTS.length], pointRadius: cfg.labels.length > 24 ? 0 : 3.5, pointHoverRadius: 6,
                        pointBackgroundColor: surface, pointBorderColor: color, pointBorderWidth: 2, spanGaps: false };
                }
                const stacked = cfg.type === 'stacked';
                return { label: set.label, data: set.data, backgroundColor: stacked ? color : fade(color, 0.55, 1), hoverBackgroundColor: color,
                    borderColor: stacked ? surface : color, borderWidth: stacked ? 1 : 0, borderRadius: stacked ? 4 : 8, borderSkipped: stacked ? 'start' : false,
                    maxBarThickness: 44, categoryPercentage: 0.72, barPercentage: 0.86 };
            });

            // A doughnut names its total in the hole (the legend carries each slice and its value).
            const centreTotal = {
                id: 'hcCentreTotal',
                afterDraw(chart) {
                    if (chart.config.type !== 'doughnut') return;
                    const meta = chart.getDatasetMeta(0);
                    const arc = meta?.data?.[0];
                    if (!arc) return;
                    const look = looks.get(chart.canvas);
                    const total = (chart.data.datasets[0]?.data || []).reduce((sum, v, j) => sum + (chart.getDataVisibility(j) ? (Number(v) || 0) : 0), 0);
                    const { ctx } = chart;
                    const size = Math.max(14, Math.min(30, arc.innerRadius / 2.6));
                    ctx.save();
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillStyle = look?.strong || '#1d2025';
                    ctx.font = `700 ${size}px ${Chart.defaults.font.family}`;
                    ctx.fillText(look ? look.fmt(total) : String(total), arc.x, arc.y - size * 0.18);
                    if (look?.totalLabel) {
                        ctx.fillStyle = look.muted;
                        ctx.font = `500 ${Math.max(11, size * 0.42)}px ${Chart.defaults.font.family}`;
                        ctx.fillText(look.totalLabel, arc.x, arc.y + size * 0.72);
                    }
                    ctx.restore();
                },
            };

            // Values written on the marks (no colour-only reading); skipped when there are too many marks to stay legible.
            // The plugin stays with the chart across updates, so it reads everything from the chart as it is now.
            const valueLabels = {
                id: 'hcValueLabels',
                afterDatasetsDraw(chart) {
                    const look = looks.get(chart.canvas);
                    const stacked = chart.options.scales?.x?.stacked || chart.options.scales?.y?.stacked;
                    const marks = chart.data.labels.length * chart.data.datasets.length;
                    const lying = chart.options.indexAxis === 'y';
                    if (!look || chart.config.type === 'pie' || chart.config.type === 'doughnut' || stacked || marks > (lying ? 60 : 36)) return;
                    const { ctx } = chart;
                    ctx.save();
                    ctx.fillStyle = look.ink;
                    ctx.textAlign = lying ? (look.rtl ? 'right' : 'left') : 'center';
                    ctx.textBaseline = lying ? 'middle' : 'bottom';
                    ctx.font = `600 11px ${Chart.defaults.font.family}`;
                    chart.data.datasets.forEach((set, i) => {
                        chart.getDatasetMeta(i).data.forEach((element, j) => {
                            const value = set.data[j];
                            if (value === null || value === undefined) return;
                            if (lying) ctx.fillText(look.fmt(value), element.x + (look.rtl ? -6 : 6), element.y);
                            else ctx.fillText(look.fmt(value), element.x, element.y - 4);
                        });
                    });
                    ctx.restore();
                },
            };

            const options = {
                responsive: true,
                maintainAspectRatio: false,
                animation: reduced ? false : { duration: 420, easing: 'easeOutQuart' },
                indexAxis: horizontal ? 'y' : 'x',
                cutout: kind === 'doughnut' ? '70%' : undefined,
                // Room for the numbers at the end of lying bars; on a narrow card, for slanted labels on the side they run to.
                layout: { padding: horizontal ? { [rtl ? 'left' : 'right']: 44 } : { top: 18, [rtl ? 'left' : 'right']: !pie && figure.clientWidth < 480 ? 28 : 0 } },
                plugins: {
                    legend: {
                        display: pie || cfg.datasets.length > 1,
                        rtl, textDirection: rtl ? 'rtl' : 'ltr',
                        position: pie ? (figure.clientWidth > 520 ? (rtl ? 'left' : 'right') : 'bottom') : 'top',
                        align: pie ? 'center' : 'end',
                        labels: {
                            color: ink, usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 14,
                            generateLabels: (chart) => {
                                // A pie names its slices (the type's own list); other charts name their series.
                                const source = (pie && Chart.overrides[kind]?.plugins?.legend?.labels?.generateLabels) || Chart.defaults.plugins.legend.labels.generateLabels;
                                const items = source(chart);
                                if (pie) items.forEach((item) => { item.text = `${item.text}: ${fmt(chart.data.datasets[0].data[item.index])}`; item.fontColor = ink; });
                                else items.forEach((item) => { item.fontColor = ink; });
                                return items;
                            },
                        },
                    },
                    tooltip: {
                        rtl, textDirection: rtl ? 'rtl' : 'ltr',
                        backgroundColor: surface, titleColor: css('--ink', '#1d2025'), bodyColor: ink, borderColor: line, borderWidth: 1,
                        padding: 12, cornerRadius: 10, boxPadding: 6, usePointStyle: true, caretSize: 6,
                        titleFont: { weight: '700' }, bodyFont: { weight: '500' },
                        callbacks: { label: (item) => `${item.dataset.label ? item.dataset.label + ': ' : ''}${fmt((horizontal ? item.parsed.x : item.parsed.y) ?? item.parsed)}` },
                    },
                },
                scales: pie ? {} : (horizontal ? {
                    x: { stacked: cfg.type === 'stacked', beginAtZero: true, reverse: rtl, grid: { color: tint(grid, 0.9), drawTicks: false }, border: { display: false, dash: [4, 4] }, ticks: { color: muted, precision: 0, padding: 8, callback: (value) => fmt(value) } },
                    y: { stacked: cfg.type === 'stacked', position: rtl ? 'right' : 'left', grid: { display: false }, border: { display: false }, ticks: { color: ink, autoSkip: false, padding: 6 } },
                } : {
                    x: { stacked: cfg.type === 'stacked', reverse: rtl, grid: { display: false }, border: { color: grid }, ticks: { color: ink, padding: 6 } },
                    y: { stacked: cfg.type === 'stacked', beginAtZero: true, position: rtl ? 'right' : 'left', grid: { color: tint(grid, 0.9), drawTicks: false }, border: { display: false, dash: [4, 4] }, ticks: { color: muted, padding: 8, callback: (value) => fmt(value) } },
                }),
            };
            if (pickable) {
                // The whole column or row answers the pointer, not only the painted bar.
                if (!pie) options.interaction = { mode: 'index', axis: horizontal ? 'y' : 'x', intersect: false };
                options.onHover = (event, elements) => { if (event.native?.target) event.native.target.style.cursor = elements.length ? 'pointer' : 'default'; };
                options.onClick = (_, elements) => {
                    if (!elements.length) return;
                    let keys = [];
                    try { keys = JSON.parse(figure.dataset.keys || '[]'); } catch (_error) { return; }
                    const key = String(keys[elements[0].index] ?? '');
                    if (key !== '') figure.dispatchEvent(new CustomEvent('hc-chart-pick', { bubbles: true, detail: { key } }));
                };
            }

            looks.set(canvas, { ink, rtl, fmt, muted, strong: css('--ink', '#1d2025'), totalLabel: figure.dataset.totalLabel || '' });
            const existing = charts.get(this.$el);
            if (existing && this.kind === shape && !rebuild) {
                existing.data.labels = cfg.labels;
                existing.data.datasets = datasets;
                existing.options = options;
                existing.update();
                return;
            }
            existing?.destroy();
            this.kind = shape;
            charts.set(this.$el, new Chart(canvas, { type: kind, data: { labels: cfg.labels, datasets }, options, plugins: [valueLabels, centreTotal] }));
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
