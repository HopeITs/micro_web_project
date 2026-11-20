<?php

return [
    'title' => 'National number check',
    'intro' => 'Is this national number known anywhere at Hope Center? It is checked in every Micro Projects city and in the DISC and F2F programmes.',
    'check' => 'Check', 'checking' => 'Checking every programme…',
    'hint' => 'Leading zeros, spaces and dashes are ignored. The number is not written to the log.',
    'none_title' => 'Nothing found for this number', 'none' => 'The number is in no city and in neither DISC nor F2F.',
    'found_title' => 'The number is registered (:count)', 'found' => 'Review the results before registering the application.',
    'micro' => 'Micro Projects (all cities)', 'other_programs' => 'Other programmes', 'program' => 'Programme', 'result' => 'Result',
    'projects_state' => 'Projects and state', 'no_project' => 'No project (family member or registered only)', 'no_match' => 'Not found',
    'other_city' => 'Other city', 'other_city_hint' => 'You may not open this city\'s records; only the basic details are shown, as in the desktop app.',
    'registered' => 'Registered', 'unavailable' => 'Could not connect', 'off' => 'Not enabled',
    'f2f_active' => 'Active partnership', 'f2f_inactive' => 'Inactive partnership',
    'cities' => ['ALP' => 'Aleppo', 'DAM' => 'Damascus', 'HMS' => 'Homs', 'LAT' => 'Latakia', 'WAD' => 'Al-Wadi'],
    'from_person' => 'Check in every programme',
    'errors' => ['number' => 'Enter a national number (5 to 14 digits).', 'too_many' => 'Too many checks in a minute. Try again in :seconds seconds.'],
];
