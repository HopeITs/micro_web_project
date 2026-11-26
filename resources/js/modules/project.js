// Module script: project (UX restructure, 25 November 2026). Imported by ../app.js; Alpine is provided by Livewire.

// Arabic is matched loosely, as in the type-to-search lists: diacritics are ignored and أ/إ/آ=ا, ة=ه, ى=ي.
const fold = (text) => String(text ?? '').toLowerCase().normalize('NFKD')
    .replace(/[\u064B-\u0652\u0670\u0640]/g, '').replace(/[\u0623\u0625\u0622]/g, '\u0627').replace(/\u0629/g, '\u0647').replace(/\u0649/g, '\u064A')
    .replace(/[\u0300-\u036F\u0653-\u0655]/g, '');

// Papers the user opened lately: the kind of paper only (never a project or a person), kept in this browser.
const PAPERS_KEY = 'mp.project.papers.recent';

document.addEventListener('alpine:init', () => {
    // Tabs of the project page. The chosen tab is the address's #fragment, so a reload, a shared link or "back" keeps it; a link to
    // something inside a tab (#tasks, #lifecycle, #installments) opens that tab and scrolls to it.
    window.Alpine.data('projectTabs', (tabs) => ({
        tab: tabs[0],
        tabs,
        onHash: null,
        onClick: null,
        init() {
            this.onHash = () => this.go(window.location.hash);
            this.onClick = (event) => {
                const link = event.target instanceof Element ? event.target.closest('a[href^="#"]') : null;
                if (link && this.go(link.getAttribute('href'))) event.preventDefault();
            };
            window.addEventListener('hashchange', this.onHash);
            this.$el.addEventListener('click', this.onClick);
            this.$nextTick(() => this.go(window.location.hash));
        },
        destroy() {
            window.removeEventListener('hashchange', this.onHash);
            this.$root?.removeEventListener('click', this.onClick);
        },
        remember(hash) {
            window.history.replaceState(window.history.state, '', `#${hash}`);
        },
        open(name) {
            this.tab = name;
            this.remember(name);
        },
        // Returns false for a fragment that is not part of the tabs, so the browser handles it.
        go(hash) {
            const name = String(hash || '').replace(/^#/, '');
            if (!name) return false;
            let target = null;
            if (this.tabs.includes(name)) {
                this.tab = name;
                target = document.querySelector('.hc-pj-body');
            } else {
                target = document.getElementById(name);
                const panel = target?.closest('[data-tab-panel]');
                if (!panel) return false;
                this.tab = panel.dataset.tabPanel;
                if (name === 'lifecycle') target.querySelector('details')?.setAttribute('open', '');
            }
            this.remember(name);
            this.$nextTick(() => target?.scrollIntoView({ block: 'start' }));
            return true;
        },
        key(event) {
            const rtl = document.documentElement.dir === 'rtl';
            const at = this.tabs.indexOf(this.tab);
            let next = null;
            if (event.key === (rtl ? 'ArrowLeft' : 'ArrowRight')) next = (at + 1) % this.tabs.length;
            else if (event.key === (rtl ? 'ArrowRight' : 'ArrowLeft')) next = (at - 1 + this.tabs.length) % this.tabs.length;
            else if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = this.tabs.length - 1;
            if (next === null) return;
            event.preventDefault();
            this.open(this.tabs[next]);
            event.currentTarget.querySelector(`[data-tab="${this.tabs[next]}"]`)?.focus();
        },
    }));

    // Copy a value. The office address may be plain http, where the clipboard API does not exist: fall back to a selection copy.
    window.Alpine.data('pjCopy', () => ({
        done: false,
        timer: null,
        async copy(text) {
            let ok = true;
            try {
                await navigator.clipboard.writeText(text);
            } catch (_) {
                const area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.append(area);
                area.select();
                ok = document.execCommand('copy');
                area.remove();
            }
            if (!ok) return;
            this.done = true;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => { this.done = false; }, 1600);
        },
    }));

    // The papers hub: search over names and groups, Enter opens the first match, "recently used" from this browser.
    window.Alpine.data('papersHub', () => ({
        query: '',
        recent: [],
        init() { this.load(); },
        read() {
            try {
                const keys = JSON.parse(localStorage.getItem(PAPERS_KEY) || '[]');
                return Array.isArray(keys) ? keys.filter((key) => /^[a-z-]{1,40}$/.test(key)) : [];
            } catch (_) { return []; }
        },
        // Only papers this page offers (this project, this account) appear.
        load() {
            this.recent = this.read().map((key) => {
                const link = this.$root.querySelector(`[data-paper="${key}"]`);
                return link ? { key, href: link.getAttribute('href'), target: link.getAttribute('target') || '_self', label: link.dataset.label } : null;
            }).filter(Boolean);
        },
        remember(key) {
            const keys = [key, ...this.read().filter((other) => other !== key)].slice(0, 5);
            try { localStorage.setItem(PAPERS_KEY, JSON.stringify(keys)); } catch (_) { /* private window */ }
            setTimeout(() => this.load());
        },
        matches(el) {
            const text = fold(el.dataset.search);
            return fold(this.query).split(/\s+/).filter(Boolean).every((word) => text.includes(word));
        },
        groupShown(group) { return [...group.querySelectorAll('[data-search]')].some((item) => this.matches(item)); },
        nothing() { return this.query.trim() !== '' && !this.groupShown(this.$root); },
        openFirst() {
            if (this.query.trim() === '') return;
            [...this.$root.querySelectorAll('[data-search]')].find((item) => this.matches(item))?.querySelector('a')?.click();
        },
    }));

    // Upload area of the attachments panel. The file input keeps wire:model, so choosing and dropping take the same Livewire path.
    window.Alpine.data('attachDrop', () => ({
        over: false,
        uploading: false,
        progress: 0,
        start() { this.uploading = true; this.progress = 0; },
        stop() { this.uploading = false; },
        leave(event) { if (!event.currentTarget.contains(event.relatedTarget)) this.over = false; },
        drop(event) {
            this.over = false;
            const files = event.dataTransfer?.files;
            if (!files?.length) return;
            this.$refs.input.files = files;
            this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
        },
    }));
});
