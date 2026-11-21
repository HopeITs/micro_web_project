<?php

return [
    'title' => 'Executive file', 'open' => 'Executive file', 'back' => 'Back to the project', 'cmd' => 'Executive file of',
    'intro' => 'The executive (legal) file of this project. A project has one file; the expiry date is the current date plus the number of months.',
    'read_only' => 'You can read this page; only the administrator and the legal role write executive files.',
    'project' => 'Project', 'project_number' => 'Project number', 'beneficiary' => 'Beneficiary', 'project_name' => 'Project name', 'state' => 'State',
    'file' => 'File', 'new' => 'New executive file', 'none' => 'This project has no executive file.', 'none_yet' => 'This project has no executive file yet.',
    'number' => 'File number', 'begin' => 'Begin date', 'current' => 'Current date', 'current_hint' => 'A new file starts with its current date equal to the begin date.',
    'months' => 'Number of months', 'months_hint' => 'From 0 to 100; the desktop default is 5.', 'expires' => 'Expiry date', 'expires_hint' => 'Current date plus the months; calculated, not stored.',
    'impound_date' => 'Impound date', 'impound_type' => 'Impound type',
    'create' => 'Create the file', 'save' => 'Save the file', 'delete' => 'Delete the file',
    'delete_confirm' => 'Delete this executive file? Its expiry notices are removed too. The deletion is recorded in the audit log.',
    'created' => 'The executive file is created and the legal accounts are notified.', 'saved' => 'The executive file is saved; its notices follow the new expiry date.',
    'deleted' => 'The executive file is deleted and its notices are removed.',
    'notice_hint' => 'Creating the file sends each legal account of the city a notice dated the expiry date; editing moves those notices to the new date; deleting removes them.',
    'no_legal' => 'This city has no legal account to send the expiry notice to. The file is saved without a notice.',
    'errors' => [
        'stale' => 'The executive file changed since this page was opened. Reload the page before saving.',
        'exists' => 'This project already has an executive file; reload the page to edit it.',
        'number' => 'Enter the file number (at most 50 characters).', 'date' => 'Enter a valid date (YYYY-MM-DD).', 'type' => 'The impound type is at most 100 characters.',
        'months' => 'Enter a number of months from 0 to 100.',
    ],
];
