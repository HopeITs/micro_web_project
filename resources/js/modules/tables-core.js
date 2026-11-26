// Pure logic of the smart tables (used by ./tables.js): Arabic-aware matching, value types and ordering.
// No DOM here, so it can be checked with plain Node.

/** Cell values that mean "nothing entered": they never decide a column's type and always sort last. */
const BLANKS = new Set(['', '—', '-', '–', '…']);

export const isBlank = (value) => BLANKS.has(String(value ?? '').trim());

/** Arabic-Indic and Persian digits (and their separators) as 0-9, "." and ",". */
export function latinDigits(text) {
    return String(text ?? '')
        .replace(/[\u0660-\u0669]/g, (d) => String(d.charCodeAt(0) - 0x0660)) // Arabic-Indic
        .replace(/[\u06F0-\u06F9]/g, (d) => String(d.charCodeAt(0) - 0x06f0)) // Persian
        .replace(/\u066B/g, '.')
        .replace(/\u066C/g, ',')
        .replace(/[\u200B-\u200F\u202A-\u202E\u2066-\u2069\uFEFF]/g, ''); // invisible direction marks
}

/**
 * The form of a text that is compared: hamza carriers lose the hamza, taa marbuta = haa, alif maqsura = yaa,
 * vowel marks and tatweel are dropped, digits are 0-9, Latin accents and case are ignored.
 */
export function normalize(text) {
    return latinDigits(String(text ?? '').normalize('NFKD'))
        // Latin accents, Arabic vowel marks, hamza marks (split off by NFKD), Quranic marks and tatweel.
        .replace(/[\u0300-\u036F\u0610-\u061A\u064B-\u065F\u0670\u06D6-\u06ED\u0640]/g, '')
        .replace(/\u0671/g, '\u0627') // alef wasla = alef
        .replace(/\u0629/g, '\u0647') // taa marbuta = haa
        .replace(/[\u0649\u06CC]/g, '\u064A') // alef maqsura, Persian yaa = yaa
        .replace(/\u06A9/g, '\u0643') // Persian kaf = kaf
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
}

/** What a row is searched in: the normalized cell texts, plus its numbers without thousands separators. */
export function haystack(texts) {
    const text = normalize(texts.join(' '));
    const compact = text.replace(/(\d),(?=\d{3}(\D|$))/g, '$1');

    return compact === text ? text : `${text} ${compact}`;
}

export const words = (query) => normalize(query).split(' ').filter(Boolean);

/** Every typed word must appear somewhere in the row (AND). */
export const matches = (hay, query) => words(query).every((word) => hay.includes(word));

/** A whole cell that is one number (optionally with %, or a currency), else null. Codes such as "DAM-12" are text. */
export function parseNumber(raw) {
    const text = latinDigits(raw).trim();
    const match = /^(?:[$€£₺]\s*)?([-+−]?\s?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?)\s*(?:%|[$€£₺]|SYP|USD|EUR|TRY|ل\.?س\.?)?$/i.exec(text);
    if (!match) return null;
    const value = Number(match[1].replace(/[,\s]/g, '').replace('−', '-'));

    return Number.isFinite(value) ? value : null;
}

/** A cell that starts with a date (Y-M-D with an optional time, or D/M/Y) as a sortable number, else null. */
export function parseDate(raw) {
    const text = latinDigits(raw).trim();
    let match = /^(\d{4})[-/](\d{1,2})[-/](\d{1,2})(?:[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?(?!\d)/.exec(text);
    let parts = match ? [match[1], match[2], match[3], match[4], match[5], match[6]] : null;
    if (!parts) {
        match = /^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})(?!\d)/.exec(text);
        parts = match ? [match[3], match[2], match[1], 0, 0, 0] : null;
    }
    if (!parts) return null;
    const [year, month, day, hour, minute, second] = parts.map((part) => Number(part ?? 0));
    if (month < 1 || month > 12 || day < 1 || day > 31) return null;

    return Date.UTC(year, month - 1, day, hour, minute, second);
}

/** "date" or "number" only when every entered value is one; otherwise "text". */
export function columnType(values) {
    const entered = values.filter((value) => !isBlank(value));
    if (entered.length === 0) return 'text';
    if (entered.every((value) => parseDate(value) !== null)) return 'date';
    if (entered.every((value) => parseNumber(value) !== null)) return 'number';

    return 'text';
}

/**
 * The row positions in sorted order. Blank cells stay last in both directions and equal values keep their
 * original order.
 *
 * @param {string[]} values one per row, in original order
 * @param {'number'|'date'|'text'} type
 * @param {'asc'|'desc'} dir
 * @param {Intl.Collator} collator
 * @returns {number[]}
 */
export function sortOrder(values, type, dir, collator) {
    const parse = type === 'number' ? parseNumber : type === 'date' ? parseDate : (value) => String(value).trim();
    const keys = values.map((value) => (isBlank(value) ? null : parse(value)));
    const sign = dir === 'desc' ? -1 : 1;

    return keys.map((_, i) => i).sort((a, b) => {
        const x = keys[a];
        const y = keys[b];
        if (x === null || y === null) return x === y ? a - b : (x === null ? 1 : -1);
        const result = type === 'text' ? collator.compare(x, y) : x - y;

        return result === 0 ? a - b : result * sign;
    });
}

/** Header click: original order → ascending → descending → original order. */
export function nextSort(current, column) {
    if (!current || current.column !== column) return { column, dir: 'asc' };

    return current.dir === 'asc' ? { column, dir: 'desc' } : null;
}

export const FILTER_MAX_VALUES = 12;

export const FILTER_MAX_COLUMNS = 3;

/**
 * Columns worth a value list: text with a few values that repeat (a state, a city, a type), at most three.
 *
 * @param {{index: number, label: string, type: string, values: string[]}[]} columns
 * @returns {{index: number, label: string, options: string[], blank: boolean}[]}
 */
export function filterColumns(columns, rowCount, collator) {
    const out = [];
    for (const column of columns) {
        if (out.length >= FILTER_MAX_COLUMNS) break;
        if (column.type !== 'text' || column.label === '') continue;
        const trimmed = column.values.map((value) => String(value).trim());
        const options = [...new Set(trimmed.filter((value) => !isBlank(value)))];
        const blank = trimmed.some(isBlank);
        const distinct = options.length + (blank ? 1 : 0);
        if (distinct < 2 || options.length < 1 || options.length > FILTER_MAX_VALUES) continue;
        if (distinct > Math.max(2, Math.floor(rowCount * 0.6)) || options.some((value) => value.length > 40)) continue;
        out.push({ index: column.index, label: column.label, options: options.sort((a, b) => collator.compare(a, b)), blank });
    }

    return out;
}
