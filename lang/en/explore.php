<?php

return [
    'title' => 'Explore', 'intro' => 'One page for search, statistics and reports: pick the data, narrow it with filters, then switch between list, pivot table and chart without losing the filters.',
    'dataset' => 'Data', 'questions' => 'Ready questions', 'kpis' => 'Key figures', 'kpi_count' => 'Records',
    'views' => 'View', 'view_list' => 'List', 'view_pivot' => 'Pivot table', 'view_chart' => 'Chart',
    'drill_hint' => 'Click any number to open the list with the same filters plus that cell.',
    'ask_invalid' => 'The question could not be applied to this data.',
    'notice' => 'The Explore page brings search, statistics and reports together with the same filters.', 'notice_link' => 'Open Explore',
    'q' => [
        'projects' => [
            'by_state' => 'Projects by state', 'state_by_city' => 'State by city', 'by_fund_type' => 'Fund type', 'by_project_type' => 'Project type',
            'by_donor' => 'Donor', 'by_sex' => 'Sex', 'by_stage' => 'Stage by state', 'by_year' => 'Requests by year',
            'full_stage' => 'Applications in the full stage', 'overdue' => 'Projects with overdue instalments', 'this_year' => 'Requests this year',
        ],
        'people' => [
            'by_sex' => 'People by sex', 'by_marital' => 'Marital status', 'by_education' => 'Education level', 'by_city' => 'People by city',
            'sex_by_city' => 'Sex by city', 'with_projects' => 'With projects, by city', 'without_projects' => 'People without projects',
        ],
        'installments' => [
            'status_by_currency' => 'Instalment amounts by status', 'due_by_month' => 'Due by month', 'by_donor' => 'Instalments by donor',
            'by_state' => 'Project state by instalment status', 'by_city' => 'Instalments by city', 'overdue' => 'Overdue instalments', 'this_month' => 'Due this month',
        ],
    ],
];
