<?php

// Project page (restructure, 25 November 2026).
return [
    'open_person' => 'Open the person page', 'call' => 'Call', 'copy' => 'Copy :what', 'copied' => 'Copied', 'no_applicant' => 'No applicant is recorded for this project.',
    'current_state' => 'Current state',
    'modules' => 'Project sections', 'needs_attention' => 'Needs attention',
    'next_installment' => 'Next instalment', 'show_installments' => 'Show instalments (:count)',
    'tabs' => ['label' => 'Project content', 'overview' => 'Overview', 'progress' => 'State and tasks', 'attachments' => 'Attachments', 'papers' => 'Papers'],
    'attention' => [
        'title' => 'Needs your attention',
        'overdue' => '{1} One overdue instalment of :money|[2,*] :count overdue instalments, :money in total',
        'national' => 'The applicant has no national number recorded.',
        'mobile' => 'The applicant has no mobile number recorded.',
        'scores' => 'The full application has no project analysis scores.',
        'attachments' => 'This project has no attachments.',
        'actions' => ['finance' => 'Open finance', 'installments' => 'Show the loan', 'person' => 'Edit the person', 'scores' => 'Fill the scores', 'attachments' => 'Add attachments'],
    ],
    'tile' => [
        'loan' => 'Loan :money', 'paid' => ':percent% paid', 'next_due' => 'Next instalment :date', 'last' => 'Latest :date',
        'visits' => '{0} No visits yet|{1} One visit|[2,*] :count visits',
        'cases' => '{0} No cases recorded|{1} One case|[2,*] :count cases',
        'purchases' => '{0} No items recorded|{1} One item|[2,*] :count items',
        'checklist_done' => 'Saved', 'checklist_none' => 'Not filled yet',
        'executive_none' => 'No file yet', 'executive_has' => 'File recorded', 'executive_number' => 'File no. :number',
        'annex_done' => 'Filled', 'annex_none' => 'Not filled yet',
    ],
    'papers' => [
        'button' => 'Papers and printing', 'title' => 'Project papers', 'search' => 'Find a paper…', 'recent' => 'Recently used', 'none' => 'No paper matches.',
        'entry' => 'Data entry', 'new_tab' => 'opens for printing in a new tab',
        'groups' => ['application' => 'Application and file', 'consents' => 'Consents', 'finance' => 'Financial and legal papers', 'purchasing' => 'Purchasing and sales'],
    ],
];
