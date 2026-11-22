<?php

return [
    'title' => 'Special cases', 'open' => 'Special cases', 'cmd' => 'New special case', 'back' => 'Back to the project',
    'intro' => "The beneficiary's contacts with the office (phone call or interview): the reason, the details and the action taken. A special case does not change the project state, the loan or the tasks.",
    'read_only' => 'Read only: you may not record special cases.',
    'new' => 'New case', 'edit_title' => 'Edit the case', 'edit' => 'Edit', 'save' => 'Save', 'cancel' => 'Cancel', 'delete' => 'Delete',
    'recorded' => 'Recorded cases (:count)', 'none' => 'No special cases for this project.', 'none_hint' => 'Record the first case with the form above.',
    'hidden_type' => 'hidden',
    'saved' => 'The case was recorded. The form is ready for another case on this project.', 'add_another' => 'Add another case to this project', 'deleted' => 'The case was deleted.',
    'delete_confirm' => 'Are you sure you want to delete this case? The deletion cannot be undone.',
    'fields' => ['date' => 'Date', 'recorder' => 'Recorded by', 'type' => 'Type', 'reason' => 'Reason', 'description' => 'Details', 'action' => 'Action taken'],
    'errors' => [
        'date_required' => 'Enter the date of the case.', 'date' => 'Enter a valid date.', 'date_future' => 'The date cannot be in the future.',
        'recorder' => 'Please choose who recorded the case and try again.', 'type' => 'Please choose the type of the case and try again.',
        'reason' => 'Please write the reason of the case and try again.', 'reason_long' => 'The reason is too long (at most :max characters).', 'long' => 'The text is too long.',
        'none' => 'Please choose a case to delete!', 'stale' => "This project's cases changed since the page was opened. Reload the page and try again.",
        'range' => 'The start date cannot be after the end date',
    ],
    'columns' => ['city' => 'City', 'number' => 'Project number', 'beneficiary' => 'Beneficiary', 'father' => "Father's name", 'career' => 'Career', 'category' => 'Category', 'subcategory' => 'Sub-category',
        'date' => 'Date', 'recorder' => 'Recorded by', 'type' => 'Type', 'reason' => 'Reason', 'description' => 'Details', 'action' => 'Action taken'],
    'list' => [
        'add' => 'New special case',
        'title' => 'Special cases', 'intro' => 'Every special case in the cities you may read, newest first.',
        'from' => 'From', 'to' => 'To', 'state' => 'Project state', 'all' => 'All', 'q' => 'Search', 'q_placeholder' => 'Reason, details, action, beneficiary or project number',
        'total' => ':count cases', 'clear' => 'Clear filters', 'none' => 'No matching cases.', 'none_hint' => 'Change or clear the filters.',
    ],
];
