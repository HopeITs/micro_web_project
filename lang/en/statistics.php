<?php

return [
    'title' => 'Statistics', 'intro' => 'Count projects by one axis, or two together, across your cities with the same filters as the search. Press a bar to filter by it, or a number to open the matching applications. Read only.',
    'axis' => 'Count by', 'show' => 'Show', 'show_both' => 'All and funded', 'total' => 'Total', 'share' => 'Share', 'table' => 'Statistics table', 'none' => 'No projects match',
    'under_filters' => 'Under the chosen filters', 'no_filters' => 'In all your cities, unfiltered', 'request_year' => 'Request year', 'fund_year' => 'Fund year', 'not_recorded' => 'Not recorded', 'individual' => 'Individual', 'partners' => ':count partners',
    'no_rate' => 'No exchange rate', 'zero_or_less' => 'Zero or less', 'over' => 'Over :value', 'under' => 'Under :value',
    'note' => 'Each project counts once. Funded = Financed, Finished, Canceled or Closed. Parish, sex, marital status and age are the project owner\'s (first beneficiary).',
    'by' => 'Second dimension', 'by_none' => 'None', 'by_city' => 'City', 'crossed_title' => ':axis by :by', 'sort' => 'Order', 'top' => 'Rows', 'top_n' => 'Largest :count', 'hide_zero' => 'Hide empty values',
    'sorts' => ['default' => 'Axis order', 'desc' => 'Largest first', 'asc' => 'Smallest first', 'name' => 'A to Z'],
    'others' => 'Others (:count)', 'not_applicable' => 'Does not apply', 'measuring' => 'Counting: :series · total :count', 'of_all' => 'of all projects', 'headline' => 'Headline numbers',
    'kpi_filter' => 'Press to show only these projects', 'kpi_unfilter' => 'Press to go back to all projects', 'no_overlap' => 'No state is in both this group and the chosen state filter.',
    'none_on_axis' => 'No values on this axis', 'none_on_axis_hint' => 'The matching projects do not fall on this axis. Choose another one.', 'chart_hbar' => 'Horizontal bars', 'chart_grouped' => 'Grouped bars',
    'press_bar' => 'Press a bar or slice for filtering and opening the applications.', 'filter_by_this' => 'Filter by this value', 'filter_by' => 'Filter by :value', 'open_in_search' => 'Open applications',
    'city_counts' => 'City columns count: :series', 'outside_loan' => 'This axis counts projects with a loan; :count of the matching projects are outside it.',
    'outside_sustainability' => 'This axis counts ongoing or closed projects with a loan date; :count of the matching projects are outside it.',
    'reports_title' => 'Ready reports',
    'reports' => [
        'status' => 'Project states', 'status_city' => 'States by city', 'requests_year' => 'This year\'s requests by type', 'top_donors' => 'Largest donors', 'funding_years' => 'Funding by year and type',
        'age_sex' => 'Ages by sex', 'top_categories' => 'Most funded categories', 'sustainability' => 'Sustainability',
    ],
    'series' => ['all' => 'All projects', 'funded' => 'Funded projects', 'ongoing' => 'Ongoing (financed, finished)', 'closed' => 'Closed (canceled, closed)'],
    'axes' => [
        'status' => 'Project status', 'fund_type' => 'Funding type', 'project_type' => 'Project type', 'subtype' => 'Type detail (new / developing)', 'category' => 'Category', 'subcategory' => 'Sub category',
        'partnership' => 'Partnership', 'parish' => 'Parish', 'sex' => 'Sex', 'marital' => 'Marital status', 'age' => 'Age at request', 'donor' => 'Donor', 'fund_year' => 'Fund year',
        'fund_amount' => 'Fund amount (USD)', 'sustainability' => 'Sustainability (years)',
    ],
];
