<?php

return [
    'title' => 'Lists',
    'intro' => 'The values offered in the forms\' lists. Streets, priests and checklist members belong to each city; the other lists are shared by all five cities.',
    'groups' => ['people' => 'People and applications', 'finance' => 'Funding', 'projects' => 'Project states', 'checklist' => 'Checklist'],
    'names' => [
        'streets' => 'Areas (streets)', 'priests' => 'Priests', 'education_specialties' => 'Education specialties', 'education_types' => 'Education degrees',
        'occupations' => 'Occupations and experience', 'aid_sources' => 'Aid sources', 'aid_types' => 'Aid types', 'donors' => 'Donors',
        'donor_groups' => 'Donor groups', 'purchasing_categories' => 'Purchasing categories', 'state_reasons' => 'State reasons', 'checklist_members' => 'Checklist members', 'checklist_positions' => 'Checklist positions',
    ],
    'city_list' => 'Per city', 'shared_list' => 'Shared by all cities', 'read_only' => 'Read only',
    'read_only_hint' => 'This list is shared by all five cities, so only an account that may edit the lists in every city can change it.',
    'counts' => ':active shown · :hidden hidden', 'open' => 'Open',
    'back' => 'All lists', 'city' => 'City', 'search' => 'Search', 'search_placeholder' => 'Part of the name',
    'show_hidden' => 'Show hidden values', 'results' => ':count values',
    'name_ar' => 'Arabic name', 'name_en' => 'English name', 'used' => 'Used in', 'used_count' => ':count records', 'unused' => 'Unused', 'status' => 'Status', 'scope' => 'Scope',
    'active' => 'Shown', 'hidden' => 'Hidden',
    'add' => 'Add a value', 'add_title' => 'New value', 'save' => 'Save', 'cancel' => 'Cancel', 'edit' => 'Edit', 'hide' => 'Hide', 'show' => 'Show', 'delete' => 'Delete',
    'confirm_delete' => 'Delete this value? If records use it, it is only hidden and stays on the old records.',
    'created' => 'Value added.', 'updated' => 'Change saved.', 'hidden_msg' => 'Value hidden: new forms no longer offer it and old records keep it.',
    'shown_msg' => 'Value shown again.', 'deleted_msg' => 'Value deleted.', 'hidden_used_msg' => 'Records use this value, so it was hidden instead of deleted.',
    'export' => 'Export', 'empty' => 'No values.', 'empty_hint' => 'Change the search or add a value.',
    'fields' => ['education_type_id' => 'Degree', 'abbreviation' => 'Abbreviation', 'donor_id' => 'Donor', 'checklist_position_id' => 'Position', 'type' => 'Category'],
    'choices' => ['type' => ['t' => 'Visitors (team)', 'k' => 'Key persons', 'c' => 'Committee members']],
    'choose' => 'Choose…', 'none' => '—',
    'errors' => [
        'name' => 'Enter an Arabic name (at most 255 characters).', 'duplicate' => 'This value is already in the list.', 'required' => 'Choose or enter a valid value.',
    ],
];
