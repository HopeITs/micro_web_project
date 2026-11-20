<?php

return [
    'new' => 'New application', 'edit' => 'Edit application',
    'new_intro' => 'The application form in the paper order. A preliminary application needs only the project description; a full one needs every section. Nothing is saved before "Save".',
    'number' => 'Project number', 'number_hint' => 'Suggested number :number. You may change it; duplicates in the city are checked on save.', 'number_edit_hint' => 'A changed number is checked in the city on save.',
    'stage' => 'Application', 'stage_preliminary_hint' => 'Saved as "On Hold".',
    'stage_full_hint' => 'Completeness is checked before saving; it then becomes "Waiting" and the application-stage tasks open.', 'stage_full_saved' => 'Full application; it does not go back to preliminary.',
    'sections' => [
        'beneficiary' => 'Applicant\'s personal details', 'project' => 'Project form', 'premises' => 'Project premises and budget', 'extra' => 'More information',
        'story' => 'Project story', 'work' => 'Work and education', 'family' => 'Family, losses, health and property', 'aid' => 'Aid, debts and power',
    ],
    'fields' => [
        'project_subcategory_id' => 'Field', 'career_id' => 'Career', 'speciality' => 'Speciality', 'project_type_id' => 'Project type', 'request_date' => 'Request date',
        'project_subtype_id' => 'Type detail', 'name' => 'Project description', 'requested_amount' => 'Requested amount', 'fund_type_id' => 'Funding',
        'place_type_id' => 'Premises type', 'place_address' => 'Premises address', 'place_note' => 'Premises note', 'amper_type' => 'Ampere type', 'amper_number' => 'Amperes',
        'agreement_1' => 'Agreement 1', 'agreement_2' => 'Agreement 2', 'agreement_3' => 'Agreement 3', 'agreement_4' => 'Agreement 4', 'agreement_5' => 'Agreement 5',
        'profit_description' => 'Profit description', 'income_kind' => 'Income kind', 'income_kind_note' => 'Income note', 'street_id' => 'Project area', 'note' => 'Notes',
        'loss_story' => 'Loss (story)', 'goal' => 'Goal of the project', 'goal_how' => 'How the goal is reached', 'other_notes' => 'Other notes',
        'has_work' => 'Works now', 'work_years' => 'Years of experience', 'has_education' => 'Has education', 'has_courses' => 'Attended courses',
        'lives_with_family' => 'Lives with family', 'lives_with_family_note' => 'Note on living with family', 'has_loss' => 'Suffered losses', 'has_illness' => 'Health conditions', 'has_assets' => 'Owns property',
        'has_aid_sources' => 'Helped by organisations', 'aid_source_note' => 'Note on organisations', 'has_debts' => 'Has debts', 'debts_amount' => 'Debts amount', 'debts_note' => 'Debts note', 'has_help' => 'Receives aid',
        'quantity' => 'Quantity', 'price' => 'Price', 'local_contribution' => 'Local contribution', 'comments' => 'Comments', 'risk_id' => 'Risk',
        'contact' => 'Contact number', 'family_number' => 'Family book number', 'current' => 'Current job',
    ],
    'grids' => [
        'family' => 'Family members', 'work_experiences' => 'Work experience', 'educations' => 'Education', 'courses' => 'Courses', 'losses' => 'Losses', 'illnesses' => 'Health conditions',
        'assets' => 'Property', 'aid_sources' => 'Help from organisations', 'aid_received' => 'Aid received', 'power_sources' => 'Home power sources', 'goals' => 'Loan goals',
        'materials' => 'Budget (materials)', 'project_power_sources' => 'Project power sources', 'risks' => 'Risks',
    ],
    'add_row' => 'Add row', 'remove_row' => 'Remove row',
    'pick_beneficiary' => 'Registered beneficiary?', 'pick_hint' => 'Search and pick them to fill their details and family; or fill the fields below for a new person.',
    'registered_person' => 'Registered person', 'open_person' => 'Open their page', 'other_person' => 'Someone else', 'use_this_person' => 'Use this person',
    'family_from_person' => 'The registered person\'s family; managed on their page.',
    'check_ready' => 'Check completeness', 'ready' => 'The full application is ready to save.', 'save' => 'Save', 'save_new' => 'Save application',
    'created' => 'Application saved.', 'updated' => 'Changes saved.',
    'drafts' => [
        'title' => 'My drafts', 'intro' => 'Forms not saved yet. They are kept encrypted every 20 seconds while you type, only you can see them, and they are removed after :days days.',
        'saved' => 'Last kept', 'resume' => 'Resume', 'discard' => 'Delete draft', 'confirm_discard' => 'Delete this draft for good?', 'edit_of_saved' => 'Edit of a saved application',
        'saved_at' => 'Draft kept :time', 'autosave_hint' => 'An encrypted copy of the form is kept automatically so you can recover it if the connection drops or the page closes.',
        'pending_title' => 'This application has an unsaved draft', 'pending' => 'From :time. You may restore it over the current data or delete it.', 'restore' => 'Restore draft',
    ],
    'errors' => [
        'subtype' => 'The type detail does not belong to the chosen project type.', 'number' => 'Enter a valid project number.', 'fix' => 'The form has :count errors; fix them, then save.',
    ],
];
