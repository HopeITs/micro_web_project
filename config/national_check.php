<?php

/*
 * M4.4: the other Hope Center programmes checked for a national number, as the desktop Check_NationalNumber_Form did
 * (owner decision M0.5: through a read-only connection). Each source names its database, table and columns; the
 * connection user needs SELECT on exactly these columns. Unreachable sources are reported, never fatal.
 */
return [
    'enabled' => (bool) env('NATIONAL_CHECK_ENABLED', false),
    'connection' => 'national_check',
    'sources' => [
        'disc_a' => ['program' => 'disc', 'city' => 'ALP', 'database' => 'hcsyria_disc_a', 'table' => 'student', 'id' => 'ID', 'number' => 'NationalNumber', 'names' => ['FirstName', 'FatherName', 'LastName']],
        'disc_d' => ['program' => 'disc', 'city' => 'DAM', 'database' => 'hcsyria_disc_d', 'table' => 'student', 'id' => 'ID', 'number' => 'NationalNumber', 'names' => ['FirstName', 'FatherName', 'LastName']],
        'disc_h' => ['program' => 'disc', 'city' => 'HMS', 'database' => 'hcsyria_disc_h', 'table' => 'student', 'id' => 'ID', 'number' => 'NationalNumber', 'names' => ['FirstName', 'FatherName', 'LastName']],
        // F2F state: the person's latest family partnership, active or not (desktop: family_partner.IsActive via person_family).
        'f2f_a' => ['program' => 'f2f', 'city' => 'ALP', 'database' => 'hcsyria_f2f_ajudy', 'table' => 'person', 'id' => 'id', 'number' => 'national_Number', 'names' => ['first_name', 'father_name', 'last_name'], 'partner_state' => true],
    ],
];
