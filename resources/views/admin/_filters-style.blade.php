{{-- Filter forms on the admin pages: an even grid sized by the card, not the window (the sidebar takes part of it).
     Set --cols on the grid for the wide layout (default 4); medium cards get 2 columns, narrow ones 1.
     A field with .grow spans two columns; .hc-filters-actions holds the submit button and fills its cell. --}}
@once
<style>
    .hc-filters { container-type: inline-size; padding: 16px 20px; border-block-end: 1px solid var(--line); }
    .hc-filters-grid { display: grid; grid-template-columns: repeat(var(--cols, 4), minmax(0, 1fr)); gap: 12px 16px; align-items: end; }
    .hc-filters-grid .hc-field { min-width: 0; }
    .hc-filters-grid .grow { grid-column: span 2; }
    .hc-filters-actions { display: flex; gap: 8px; }
    .hc-filters-actions > * { flex: 1 1 auto; justify-content: center; }
    @container (max-width: 860px) { .hc-filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .hc-filters-grid .grow { grid-column: auto; } .hc-filters-actions { grid-column: -2 / -1; } }
    @container (max-width: 480px) { .hc-filters-grid { grid-template-columns: 1fr; } .hc-filters-actions { grid-column: auto; } }
    @media (max-width: 640px) { .hc-filters { padding: 12px 16px; } }
</style>
@endonce
