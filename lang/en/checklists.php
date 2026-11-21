<?php

return [
    'title' => 'Checklist and assessment', 'intro' => "The beneficiary's visits, test and training, the team and committee opinions, and the project score. Each yes/no question opens its details below it.",
    'sections' => ['visits' => 'Visits', 'experience' => 'Experience and training', 'scores' => 'Project assessment', 'decision' => 'Notes and decision'],
    'lists' => ['key_people' => 'Key persons', 'team' => 'Team opinion', 'committee' => 'Committee opinion'],
    'fields' => [
        'has_home_visit' => 'Home visited', 'home_visit_date' => 'Home visit date', 'home_visit_time' => 'Time', 'home_visitor_1_id' => 'First visitor', 'home_visitor_2_id' => 'Second visitor', 'home_visit_note' => 'Visit notes',
        'has_project_visit' => 'Shop visited', 'project_visit_date' => 'Shop visit date', 'project_visit_time' => 'Time', 'project_visitor_1_id' => 'First visitor', 'project_visitor_2_id' => 'Second visitor', 'project_visit_note' => 'Visit notes',
        'visits_report' => 'Visits report',
        'needs_professional_test' => 'Needs a professional test', 'professional_test_need_note' => 'Why',
        'has_professional_test' => 'Professional test done', 'professional_test_date' => 'Test date', 'professional_tester_id' => 'Tester', 'professional_test_note' => 'Test notes', 'professional_test_result' => 'Test result',
        'has_professional_training' => 'Professional training', 'professional_trainer' => 'Trainer', 'professional_training_place' => 'Place', 'professional_training_start' => 'From', 'professional_training_end' => 'To', 'professional_training_note' => 'Notes', 'professional_training_result' => 'Result',
        'has_management_training' => 'Management training', 'management_trainer' => 'Trainer', 'management_training_place' => 'Place', 'management_training_date' => 'Date', 'management_training_result' => 'Result',
        'team_date' => 'Team meeting date', 'committee_date' => 'Committee meeting date', 'notes' => 'Notes',
        'rejection_message' => 'Rejection message sent', 'rejection_message_date' => 'Message date', 'rejection_message_sender_account_id' => 'Sender',
    ],
    'member' => 'Member', 'name_notes' => 'Name', 'role_notes' => 'Role', 'opinion' => 'Opinion', 'reason' => 'Reason', 'add_member' => 'Add member',
    'criterion' => 'Criterion', 'score' => 'Score',
    'save' => 'Save', 'print' => 'Print', 'delete' => 'Delete checklist', 'confirm_delete' => "Delete the checklist, the members' opinions and the project score?",
    'saved' => 'Checklist saved.', 'deleted' => 'Checklist deleted.', 'read_only' => 'Read only: administrators and data entry edit and print it.',
    'print_labels' => [
        'info' => 'Beneficiary', 'name' => 'Name', 'project_no' => 'Project number', 'city' => 'City', 'project_name' => 'Project',
        'name_col' => 'Name', 'position' => 'Member and position', 'role' => 'Role', 'notes' => 'Notes', 'visit' => 'Visits', 'visit_type' => 'Visit',
        'visit_date' => 'Date', 'visit_time' => 'Time', 'visitors' => 'Visitors', 'home' => 'Home', 'project' => 'Shop', 'experience' => 'Experience and training',
        'pro_test' => 'Professional test', 'test_date' => 'Date', 'tester' => 'Tester', 'result' => 'Result', 'pro_train' => 'Professional training', 'trainer' => 'Trainer',
        'place' => 'Place', 'period' => 'Period', 'mng_train' => 'Management training', 'date' => 'Date', 'meeting_date' => 'Meeting date', 'opinion' => 'Opinion',
        'reason' => 'Reason', 'signature' => 'Signature', 'printed' => 'Printed',
    ],
    'errors' => ['needed' => 'Required when the answer is Yes.', 'no_beneficiary' => 'This project has no beneficiary; add one in the application form first.'],
];
