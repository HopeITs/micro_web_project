<?php

return [
    /*
     * M4.14b: the visits the desktop sync wrote (they carry a Kobo submission id) stay read-only on the web while the desktop is still the
     * system of record for downloads. Switch this on at cut-over, when the sync is retired, to let the web forms correct and delete them too.
     */
    'web_edit_synced' => (bool) env('KOBO_WEB_EDIT_SYNCED', false),
];
