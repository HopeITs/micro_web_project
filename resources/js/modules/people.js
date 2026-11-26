// Module script: people (UX restructure, 25 November 2026). Imported by ../app.js; Alpine is provided by Livewire.
document.addEventListener('alpine:init', () => {
    // Tabs that remember the choice on this device (person page and family page). An address ending in #<tab> wins.
    window.Alpine.data('hcTabs', (storageKey, tabs, first) => ({
        tab: first,
        init() {
            let saved = null;
            try { saved = window.localStorage.getItem(storageKey); } catch (e) { /* private window */ }
            const asked = window.location.hash.slice(1);
            if (tabs.includes(asked)) this.tab = asked;
            else if (tabs.includes(saved)) this.tab = saved;
        },
        select(key) {
            this.tab = key;
            try { window.localStorage.setItem(storageKey, key); } catch (e) { /* not kept */ }
        },
        // Arrow keys move along the tab list (mirrored in right-to-left pages), Home and End jump to its ends.
        keys(event) {
            const rtl = getComputedStyle(event.currentTarget).direction === 'rtl';
            const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[event.key];
            let next = null;
            if (step) next = tabs[(tabs.indexOf(this.tab) + step + tabs.length) % tabs.length];
            if (event.key === 'Home') next = tabs[0];
            if (event.key === 'End') next = tabs[tabs.length - 1];
            if (!next) return;
            event.preventDefault();
            this.select(next);
            event.currentTarget.querySelector(`[data-tab="${next}"]`)?.focus();
        },
    }));

    // Copy a number to the clipboard (data-copy); older browsers and plain http fall back to a hidden field.
    window.Alpine.data('hcCopy', () => ({
        done: false,
        async copy() {
            const text = this.$root.dataset.copy;
            try {
                await navigator.clipboard.writeText(text);
            } catch (e) {
                const field = document.createElement('textarea');
                field.value = text;
                field.setAttribute('readonly', '');
                field.style.position = 'fixed';
                field.style.opacity = '0';
                document.body.appendChild(field);
                field.select();
                const copied = document.execCommand('copy');
                field.remove();
                if (!copied) return;
            }
            this.done = true;
            setTimeout(() => { this.done = false; }, 1600);
        },
    }));
});
