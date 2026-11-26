// Module script: insights (UX restructure, 25 November 2026). Imported by ../app.js; Alpine is provided by Livewire.
//
// The browser side of the search and statistics filters (x-hc.report-filters, x-hc.multi-filter) and of the search type picker.
// Everything that decides the rows happens on the server; here are only focus, the search inside a checklist, "tick shown" /
// "invert", folding away empty values, and printing.

// Arabic matched loosely, as in the app's other pickers: diacritics and tatweel ignored, أ/إ/آ/ٱ = ا, ة = ه, ى = ي.
const fold = (text) => String(text ?? '').toLowerCase().normalize('NFKD')
    .replace(/[\u064B-\u065F\u0670\u0640\u0300-\u036F]/g, '').replace(/[\u0623\u0625\u0622\u0671]/g, '\u0627').replace(/\u0629/g, '\u0647').replace(/\u0649/g, '\u064A');
const words = (query) => fold(query).split(/\s+/).filter(Boolean);
const matches = (text, wanted) => { const hay = fold(text); return wanted.every((word) => hay.includes(word)); };

document.addEventListener('alpine:init', () => {
    // The panel of one filter: a popover under its chip, a bottom sheet on phones.
    window.Alpine.data('insEditor', (key) => ({
        sheet: window.matchMedia('(max-width: 640px)').matches,
        init() {
            // On a phone the search box is not focused, so the keyboard does not cover the sheet.
            this.$nextTick(() => requestAnimationFrame(() => {
                const target = this.sheet ? null : this.$root.querySelector('[data-autofocus]');
                (target ?? this.$root).focus({ preventScroll: true });
            }));
        },
        close() {
            const back = () => (document.getElementById(`flt-${key}`) ?? document.querySelector('.ins-add-button'))?.focus({ preventScroll: true });
            this.$wire.closeFilter().then(() => setTimeout(back, 30));
        },
        outside(event) {
            // Another chip opens its own panel; an element already replaced by an update is not "outside".
            if (!event.target.isConnected || event.target.closest('[data-flt-trigger], .ins-add')) return;
            this.$wire.closeFilter();
        },
    }));

    // The checklist inside the panel. The server renders every value with its count and whether it is ticked.
    window.Alpine.data('insOptions', () => ({
        q: '', empty: false, shown: 1, hidden: 0, observer: null,
        init() {
            this.$watch('q', () => this.apply());
            this.$watch('empty', () => this.apply());
            // An update from the server replaces the attributes (and drops `hidden`), so the list is folded again after it.
            this.observer = new MutationObserver(() => this.apply());
            this.observer.observe(this.$refs.list, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-selected', 'data-count', 'hidden'] });
            this.apply();
        },
        destroy() { this.observer?.disconnect(); },
        items() { return [...this.$refs.list.querySelectorAll('li')]; },
        apply() {
            const wanted = words(this.q);
            let shown = 0;
            let hidden = 0;
            for (const item of this.items()) {
                const ticked = item.dataset.selected === '1';
                const input = item.querySelector('input');
                if (input && input.checked !== ticked) input.checked = ticked;
                const empty = !ticked && Number(item.dataset.count) === 0;
                if (empty) hidden += 1;
                // Values that would give nothing wait behind their button, unless they are searched for.
                const visible = matches(item.dataset.label, wanted) && (!empty || this.empty || wanted.length > 0);
                if (item.hidden === visible) item.hidden = !visible;
                if (visible) shown += 1;
            }
            this.shown = shown;
            this.hidden = hidden;
        },
        visible() { return this.items().filter((item) => !item.hidden); },
        ticked() { return this.items().filter((item) => item.dataset.selected === '1').map((item) => item.dataset.value); },
        all(key) {
            this.$wire.setValues(key, [...new Set([...this.ticked(), ...this.visible().map((item) => item.dataset.value)])]);
        },
        invert(key) {
            const chosen = new Set(this.ticked());
            this.visible().forEach((item) => { chosen.has(item.dataset.value) ? chosen.delete(item.dataset.value) : chosen.add(item.dataset.value); });
            this.$wire.setValues(key, [...chosen]);
        },
        focusFirst() { this.visible()[0]?.querySelector('input')?.focus(); },
        step(event, direction) {
            const inputs = this.visible().map((item) => item.querySelector('input'));
            const next = inputs[inputs.indexOf(event.target) + direction];
            next?.focus();
        },
        // Enter in the search box ticks the value when the search leaves exactly one.
        tickOnly() { const list = this.visible(); if (this.q !== '' && list.length === 1) list[0].querySelector('input')?.click(); },
    }));

    // "Search by": the types as grouped cards, found by typing.
    window.Alpine.data('insPicker', () => ({
        open: false, q: '', shown: 1,
        init() { this.$watch('q', () => this.apply()); },
        toggle() { this.open ? this.close() : this.show(); },
        show() {
            this.open = true;
            this.q = '';
            this.apply();
            this.$nextTick(() => requestAnimationFrame(() => {
                if (!window.matchMedia('(max-width: 640px)').matches) this.$refs.find.focus({ preventScroll: true });
                else this.$refs.list.querySelector('[aria-current]')?.focus();
            }));
        },
        close(back = true) {
            if (!this.open) return;
            this.open = false;
            if (back) this.$refs.trigger.focus({ preventScroll: true });
        },
        cards() { return [...this.$refs.list.querySelectorAll('[data-mode]')]; },
        apply() {
            const wanted = words(this.q);
            let shown = 0;
            this.cards().forEach((card) => { const visible = matches(card.dataset.find, wanted); card.hidden = !visible; if (visible) shown += 1; });
            this.$refs.list.querySelectorAll('[data-group]').forEach((group) => { group.hidden = !group.querySelector('[data-mode]:not([hidden])'); });
            this.shown = shown;
        },
        visible() { return this.cards().filter((card) => !card.hidden); },
        move(direction) {
            const list = this.visible();
            if (!list.length) return;
            const at = list.indexOf(document.activeElement);
            const next = at < 0 ? (direction > 0 ? 0 : list.length - 1) : (at + direction + list.length) % list.length;
            list[next].focus();
        },
        first() { this.visible()[0]?.click(); },
    }));
});

// Print what is shown. The charts are drawings made with the screen's colours, so the page is switched to the light theme (they
// redraw themselves) before the print dialog opens, and put back afterwards.
document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-ins-print]') : null;
    if (!button) return;
    const root = document.documentElement;
    const before = root.dataset.theme;
    root.dataset.theme = 'light';
    setTimeout(() => {
        window.print();
        if (before) root.dataset.theme = before; else delete root.dataset.theme;
    }, 600);
});
