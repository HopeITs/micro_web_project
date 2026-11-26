// Module script: tables (UX restructure, 25 November 2026). Imported by ../app.js; Alpine is provided by Livewire.
//
// Smart tables: every `.hc-table` whose full row set is on the page gets, with no change to its view, an instant
// Arabic-aware search, click-to-sort headers, value lists for columns that repeat, an "x of y rows" count and a
// clear button. Pure logic lives in ./tables-core.js.
//
// A table is left alone when it is shorter than 6 rows (data-smart-min changes it), paginated by the server, grouped
// (rowspan / colspan / several bodies), or holds form fields (re-ordering would disturb wire:model and typing).
// data-smart on the table or any ancestor: "off" never; "search" no sorting (allowed with form fields, hidden rows
// are still submitted); "sort" no search box (the list already has a server search).
//
// Livewire: before a component is morphed its tables are put back exactly as the server rendered them, and are
// enhanced again afterwards with the same search, filters and order. The same is done before wire:navigate keeps a
// copy of the page, so a back click never shows a second toolbar.
import { columnType, filterColumns, haystack, isBlank, matches, nextSort, sortOrder } from './tables-core.js';

const THRESHOLD = 6;
const UI = 'data-ts-ui';

const STRINGS = {
    ar: {
        search: 'بحث في الجدول', placeholder: 'ابحث في الجدول…', all: 'الكل', blank: '(فارغ)', clear: 'مسح',
        count: (shown, total) => `المعروض ${shown} من ${total}`, none: 'لا توجد صفوف مطابقة. غيّر البحث أو امسحه.',
        sortHint: 'اضغط للترتيب', sortBy: 'الترتيب', original: 'الترتيب الأصلي', asc: 'تصاعدي', desc: 'تنازلي',
        totals: 'الإجمالي لكل الصفوف، لا المعروضة فقط', filter: (label) => `تصفية حسب ${label}`,
    },
    en: {
        search: 'Search this table', placeholder: 'Search the table…', all: 'All', blank: '(blank)', clear: 'Clear',
        count: (shown, total) => `Showing ${shown} of ${total}`, none: 'No matching rows. Change or clear the search.',
        sortHint: 'Click to sort', sortBy: 'Sort', original: 'Original order', asc: 'ascending', desc: 'descending',
        totals: 'Totals cover all rows, not only the shown ones', filter: (label) => `Filter by ${label}`,
    },
};

const language = () => (document.documentElement.lang || 'ar').toLowerCase().startsWith('en') ? 'en' : 'ar';

const active = new Map(); // table element -> controller
const memory = new Map(); // table identity -> {query, filters, sort}; forgotten when the page changes
const morphing = new Map(); // Livewire component element -> safety timer
let observer = null;
let scheduled = false;
let navigating = false;
let pendingFocus = null;

// ---------------------------------------------------------------- which tables

/** Text of a cell as people read it: text nodes joined by a space, so two stacked lines never merge into one word. */
function textOf(element) {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT, {
        acceptNode: (node) => (node.parentElement?.closest('script, style, template, option, .hc-sort-ind') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
    });
    const parts = [];
    while (walker.nextNode()) {
        const text = walker.currentNode.nodeValue.trim();
        if (text !== '') parts.push(text);
    }

    return parts.join(' ').replace(/\s+/g, ' ');
}

/** The list is cut into pages by the server: searching here would only see the current page. */
function isPaged(table) {
    const scope = table.closest('.hc-card, .card') || table.parentElement?.parentElement;

    return Boolean(scope?.querySelector('.hc-pagination, .pagination, nav[aria-label="pagination"]'));
}

function analyse(table) {
    const mode = table.closest('[data-smart]')?.getAttribute('data-smart') || 'auto';
    if (mode === 'off' || table.closest('.sr-only, [data-print]') || document.body.matches('[data-print], .print-page')) return null;
    const head = table.tHead;
    if (!head || head.rows.length !== 1 || table.tBodies.length !== 1) return null;
    const headers = [...head.rows[0].cells];
    if (headers.some((cell) => cell.colSpan > 1 || cell.rowSpan > 1)) return null;
    const body = table.tBodies[0];
    const rows = [...body.rows];
    const minimum = Number(table.dataset.smartMin) || THRESHOLD;
    if (rows.length < minimum) return null;
    // Group headings, detail lines and merged cells cannot be re-ordered or hidden one by one.
    if (rows.some((row) => row.cells.length !== headers.length || [...row.cells].some((cell) => cell.colSpan > 1 || cell.rowSpan > 1))) return null;
    const editable = body.querySelector('input:not([type="hidden"]), select, textarea, [contenteditable="true"]') !== null;
    if (editable && mode !== 'search') return null;
    if (isPaged(table)) return null;

    return { headers, body, rows, search: mode !== 'sort', sort: mode !== 'search' && !editable };
}

const rootOf = (table) => table.closest('[wire\\:id]');

/** Stable identity of a table across morphs: its Livewire component, its headers and its position among twins. */
function identity(table) {
    const signature = (t) => [...(t.tHead?.rows[0]?.cells ?? [])].map((cell) => textOf(cell)).join('|');
    const root = rootOf(table);
    const own = signature(table);
    const twins = [...(root ?? document).querySelectorAll('table.hc-table')].filter((t) => signature(t) === own);

    return `${root?.getAttribute('wire:id') ?? 'page'}#${own}#${twins.indexOf(table)}`;
}

// ---------------------------------------------------------------- build

function element(tag, className, attributes = {}) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, value));

    return node;
}

function option(value, label) {
    const node = element('option', '', { value });
    node.textContent = label;

    return node;
}

function build(table, info) {
    const strings = STRINGS[language()];
    const collator = new Intl.Collator(document.documentElement.lang || 'ar', { numeric: true, sensitivity: 'base' });
    const key = identity(table);
    const { headers, body, rows } = info;

    const columns = headers.map((th, index) => {
        const label = textOf(th).replace(/\s*\*$/, '');
        const values = rows.map((row) => textOf(row.cells[index]));
        const sortValues = rows.map((row, i) => row.cells[index].dataset.sort ?? values[i]);

        // A column without a heading holds the row's buttons: it is neither searched, sorted nor filtered.
        return { index, th, label, values, sortValues, type: columnType(sortValues) };
    });
    const searchable = columns.filter((column) => column.label !== '');
    const hays = rows.map((_, i) => haystack(searchable.map((column) => column.values[i])));
    const filters = info.search ? filterColumns(columns, rows.length, collator) : [];
    const sortable = info.sort
        ? searchable.filter((column) => column.th.dataset.sort !== 'off' && !column.th.querySelector('a, button, input, select') && column.values.some((value) => !isBlank(value)))
        : [];

    // Restore what the person had chosen, dropping anything that no longer exists.
    const saved = memory.get(key);
    const state = {
        query: info.search ? (saved?.query ?? '') : '',
        filters: Object.fromEntries(Object.entries(saved?.filters ?? {}).filter(([index, value]) => filters.some((f) => f.index === Number(index) && (value === '\u0000' ? f.blank : f.options.includes(value))))),
        sort: saved?.sort && sortable.some((column) => column.index === saved.sort.column) ? saved.sort : null,
    };

    const controller = {
        table, key, state, rows, body,
        original: [...body.childNodes], // rows, text and Livewire's comment markers, exactly as rendered
        moved: false, nodes: [], buttons: [], footerCell: null,
    };

    // ---- toolbar
    const bar = element('div', 'hc-ts', { [UI]: '', role: 'search', 'aria-label': strings.search });
    let input = null;
    if (info.search) {
        input = element('input', 'hc-input hc-ts-search', { type: 'search', placeholder: strings.placeholder, 'aria-label': strings.search, autocomplete: 'off', 'data-ts-field': 'search' });
        input.value = state.query;
        input.addEventListener('input', () => { state.query = input.value; render(controller); });
        // The table may sit inside a form: Enter in the search box must not submit it.
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') event.preventDefault();
            if (event.key === 'Escape' && input.value !== '') { event.preventDefault(); input.value = ''; state.query = ''; render(controller); }
        });
        bar.append(input);
    }
    const selects = filters.map((filter) => {
        const select = element('select', 'hc-select hc-ts-filter', { 'aria-label': strings.filter(filter.label), 'data-ts-field': `filter-${filter.index}` });
        select.append(option('', `${filter.label}: ${strings.all}`), ...filter.options.map((value) => option(value, value)));
        if (filter.blank) select.append(option('\u0000', strings.blank));
        select.value = state.filters[filter.index] ?? '';
        select.addEventListener('change', () => {
            if (select.value === '') delete state.filters[filter.index]; else state.filters[filter.index] = select.value;
            render(controller);
        });
        bar.append(select);

        return select;
    });
    // On phones a "stack" table has no header row, so the order is chosen from a list.
    let order = null;
    if (sortable.length > 0 && table.classList.contains('stack')) {
        order = element('select', 'hc-select hc-ts-order', { 'aria-label': strings.sortBy, 'data-ts-field': 'order' });
        order.append(option('', `${strings.sortBy}: ${strings.original}`));
        sortable.forEach((column) => order.append(option(`${column.index}:asc`, `${column.label} (${strings.asc})`), option(`${column.index}:desc`, `${column.label} (${strings.desc})`)));
        order.addEventListener('change', () => {
            const [column, dir] = order.value.split(':');
            state.sort = order.value === '' ? null : { column: Number(column), dir };
            render(controller);
        });
        bar.append(order);
    }
    const count = element('span', 'hc-ts-count', { role: 'status', 'aria-live': 'polite' });
    const clear = element('button', 'hc-btn hc-btn-ghost hc-btn-sm hc-ts-clear', { type: 'button' });
    clear.textContent = strings.clear;
    clear.addEventListener('click', () => {
        state.query = '';
        state.filters = {};
        state.sort = null;
        if (input) input.value = '';
        selects.forEach((select) => { select.value = ''; });
        render(controller);
        (input ?? selects[0])?.focus();
    });
    bar.append(count, clear);
    const empty = element('p', 'hc-ts-empty', { [UI]: '' });
    empty.textContent = strings.none;
    empty.hidden = true;

    const wrap = table.parentElement?.classList.contains('hc-table-wrap') ? table.parentElement : table;
    wrap.before(bar);
    wrap.after(empty);
    controller.nodes = [bar, empty];

    // ---- sortable headers
    sortable.forEach((column) => {
        const button = element('button', 'hc-sort hc-ts-sort', { type: 'button', title: strings.sortHint, 'data-ts-field': `sort-${column.index}` });
        const label = element('span', 'hc-ts-label');
        while (column.th.firstChild) label.append(column.th.firstChild);
        button.append(label, element('span', 'hc-sort-ind', { 'aria-hidden': 'true' }));
        button.addEventListener('click', () => { state.sort = nextSort(state.sort, column.index); render(controller); });
        column.th.append(button);
        column.th.setAttribute('aria-sort', 'none');
        controller.buttons.push({ column, button, label });
    });

    controller.render = () => {
        const visible = rows.map((row, i) => matches(hays[i], state.query)
            && Object.entries(state.filters).every(([index, wanted]) => {
                const value = columns[Number(index)].values[i].trim();

                return wanted === '\u0000' ? isBlank(value) : value === wanted;
            }));
        const shown = visible.filter(Boolean).length;
        rows.forEach((row, i) => row.toggleAttribute('data-ts-hidden', !visible[i]));

        if (state.sort) {
            const column = columns[state.sort.column];
            sortOrder(column.sortValues, column.type, state.sort.dir, collator).forEach((i) => body.append(rows[i]));
            controller.moved = true;
        } else {
            restoreOrder(controller);
        }

        controller.buttons.forEach(({ column: c, button }) => {
            const dir = state.sort?.column === c.index ? state.sort.dir : null;
            c.th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : (dir === 'desc' ? 'descending' : 'none'));
            button.classList.toggle('is-sorted', dir !== null);
            button.querySelector('.hc-sort-ind').textContent = dir === 'asc' ? '▲' : (dir === 'desc' ? '▼' : '↕');
        });
        if (order) order.value = state.sort ? `${state.sort.column}:${state.sort.dir}` : '';

        const filtered = shown < rows.length;
        const changed = state.query !== '' || Object.keys(state.filters).length > 0 || state.sort !== null;
        count.textContent = strings.count(shown, rows.length);
        clear.hidden = !changed;
        bar.classList.toggle('is-filtered', filtered);
        empty.hidden = shown !== 0;

        // A footer total was worked out from every row: say so while some rows are hidden.
        controller.footerCell?.removeAttribute('data-ts-note');
        controller.footerCell = table.tFoot?.rows[0]?.cells[0] ?? null;
        if (filtered) controller.footerCell?.setAttribute('data-ts-note', strings.totals);
    };

    active.set(table, controller);
    controller.render();
}

function restoreOrder(controller) {
    if (!controller.moved) return;
    // Only nodes still in the table: one removed by someone else is not brought back.
    controller.original.filter((node) => node.parentNode === controller.body).forEach((node) => controller.body.append(node));
    controller.moved = false;
}

/** A user action: show the new state, remember it, and keep our own DOM changes away from the observer. */
function render(controller) {
    memory.set(controller.key, controller.state);
    controller.render();
    observer?.takeRecords();
}

/** Puts a table back exactly as it was rendered and removes everything added to it. */
function teardown(table) {
    const controller = active.get(table);
    if (!controller) return;
    restoreOrder(controller);
    controller.rows.forEach((row) => row.removeAttribute('data-ts-hidden'));
    controller.buttons.forEach(({ column, button, label }) => {
        if (button.parentElement === column.th) {
            while (label.firstChild) column.th.insertBefore(label.firstChild, button);
            button.remove();
        }
        column.th.removeAttribute('aria-sort');
    });
    controller.footerCell?.removeAttribute('data-ts-note');
    controller.nodes.forEach((node) => node.remove());
    active.delete(table);
}

/** Whether something other than us (Alpine, a script) replaced the rows. */
const stale = (controller) => controller.body.rows.length !== controller.rows.length
    || controller.rows.some((row) => row.parentElement !== controller.body)
    || !controller.nodes[0].isConnected;

// ---------------------------------------------------------------- when

function scan() {
    scheduled = false;
    if (navigating || !document.body) return;
    active.forEach((controller, table) => {
        if (!table.isConnected || stale(controller)) teardown(table);
    });
    document.querySelectorAll('table.hc-table').forEach((table) => {
        if ([...morphing.keys()].some((root) => root.contains(table))) return;
        const controller = active.get(table);
        if (controller) {
            // A pager can appear after the table was enhanced.
            if (isPaged(table)) teardown(table);

            return;
        }
        const info = analyse(table);
        if (info) build(table, info);
    });
    observer?.takeRecords();
}

function schedule() {
    if (scheduled) return;
    scheduled = true;
    // A timer, not requestAnimationFrame: frames stop in a background tab.
    window.setTimeout(scan, 30);
}

function beforeMorph(root) {
    if (!root) return;
    const focused = document.activeElement;
    active.forEach((controller, table) => {
        if (!root.contains(table)) return;
        const bar = controller.nodes[0];
        const sortButton = controller.buttons.find(({ button }) => button === focused);
        if (focused instanceof HTMLElement && (bar.contains(focused) || sortButton)) {
            pendingFocus = { key: controller.key, field: focused.dataset.tsField, start: focused.selectionStart, end: focused.selectionEnd };
        }
        teardown(table);
    });
    window.clearTimeout(morphing.get(root));
    // Should a morph fail, the tables are enhanced again anyway.
    morphing.set(root, window.setTimeout(() => { morphing.delete(root); schedule(); }, 3000));
    observer?.takeRecords();
}

function afterMorph(root) {
    window.clearTimeout(morphing.get(root));
    morphing.delete(root);
    scan();
    if (!pendingFocus) return;
    const controller = [...active.values()].find((c) => c.key === pendingFocus.key);
    const target = controller && (controller.nodes[0].querySelector(`[data-ts-field="${pendingFocus.field}"]`)
        ?? controller.table.querySelector(`[data-ts-field="${pendingFocus.field}"]`));
    if (target && (document.activeElement === document.body || document.activeElement === null)) {
        target.focus({ preventScroll: true });
        if (typeof pendingFocus.start === 'number') target.setSelectionRange?.(pendingFocus.start, pendingFocus.end);
    }
    pendingFocus = null;
}

function reset() {
    [...active.keys()].forEach(teardown);
    morphing.forEach((timer) => window.clearTimeout(timer));
    morphing.clear();
    pendingFocus = null;
}

function start() {
    // Leftovers of a copied page (never expected: pages are cleaned before they are kept).
    document.querySelectorAll(`[${UI}]`).forEach((node) => { if (![...active.values()].some((c) => c.nodes.includes(node))) node.remove(); });
    scan();
    if (!observer) {
        // documentElement stays when wire:navigate swaps the body.
        observer = new MutationObserver(schedule);
        observer.observe(document.documentElement, { childList: true, subtree: true });
    }
}

if (typeof document !== 'undefined') {
    let hooked = false;
    const hook = () => {
        if (hooked || !window.Livewire?.hook) return;
        hooked = true;
        window.Livewire.hook('morph', ({ el }) => beforeMorph(el));
        window.Livewire.hook('morphed', ({ el }) => afterMorph(el));
        window.Livewire.hook('island.morph', ({ component }) => beforeMorph(component.el));
        window.Livewire.hook('island.morphed', ({ component }) => afterMorph(component.el));
    };
    document.addEventListener('livewire:init', hook);
    hook();

    // wire:navigate keeps a copy of the page for the back button: hand it over clean.
    document.addEventListener('livewire:navigating', () => { navigating = true; reset(); memory.clear(); observer?.takeRecords(); });
    document.addEventListener('livewire:navigated', () => { navigating = false; start(); });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
}
