<?php

/*
 * Unified schema v1 — design definition (M1, proposed 19 November 2026, awaiting user approval).
 *
 * One database, clean tables shared by all cities. Generated outputs: unified-schema-v1.json
 * (machine spec with SHA-256) and m1-schema-review.html (review page). After approval this file
 * drives the M2 migrations and the M3 transfer tool.
 *
 * Scopes (standard columns added by the generator):
 *   city        id BIGINT PK, city_id FK cities, legacy_id INT NULL, UNIQUE(city_id, legacy_id),
 *               created_at, updated_at, deleted_at
 *   lookup      id, name_ar, name_en, sort_order, is_active, created_at, updated_at
 *               (legacy ids of every city resolve through lookup_legacy_map; values merged by name)
 *   city_lookup lookup + city_id (values that belong to one city: streets, priests, members)
 *   global      id, created_at, updated_at
 *   existing    table already built in the current web app; kept as is (M1.5)
 *
 * Column syntax: 'type|flag|flag'. Types: str:N, text, int, uint, smallint, tinyint, bool, date,
 * datetime, dec:P,S, enum:a,b, json, char:N, fk:<table>. Flags: null, unique, index,
 * src:<Column> or src:<table>.<Column>, tx:<transform>.
 * Transforms: trim, bool01 (0/1 or text yes/no -> bool), date (invalid/zero -> null, kept in
 * legacy note), lookup (legacy id -> lookup_legacy_map), project (MicroProject_ID -> projects.id),
 * person, account (legacy user id -> web account via account_legacy_links), money, sex,
 * stage (ApplicationState -> enum), file (BLOB/FTP path -> private storage).
 */

return [
    'meta' => [
        'version' => 'unified-v1',
        'database' => 'one MySQL 8.4 / MariaDB 10.11 database, utf8mb4',
        'cities' => [
            ['code' => 'ALP', 'name_ar' => 'حلب', 'name_en' => 'Aleppo', 'legacy_database' => 'hcsyria_micro', 'notes' => 'hcsyria_micro_id is an old Aleppo copy and is not imported separately'],
            ['code' => 'DAM', 'name_ar' => 'دمشق', 'name_en' => 'Damascus', 'legacy_database' => 'hcsyria_micro_d'],
            ['code' => 'HMS', 'name_ar' => 'حمص', 'name_en' => 'Homs', 'legacy_database' => 'hcsyria_micro_h'],
            ['code' => 'LAT', 'name_ar' => 'اللاذقية', 'name_en' => 'Latakia', 'legacy_database' => 'hcsyria_micro_l'],
            ['code' => 'WAD', 'name_ar' => 'الوادي', 'name_en' => 'AlWadi', 'legacy_database' => 'hcsyria_micro_w'],
        ],
        'project_number' => [
            'column' => 'projects.project_number (INT UNSIGNED), UNIQUE(city_id, project_number)',
            'imported' => 'keeps the original MP_ID of its city unchanged',
            'suggest' => 'the form shows the next number of the selected city: max(sequence.next_number, MAX(project_number)+1)',
            'edit' => 'the user may type another number; it must be unused in the same city',
            'save' => 'inside one transaction: lock the city row of project_number_sequences, check the number, insert, then next_number = max(next_number, saved + 1); a duplicate returns a validation error with the next free number',
            'display' => '<city code>-<number>, e.g. DAM-2354; search accepts DAM-2354, dam2354 or 2354 (within the permitted cities)',
            'internal' => 'every reference uses projects.id (BIGINT); the number is never a foreign key',
            'floors' => 'after import next_number = MAX(project_number)+1 per city (M3.5)',
        ],
        'other_numbers' => 'Pay_ID, ExeF_ID, visit and M&E visit ids become internal ids; the original value stays in legacy_id. ExeF_No (the paper executive-file number) stays as typed text.',
        'deletes' => 'business rows are soft-deleted (deleted_at) by permitted roles and audited; hard delete only by administrators',
        // M1.6 — transfer rules, checked against the restored 19 Nov sources (Aleppo 22 Oct).
        'transforms' => [
            'trim' => 'leading/trailing spaces removed; empty string -> null',
            'bool01' => '1/0, YES/Yes/NO/No, Paid/Not Paid -> true/false; anything else -> null + migration_exceptions',
            'date' => 'valid dates kept; null, zero or before 1900 -> null + migration_exceptions (desktop "set later" values keep their original text there)',
            'sex' => 'ذكر -> male, أنثى -> female; empty (1,532 people) -> null; 3 malformed values -> null + exception',
            'stage' => 'ApplicationState zero -> preliminary (1,114), project -> full (9,405)',
            'currency' => 'loan Currency SP -> SYP, USD -> USD (3,065 / 40 loans)',
            'money' => 'float/double -> DECIMAL(15,2), rounded half-up; any change above 0.005 is recorded',
            'lookup' => 'per city, legacy id -> unified lookup through lookup_legacy_map. Values are merged by normalised Arabic name (trim, unify ي/ى, ة/ه, alef forms). Same id with different names in different cities is normal (for example 87 of 100 street ids) and is resolved per city',
            'project' => 'MicroProject_ID -> projects.id of the same city',
            'person' => 'Person_ID -> people.id of the same city',
            'account' => '(city, legacy UserID) -> web account through account_legacy_links; an unmapped user keeps null plus the legacy id in migration_exceptions',
            'file' => 'image.image_NewPath: file copied from the city FTP folder to private storage (M5.4); file.File_Content (Aleppo, 32 rows) written to private storage',
            'checklist_project' => 'checklist and checklist_member_opinions: project = the person\'s only project; 40 Aleppo checklists of people with several projects use the project whose request date is closest before the checklist dates, and are listed for review',
            'me_visit_project' => 'me_visits: project from kobo_monitoring_record when present, else the person\'s only project; 19 Aleppo visits of people with several projects are listed for review',
            'visit_answers' => 'visit.AnsN/AnsN_Value (N=1..7), rehabvisit (1..15) and office_monitoring (1..6) become one row per answered question; 2 duplicate office_monitoring rows (Homs, Latakia) are both kept as separate answers sets and listed for review',
            'latakia_person_extras' => 'person.P_Goal, P_GoalHow, P_OtherNotes, P_WorkYears (Latakia only) fill the empty fields of the person\'s project_applicants row; non-empty conflicts go to migration_exceptions',
            'kobo_local_record' => 'kobo_submissions.local_table mevisit/visit + Local_Record_ID -> me_visits.id / visits.id',
            'profession_meeting_state' => 'rows without Record_State (Aleppo backup of 22 Oct) -> final',
        ],
        'source_findings' => [
            'structure' => 'the five cities share the same tables and columns; only Latakia person has 4 extra columns and Homs/AlWadi store two loan amounts as double',
            'aleppo' => 'the 19 Nov Aleppo backup stops after profession_meeting (95 of ~140 tables); the 22 Oct backup is used for design until a complete one is provided',
            'empty' => 'purchasing, purchasingcategory and microproject_ag_answer are empty in every city; rehabilitation (2 projects), executive files (145) and stored files (32) exist only in Aleppo',
            'orphans' => 'no applicant rows without a project and no installments without a loan in any city',
        ],
    ],

    'groups' => [
        'core' => 'المدن والترقيم والربط',
        'lookups' => 'القوائم',
        'people' => 'الأشخاص والعائلات',
        'projects' => 'المشاريع والطلبات',
        'assessment' => 'التقييم والمهام والحالات الخاصة',
        'finance' => 'المالية',
        'field' => 'الزيارات والمراقبة',
        'kobo' => 'Kobo',
        'meetings' => 'اجتماعات المهن',
        'staff' => 'الحسابات والإشعارات والسجل',
    ],

    'tables' => [
        // ---------------------------------------------------------------- core
        'cities' => ['group' => 'core', 'scope' => 'global', 'columns' => [
            'code' => 'char:3|unique', 'name_ar' => 'str:60', 'name_en' => 'str:60',
            'legacy_database' => 'str:40|unique', 'attachment_folder' => 'str:120|null',
        ]],
        'project_number_sequences' => ['group' => 'core', 'scope' => 'global', 'columns' => [
            'city_id' => 'fk:cities|unique', 'next_number' => 'uint',
        ]],
        'lookup_legacy_map' => ['group' => 'core', 'scope' => 'global', 'note' => 'UNIQUE(city_id, lookup_table, legacy_id)', 'columns' => [
            'city_id' => 'fk:cities', 'lookup_table' => 'str:60', 'legacy_id' => 'int', 'lookup_id' => 'uint', 'legacy_name' => 'str:255|null',
        ]],
        'migration_runs' => ['group' => 'core', 'scope' => 'global', 'note' => 'one row per import of one city backup (archive hash, counts, status)', 'columns' => [
            'city_id' => 'fk:cities', 'archive_name' => 'str:200', 'archive_sha256' => 'char:64', 'status' => 'enum:running,completed,failed',
            'started_at' => 'datetime', 'finished_at' => 'datetime|null', 'counts' => 'json|null',
        ]],
        'migration_exceptions' => ['group' => 'core', 'scope' => 'global', 'note' => 'every value that could not be transferred exactly (bad date, missing reference, unknown user)', 'columns' => [
            'migration_run_id' => 'fk:migration_runs', 'source_table' => 'str:60', 'source_key' => 'str:60', 'column_name' => 'str:60|null',
            'kind' => 'str:40', 'original_value' => 'text|null', 'resolution' => 'str:200|null',
        ]],

        // ---------------------------------------------------------------- lookups (merged by name)
        'age_ranges' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'age', 'columns' => ['min_age' => 'int|src:Min', 'max_age' => 'int|src:Max']],
        'aid_types' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'aid'],
        'aid_periods' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'aidperiod'],
        'aid_sources' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'aidsource'],
        'asset_kinds' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'asset', 'columns' => ['mark' => 'int|null|src:Mark']],
        'asset_benefits' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'assetbenefits', 'columns' => ['mark' => 'int|null|src:Mark']],
        'asset_types' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'assettype', 'columns' => ['home_mark' => 'int|null|src:HomeMark', 'project_mark' => 'int|null|src:ProjectMark']],
        'project_categories' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'category'],
        'project_subcategories' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'subcategory', 'columns' => ['project_category_id' => 'fk:project_categories|src:Category_ID|tx:lookup']],
        'careers' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'career', 'columns' => ['project_subcategory_id' => 'fk:project_subcategories|null|src:SubCategory_ID|tx:lookup']],
        'project_types' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'microprojecttype'],
        'project_subtypes' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'microprojectsubtype', 'columns' => ['project_type_id' => 'fk:project_types|src:Type_ID|tx:lookup']],
        'fund_types' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'fundtype'],
        'donors' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'donor', 'columns' => ['abbreviation' => 'str:50|null|src:Name_abb']],
        'donor_groups' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'donorgroup', 'note' => 'merged by (donor, name)', 'columns' => ['donor_id' => 'fk:donors|src:Donor_ID|tx:lookup']],
        'education_types' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'educationtype'],
        'education_levels' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'educationlevel'],
        'education_specialties' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'education', 'columns' => ['education_type_id' => 'fk:education_types|null|src:Type_ID|tx:lookup']],
        'education_grades' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'level', 'note' => 'name from Level_Symbol', 'columns' => ['description' => 'text|null|src:Level_Description']],
        'goals' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'goal'],
        'illnesses' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'illness', 'columns' => ['mark' => 'int|null|src:Mark']],
        'medical_needs' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'medicalneeds'],
        'income_sources' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'incomesource'],
        'languages' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'language'],
        'loss_types' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'loss', 'columns' => ['mark' => 'int|null|src:Mark']],
        'marital_statuses' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'maritalstatus', 'columns' => ['mark' => 'int|null|src:Mark']],
        'military_statuses' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'militarystate'],
        'parishes' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'parish'],
        'person_states' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'personstate', 'columns' => ['type' => 'str:50|null|src:Type']],
        'power_sources' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'powersource', 'columns' => ['mark' => 'int|null|src:Mark']],
        'relations' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'relation'],
        'risks' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'risk'],
        'work_types' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'worktype', 'columns' => ['mark' => 'int|null|src:Mark']],
        'occupations' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'work', 'note' => 'name from W_Name'],
        'courses' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'course'],
        'attachment_types' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'imagetype', 'columns' => ['category' => 'str:50|null|src:Category']],
        'special_case_types' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'specialcasetype'],
        'project_states' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'state'],
        'state_reasons' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'statereason'],
        'purchasing_categories' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'purchasingcategory'],
        'task_definitions' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'task', 'columns' => ['state' => 'int|null|src:T_State', 'type' => 'str:50|null|src:T_Type']],
        'score_criteria' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'score'],
        'score_options' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'scorevalue', 'columns' => ['score_criterion_id' => 'fk:score_criteria|src:Score_ID|tx:lookup', 'value' => 'int|src:Value']],
        'checklist_opinions' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'checklist_opinion', 'note' => 'sort_order from Index'],
        'checklist_positions' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'checklist_position', 'columns' => ['type' => 'str:20|null|src:Type']],
        'streets' => ['group' => 'lookups', 'scope' => 'city_lookup', 'source' => 'street'],
        'priests' => ['group' => 'lookups', 'scope' => 'city_lookup', 'source' => 'priest', 'note' => 'name from Priest_Name'],
        'checklist_members' => ['group' => 'lookups', 'scope' => 'city_lookup', 'source' => 'checklist_member', 'columns' => ['checklist_position_id' => 'fk:checklist_positions|null|src:Position_ID|tx:lookup']],
        'ag_questions' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'agquestion'],
        'ag_answers' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'aganswer', 'note' => 'merged by (question, name)', 'columns' => ['ag_question_id' => 'fk:ag_questions|src:Question_ID|tx:lookup']],
        'rehab_questions' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'rehabquestion', 'columns' => ['type' => 'int|null|src:Type']],
        'rehab_answers' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'rehabanswer', 'columns' => ['rehab_question_id' => 'fk:rehab_questions|src:Question_ID|tx:lookup']],
        'visit_questions' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'visitquestions', 'columns' => ['type' => 'str:30|null|src:Type', 'has_selected_value' => 'bool|src:HasSelectedValue|tx:bool01', 'number' => 'int|null|src:QNumber']],
        'visit_question_options' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'visitanswers', 'columns' => ['visit_question_id' => 'fk:visit_questions|src:Question_ID|tx:lookup', 'value' => 'int|null|src:Value']],
        'me_questions' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'mequestion', 'columns' => ['type' => 'str:30|null|src:Type']],
        'me_answers' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'meanswer', 'columns' => ['me_question_id' => 'fk:me_questions|src:Question_ID|tx:lookup', 'mark' => 'int|null|src:Mark']],
        'me_profit_marks' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'meprofitmarks', 'note' => 'no name; name_ar = range label', 'columns' => ['min_value' => 'dec:15,2|src:Min', 'max_value' => 'dec:15,2|src:Max', 'mark' => 'int|src:Mark']],
        'me_result_ranges' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'meresultranges', 'note' => 'name from Evaluation / Evaluation_en', 'columns' => ['min_value' => 'int|src:Min', 'max_value' => 'int|src:Max']],
        'profession_sections' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'profession_section'],
        'profession_questions' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'profession_question', 'note' => 'name from Text_Ar', 'columns' => ['profession_section_id' => 'fk:profession_sections|src:Section_ID|tx:lookup', 'number' => 'str:10|null|src:Number', 'answer_type' => 'str:30|src:Answer_Type']],
        'profession_options' => ['group' => 'lookups', 'scope' => 'lookup', 'source' => 'profession_option', 'note' => 'name from Text_Ar', 'columns' => ['profession_question_id' => 'fk:profession_questions|src:Question_ID|tx:lookup', 'is_other' => 'bool|src:Is_Other', 'has_details' => 'bool|src:Has_Details']],

        // ---------------------------------------------------------------- people
        'people' => ['group' => 'people', 'scope' => 'city', 'source' => 'person', 'legacy_key' => 'P_ID', 'columns' => [
            'first_name' => 'str:100|src:P_FirstName|tx:trim', 'last_name' => 'str:100|src:P_LastName|tx:trim',
            'father_name' => 'str:100|null|src:P_FatherName|tx:trim', 'mother_name' => 'str:100|null|src:P_MotherName|tx:trim',
            'full_name_en' => 'str:255|null|src:P_FullName_en', 'birth_date' => 'date|null|src:P_DOB|tx:date',
            'sex' => 'enum:male,female|null|src:P_Sex|tx:sex', 'national_number' => 'str:30|null|index|src:P_NationalNumber|tx:trim',
            'registration_place' => 'str:100|null|src:P_RegistrationPlace', 'mobile' => 'str:30|null|index|src:P_Mobile',
            'mobile_2' => 'str:30|null|src:P_Mobile2', 'home_phone' => 'str:30|null|src:P_HomeTel',
            'street_id' => 'fk:streets|null|src:Street_ID|tx:lookup', 'home_address' => 'text|null|src:P_HomeAddress',
            'marital_status_id' => 'fk:marital_statuses|null|src:P_MaritalStatus_ID|tx:lookup',
            'household_size' => 'smallint|null|src:P_NumAtHome', 'family_size' => 'smallint|null|src:P_NumOfMembers',
            'is_project_owner' => 'bool|null|src:IsProjectOwner|tx:bool01', 'intermediary' => 'text|null|src:P_IntermidiarySide',
            'home_ownership_id' => 'fk:asset_types|null|src:P_HomeType_ID|tx:lookup', 'home_state_note' => 'str:255|null|src:P_HomeState_Note',
            'maristes_course' => 'str:100|null|src:P_MaristesCourse', 'parish_id' => 'fk:parishes|null|src:P_Parish_ID|tx:lookup',
            'priest_id' => 'fk:priests|null|src:P_Priest_ID|tx:lookup', 'second_priest_id' => 'fk:priests|null|src:P_Priest2_ID|tx:lookup',
            'person_state_id' => 'fk:person_states|null|src:P_State_ID|tx:lookup',
            'military_service' => 'str:50|null|src:P_MilitaryService', 'military_service_date' => 'date|null|src:P_MilitaryService_Date|tx:date',
            'military_service_note' => 'str:255|null|src:P_MilitaryService_Note', 'military_period' => 'smallint|null|src:P_MilitaryPeriod',
            'military_in_war' => 'bool|null|src:P_IsMilitaryInWar|tx:bool01',
        ], 'note' => 'Latakia-only P_Goal, P_GoalHow, P_OtherNotes, P_WorkYears move to the person\'s project_applicants row when that row is empty; otherwise kept in migration_exceptions'],
        'families' => ['group' => 'people', 'scope' => 'city', 'source' => 'family', 'legacy_key' => 'F_ID', 'columns' => [
            'family_number' => 'str:50|index|src:F_Number|tx:trim',
        ]],
        'family_members' => ['group' => 'people', 'scope' => 'city', 'source' => 'person_family', 'legacy_key' => 'PF_ID', 'columns' => [
            'family_id' => 'fk:families|src:Family_ID', 'person_id' => 'fk:people|src:Person_ID|tx:person',
            'relation_id' => 'fk:relations|null|src:Relation_ID|tx:lookup', 'education_level_id' => 'fk:education_levels|null|src:EducationLevel_ID|tx:lookup',
            'education_specialty_id' => 'fk:education_specialties|null|src:Education_ID|tx:lookup', 'has_work' => 'bool|null|src:HasWork|tx:bool01',
            'provider_person_id' => 'fk:people|null|src:P_Provider_ID|tx:person', 'work_name' => 'text|null|src:Work_Name',
        ]],
        'person_educations' => ['group' => 'people', 'scope' => 'city', 'source' => 'person_education', 'columns' => [
            'person_id' => 'fk:people|src:Person_ID|tx:person', 'education_specialty_id' => 'fk:education_specialties|null|src:Education_ID|tx:lookup',
            'education_grade_id' => 'fk:education_grades|null|src:Level_ID|tx:lookup',
        ]],
        'person_work_experiences' => ['group' => 'people', 'scope' => 'city', 'source' => 'person_workexp', 'columns' => [
            'person_id' => 'fk:people|src:Person_ID|tx:person', 'project_id' => 'fk:projects|null|src:MicroProject_ID|tx:project',
            'occupation_id' => 'fk:occupations|null|src:Work_ID|tx:lookup', 'description' => 'text|null|src:W_Description',
            'work_type_id' => 'fk:work_types|null|src:W_WorkType_ID|tx:lookup', 'begin_text' => 'str:50|null|src:W_BeginDate', 'end_text' => 'str:50|null|src:W_EndDate',
            'place' => 'text|null|src:W_Place', 'place_state' => 'str:100|null|src:W_PlaceState', 'status' => 'str:10|null|src:W_Status',
            'contact' => 'text|null|src:W_Contact', 'leaving_reason' => 'text|null|src:W_CauseOfLoseDescription', 'salary' => 'dec:15,2|null|src:W_Salary|tx:money',
        ]],
        'person_courses' => ['group' => 'people', 'scope' => 'city', 'source' => 'person_course', 'columns' => [
            'person_id' => 'fk:people|src:Person_ID|tx:person', 'name' => 'str:255|null|src:Name', 'place' => 'str:255|null|src:Place',
            'donor_text' => 'str:255|null|src:Donor', 'date_text' => 'str:50|null|src:Date',
            'project_subcategory_id' => 'fk:project_subcategories|null|src:SubCategory_ID|tx:lookup',
        ]],
        'person_losses' => ['group' => 'people', 'scope' => 'city', 'source' => 'person_loss', 'columns' => [
            'person_id' => 'fk:people|src:Person_ID|tx:person', 'loss_type_id' => 'fk:loss_types|null|src:Loss_ID|tx:lookup',
            'year_text' => 'str:20|null|src:Year', 'note' => 'text|null|src:Note',
        ]],
        'person_illnesses' => ['group' => 'people', 'scope' => 'city', 'source' => 'person_illness', 'columns' => [
            'person_id' => 'fk:people|src:Person_ID|tx:person', 'illness_id' => 'fk:illnesses|null|src:Illness_ID|tx:lookup',
            'has_medical_aid' => 'bool|null|src:Has_MedicalAid|tx:bool01', 'aid_source_id' => 'fk:aid_sources|null|src:AidSource_ID|tx:lookup',
            'donor_text' => 'text|null|src:Donor', 'note' => 'text|null|src:Note', 'aid_period_id' => 'fk:aid_periods|null|src:AidPeriod_ID|tx:lookup',
            'medical_need_id' => 'fk:medical_needs|null|src:MedicalNeeds_ID|tx:lookup',
        ]],
        'person_assets' => ['group' => 'people', 'scope' => 'city', 'source' => 'person_asset', 'columns' => [
            'person_id' => 'fk:people|src:Person_ID|tx:person', 'asset_kind_id' => 'fk:asset_kinds|null|src:Asset_ID|tx:lookup',
            'asset_type_id' => 'fk:asset_types|null|src:Asset_Type_ID|tx:lookup', 'asset_benefit_id' => 'fk:asset_benefits|null|src:Asset_Benefits_ID|tx:lookup',
            'year_text' => 'str:20|null|src:Year', 'description' => 'text|null|src:Description', 'note' => 'text|null|src:Note',
        ]],
        'person_aid_sources' => ['group' => 'people', 'scope' => 'city', 'source' => 'person_aidsource', 'columns' => [
            'person_id' => 'fk:people|src:Person_ID|tx:person', 'project_id' => 'fk:projects|null|src:MicroProject_ID|tx:project',
            'aid_source_id' => 'fk:aid_sources|null|src:AidSource_ID|tx:lookup', 'year_text' => 'str:20|null|src:DateYear',
            'amount_text' => 'text|null|src:Amount', 'project_type_text' => 'text|null|src:ProjectType', 'notes' => 'text|null|src:Notes',
        ]],
        'person_aid_received' => ['group' => 'people', 'scope' => 'city', 'source' => 'person_help', 'columns' => [
            'person_id' => 'fk:people|src:Person_ID|tx:person', 'aid_source_id' => 'fk:aid_sources|null|src:AidSource_ID|tx:lookup',
            'aid_type_id' => 'fk:aid_types|null|src:Aid_ID|tx:lookup', 'aid_period_id' => 'fk:aid_periods|null|src:Aidperiod_ID|tx:lookup', 'notes' => 'text|null|src:Notes',
        ]],
        'person_goals' => ['group' => 'people', 'scope' => 'city', 'source' => 'person_goals', 'columns' => [
            'person_id' => 'fk:people|src:Person_ID|tx:person', 'goal_id' => 'fk:goals|src:Goal_ID|tx:lookup',
        ]],
        'person_power_sources' => ['group' => 'people', 'scope' => 'city', 'source' => 'person_powersource', 'columns' => [
            'person_id' => 'fk:people|src:Person_ID|tx:person', 'power_source_id' => 'fk:power_sources|null|src:PowerSource_ID|tx:lookup',
            'note' => 'text|null|src:Note', 'cost' => 'dec:15,2|null|src:Cost|tx:money', 'responsibility' => 'text|null|src:Responsability',
        ]],

        // ---------------------------------------------------------------- projects
        'projects' => ['group' => 'projects', 'scope' => 'city', 'source' => 'microproject', 'legacy_key' => 'MP_ID', 'columns' => [
            'project_number' => 'uint|src:MP_ID', 'application_stage' => 'enum:preliminary,full|src:ApplicationState|tx:stage',
            'request_date' => 'date|null|src:MP_DateOfRequest|tx:date', 'name' => 'text|null|src:MP_Name',
            'requested_amount' => 'dec:15,2|null|src:MP_RequestedAmount|tx:money', 'fund_type_id' => 'fk:fund_types|null|src:MP_FundType_ID|tx:lookup',
            'project_type_id' => 'fk:project_types|null|src:MP_Type_ID|tx:lookup', 'project_subtype_id' => 'fk:project_subtypes|null|src:MP_SubType_ID|tx:lookup',
            'project_category_id' => 'fk:project_categories|null|src:Category_ID|tx:lookup', 'project_subcategory_id' => 'fk:project_subcategories|null|src:SubCategory_ID|tx:lookup',
            'career_id' => 'fk:careers|null|src:Career_ID|tx:lookup', 'speciality' => 'str:255|null|src:MP_Speciality',
            'profit_description' => 'text|null|src:MP_Profit_Description', 'income_kind' => 'str:100|null|src:MP_IncomeKind', 'income_kind_note' => 'str:255|null|src:MP_IncomeKind_Note',
            'place_type_id' => 'fk:asset_types|null|src:MP_PlaceType_ID|tx:lookup', 'place_address' => 'text|null|src:MP_PlaceAddress', 'place_note' => 'text|null|src:MP_PlaceNote',
            'amper_number' => 'int|null|src:MP_AmperNumber', 'amper_type' => 'int|null|src:MP_AmperType',
            'agreement_1' => 'bool|null|src:MP_Agreement1|tx:bool01', 'agreement_2' => 'bool|null|src:MP_Agreement2|tx:bool01', 'agreement_3' => 'bool|null|src:MP_Agreement3|tx:bool01',
            'agreement_4' => 'bool|null|src:MP_Agreement4|tx:bool01', 'agreement_5' => 'bool|null|src:MP_Agreement5|tx:bool01',
            'note' => 'text|null|src:MP_Note', 'donor_id' => 'fk:donors|null|src:MP_Donor|tx:lookup', 'donor_group_id' => 'fk:donor_groups|null|src:DonorGroup_ID|tx:lookup',
            'partnership' => 'int|null|src:Partnership', 'is_content_updated' => 'bool|null|src:IsContentUpdated|tx:bool01',
            'street_id' => 'fk:streets|null|src:MP_Street_ID|tx:lookup', 'address_after_fund' => 'str:255|null|src:MP_AddressAfterFund',
            'paper_registrar_account_id' => 'fk:web_accounts|null|src:MP_PaperUser_ID|tx:account',
            'program_registrar_account_id' => 'fk:web_accounts|null|src:MP_ProgramUser_ID|tx:account',
            'edited_by_account_id' => 'fk:web_accounts|null|src:MP_EditedByUser_ID|tx:account',
        ], 'note' => 'UNIQUE(city_id, project_number); see meta.project_number'],
        'project_applicants' => ['group' => 'projects', 'scope' => 'city', 'source' => 'person_microproject', 'legacy_key' => 'PMP_ID', 'columns' => [
            'project_id' => 'fk:projects|src:MicroProject_ID|tx:project', 'person_id' => 'fk:people|src:Person_ID|tx:person',
            'role' => 'str:30|null|src:PersonType', 'loss_story' => 'text|null|src:P_Loss', 'goal' => 'text|null|src:P_Goal', 'goal_how' => 'text|null|src:P_GoalHow',
            'other_notes' => 'text|null|src:P_OtherNotes', 'has_work' => 'bool|null|src:P_HasWork|tx:bool01', 'work_years' => 'str:100|null|src:P_WorkYears',
            'lives_with_family' => 'bool|null|src:P_IsLivingWithFamily|tx:bool01', 'lives_with_family_note' => 'text|null|src:P_LiveWithFamily_Note',
            'has_education' => 'bool|null|src:P_HasEducation|tx:bool01', 'has_courses' => 'bool|null|src:P_HasCourses|tx:bool01', 'has_loss' => 'bool|null|src:P_HasLoss|tx:bool01',
            'has_illness' => 'bool|null|src:P_HasIllness|tx:bool01', 'has_assets' => 'bool|null|src:P_HasAssets|tx:bool01', 'has_aid_sources' => 'bool|null|src:P_HasAidSource|tx:bool01',
            'has_help' => 'bool|null|src:P_HasHelp|tx:bool01', 'has_debts' => 'bool|null|src:P_HasDebts|tx:bool01', 'debts_amount' => 'text|null|src:P_DebtsAmount',
            'debts_note' => 'text|null|src:P_DebtsNote', 'aid_source_note' => 'text|null|src:P_AidSourceNote',
        ]],
        'project_state_history' => ['group' => 'projects', 'scope' => 'city', 'source' => 'microproject_state', 'columns' => [
            'project_id' => 'fk:projects|src:MicroProject_ID|tx:project', 'project_state_id' => 'fk:project_states|src:State_ID|tx:lookup',
            'state_reason_id' => 'fk:state_reasons|null|src:StateReason_ID|tx:lookup', 'note' => 'text|null|src:Note',
            'changed_on' => 'date|null|src:Date|tx:date', 'is_current' => 'bool|src:Current_State|tx:bool01',
        ]],
        'project_power_sources' => ['group' => 'projects', 'scope' => 'city', 'source' => 'microproject_powersource', 'columns' => [
            'project_id' => 'fk:projects|src:MicroProject_ID|tx:project', 'power_source_id' => 'fk:power_sources|null|src:PowerSource_ID|tx:lookup',
            'note' => 'text|null|src:Note', 'cost' => 'dec:15,2|null|src:Cost|tx:money', 'responsibility' => 'text|null|src:Responsability',
        ]],
        'project_risks' => ['group' => 'projects', 'scope' => 'city', 'source' => 'microproject_risk', 'columns' => [
            'project_id' => 'fk:projects|src:MicroProject_ID|tx:project', 'risk_id' => 'fk:risks|src:Risk_ID|tx:lookup',
        ]],
        'project_scores' => ['group' => 'projects', 'scope' => 'city', 'source' => 'microproject_score', 'columns' => [
            'project_id' => 'fk:projects|src:MicroProject_ID|tx:project', 'score_criterion_id' => 'fk:score_criteria|src:Score_ID|tx:lookup',
            'value' => 'int|null|src:value', 'notes' => 'text|null|src:notes',
        ]],
        'project_materials' => ['group' => 'projects', 'scope' => 'city', 'source' => 'material', 'columns' => [
            'project_id' => 'fk:projects|src:MicroProject_ID|tx:project', 'name' => 'text|null|src:Name', 'quantity' => 'dec:15,2|null|src:Amount',
            'price' => 'dec:15,2|null|src:Price|tx:money', 'local_contribution' => 'dec:15,2|null|src:LocalContribution|tx:money', 'comments' => 'text|null|src:Comments',
        ]],
        'project_agriculture_answers' => ['group' => 'projects', 'scope' => 'city', 'source' => 'microproject_ag_answer', 'note' => 'empty in all five cities; kept for the agriculture annex (user decision M0.5)', 'columns' => [
            'project_id' => 'fk:projects|src:MicroProject_ID|tx:project', 'ag_question_id' => 'fk:ag_questions|src:Question_ID|tx:lookup', 'ag_answer_id' => 'fk:ag_answers|null|src:Answer_ID|tx:lookup',
        ]],
        'project_rehabilitations' => ['group' => 'projects', 'scope' => 'city', 'source' => 'microproject_rehab', 'columns' => [
            'project_id' => 'fk:projects|src:MicroProject_ID|tx:project', 'property_ownership' => 'str:100|null|src:PropertyOwnership',
            'property_area' => 'str:100|null|src:PropertyArea', 'created_by_name' => 'str:100|null|src:Created_By', 'edited_by_name' => 'str:100|null|src:Edited_By',
        ]],
        'project_rehabilitation_items' => ['group' => 'projects', 'scope' => 'city', 'source' => 'microproject_rehab_answer', 'columns' => [
            'project_rehabilitation_id' => 'fk:project_rehabilitations|src:MicroRehab_ID', 'rehab_question_id' => 'fk:rehab_questions|src:Question_ID|tx:lookup',
            'rehab_answer_id' => 'fk:rehab_answers|null|src:Answer_ID|tx:lookup', 'note' => 'text|null|src:Note', 'quantity' => 'int|null|src:Number',
            'price' => 'dec:15,2|null|src:Price|tx:money', 'result' => 'text|null|src:Result',
        ]],

        // ---------------------------------------------------------------- assessment, tasks, special cases
        'checklists' => ['group' => 'assessment', 'scope' => 'city', 'source' => 'checklist', 'note' => 'the desktop keys a checklist by person; project_id is filled when the person has exactly one project, otherwise recorded in migration_exceptions', 'columns' => [
            'person_id' => 'fk:people|src:Person_ID|tx:person', 'project_id' => 'fk:projects|null',
            'has_home_visit' => 'bool|null|src:HasHomeVisit|tx:bool01', 'home_visit_date' => 'date|null|src:HVisit_Date|tx:date', 'home_visit_time' => 'str:20|null|src:HVisit_Time',
            'home_visitor_1_id' => 'fk:checklist_members|null|src:HVisit_Visitor1_ID|tx:lookup', 'home_visitor_2_id' => 'fk:checklist_members|null|src:HVisit_Visitor2_ID|tx:lookup', 'home_visit_note' => 'text|null|src:HVisit_Note',
            'has_project_visit' => 'bool|null|src:HasProjectVisit|tx:bool01', 'project_visit_date' => 'date|null|src:PVisit_Date|tx:date', 'project_visit_time' => 'str:20|null|src:PVisit_Time',
            'project_visitor_1_id' => 'fk:checklist_members|null|src:PVisit_Visitor1_ID|tx:lookup', 'project_visitor_2_id' => 'fk:checklist_members|null|src:PVisit_Visitor2_ID|tx:lookup', 'project_visit_note' => 'text|null|src:PVisit_Note',
            'visits_report' => 'text|null|src:VisitsReport',
            'needs_professional_test' => 'bool|null|src:NeedProTest|tx:bool01', 'professional_test_need_note' => 'text|null|src:NeedProTest_Note',
            'has_professional_test' => 'bool|null|src:HasProTest|tx:bool01', 'professional_test_date' => 'date|null|src:ProTest_Date|tx:date', 'professional_test_note' => 'text|null|src:ProTest_Note',
            'professional_test_account_id' => 'fk:web_accounts|null|src:ProTest_User_ID|tx:account', 'professional_test_result' => 'text|null|src:ProTest_Result',
            'has_professional_training' => 'bool|null|src:HasProTrain|tx:bool01', 'professional_trainer' => 'str:255|null|src:ProTrain_Trainer', 'professional_training_place' => 'str:255|null|src:ProTrain_Place',
            'professional_training_start' => 'date|null|src:ProTrain_BDate|tx:date', 'professional_training_end' => 'date|null|src:ProTrain_EDate|tx:date',
            'professional_training_note' => 'text|null|src:ProTrain_Note', 'professional_training_result' => 'text|null|src:ProTrain_Result',
            'has_management_training' => 'bool|null|src:HasMngTrain|tx:bool01', 'management_trainer' => 'str:255|null|src:MngTrain_Trainer', 'management_training_place' => 'str:255|null|src:MngTrain_Place',
            'management_training_date' => 'date|null|src:MngTrain_Date|tx:date', 'management_training_result' => 'text|null|src:MngTrain_Result',
            'notes' => 'text|null|src:Notes', 'team_date' => 'date|null|src:TeamDate|tx:date', 'committee_date' => 'date|null|src:CommityDate|tx:date',
            'rejection_message' => 'int|null|src:RejectMsg', 'rejection_message_date' => 'date|null|src:RejectMsgDate|tx:date',
            'rejection_message_sender_account_id' => 'fk:web_accounts|null|src:RejectMsgSender_ID|tx:account',
        ]],
        'checklist_member_opinions' => ['group' => 'assessment', 'scope' => 'city', 'source' => 'person_checklistmembers', 'columns' => [
            'person_id' => 'fk:people|src:Person_ID|tx:person', 'checklist_member_id' => 'fk:checklist_members|src:Member_ID|tx:lookup',
            'checklist_opinion_id' => 'fk:checklist_opinions|null|src:Opinion|tx:lookup', 'notes' => 'text|null|src:Notes', 'name_notes' => 'str:255|null|src:NameNotes',
            'role_notes' => 'str:255|null|src:RoleNotes', 'location' => 'str:100|null|src:Location', 'recorded_at' => 'datetime|null|src:created_at|tx:date',
        ]],
        'project_tasks' => ['group' => 'assessment', 'scope' => 'city', 'source' => 'task_microproject', 'columns' => [
            'project_id' => 'fk:projects|src:MicroProject_ID|tx:project', 'task_definition_id' => 'fk:task_definitions|src:Task_ID|tx:lookup',
            'state' => 'int|null|src:State', 'task_date' => 'datetime|null|src:Date|tx:date',
        ]],
        'special_cases' => ['group' => 'assessment', 'scope' => 'city', 'source' => 'specialcase', 'columns' => [
            'project_id' => 'fk:projects|src:MicroProject_ID|tx:project', 'account_id' => 'fk:web_accounts|null|src:User_ID|tx:account',
            'special_case_type_id' => 'fk:special_case_types|null|src:Type_ID|tx:lookup', 'case_date' => 'date|null|src:Date|tx:date',
            'reason' => 'text|null|src:Reason', 'description' => 'text|null|src:Description', 'action' => 'text|null|src:Action',
        ]],

        // ---------------------------------------------------------------- finance
        'loans' => ['group' => 'finance', 'scope' => 'city', 'source' => 'loan', 'legacy_key' => 'Loan_ID', 'columns' => [
            'project_id' => 'fk:projects|src:MicroProject_ID|tx:project', 'currency' => 'str:3|src:Currency|tx:currency', 'exchange_rate' => 'dec:15,4|null|src:ExchangeRate',
            'amount' => 'dec:15,2|src:Loan_Amount|tx:money', 'taken_on' => 'date|null|src:Loan_DateTaken|tx:date',
            'installments_count' => 'dec:8,2|null|src:Loan_PaymentsCount', 'rate' => 'dec:8,4|null|src:Loan_Rate',
            'returned_amount' => 'dec:15,2|null|src:Loan_ReturnedAmount|tx:money', 'installment_amount' => 'dec:15,2|null|src:Loan_PaymentsAmount|tx:money',
            'note' => 'text|null|src:Loan_Note', 'receipt_number' => 'str:50|null|src:Loan_ReceiptID',
        ]],
        'loan_installments' => ['group' => 'finance', 'scope' => 'city', 'source' => 'payment', 'legacy_key' => 'Pay_ID', 'columns' => [
            'loan_id' => 'fk:loans|src:Loan_ID', 'amount' => 'dec:15,2|src:Pay_Amount|tx:money', 'due_on' => 'date|null|src:Pay_DueDate|tx:date',
            'is_paid' => 'bool|src:Pay_IsPaid|tx:bool01', 'received_on' => 'date|null|src:Pay_RecievedOnDate|tx:date', 'note' => 'text|null|src:Pay_Note',
        ]],
        'executive_files' => ['group' => 'finance', 'scope' => 'city', 'source' => 'exefile', 'legacy_key' => 'ExeF_ID', 'columns' => [
            'project_id' => 'fk:projects|src:MicroProject_ID|tx:project', 'file_number' => 'str:50|null|src:ExeF_No', 'begin_on' => 'date|null|src:ExeF_BeginDate|tx:date',
            'current_on' => 'date|null|src:ExeF_CurrentDate|tx:date', 'impound_on' => 'date|null|src:ExeF_ImpoundDate|tx:date',
            'impound_type' => 'str:100|null|src:ExeF_ImpoundType', 'months' => 'int|null|src:ExeF_NumOfMonths',
        ]],
        'purchases' => ['group' => 'finance', 'scope' => 'city', 'source' => 'purchasing', 'note' => 'empty in all cities; kept (user decision M0.5)', 'columns' => [
            'project_id' => 'fk:projects|src:MicroProject_ID|tx:project', 'item_name' => 'str:255|src:ItemName', 'brand' => 'str:255|null|src:Brand',
            'purchasing_category_id' => 'fk:purchasing_categories|null|src:ItemCategory_ID|tx:lookup', 'quantity' => 'int|null|src:Quantity',
            'price' => 'dec:15,2|null|src:Price|tx:money', 'currency' => 'str:3|null|src:Currency|tx:currency', 'invoice_date' => 'date|null|src:InvoiceDate|tx:date',
            'invoice_number' => 'str:100|null|src:InvoiceNumber', 'trader_name' => 'str:255|null|src:TraderName',
        ]],

        // ---------------------------------------------------------------- field
        'visits' => ['group' => 'field', 'scope' => 'city', 'source' => 'visit', 'legacy_key' => 'ID', 'columns' => [
            'project_id' => 'fk:projects|src:MicroProject_ID|tx:project', 'kind' => 'str:30|null|src:Kind', 'type' => 'str:30|null|src:Type',
            'visit_date' => 'date|null|src:Date|tx:date', 'indicators' => 'text|null|src:Indicators', 'vehicle_number' => 'str:30|null|src:VehicleNumber',
            'office_comments' => 'text|null|src:office_monitoring.OtherComments',
            'created_by_name' => 'str:100|null|src:Created_By', 'edited_by_name' => 'str:100|null|src:Edited_By',
        ]],
        'visit_answers' => ['group' => 'field', 'scope' => 'city', 'source' => 'visit.Ans1..7 + rehabvisit.Ans1..15 + office_monitoring.Ans1..6', 'note' => 'one row per question; replaces the wide AnsN / AnsN_Value columns of three tables', 'columns' => [
            'visit_id' => 'fk:visits', 'form' => 'enum:visit,rehab,office_monitoring', 'question_number' => 'tinyint',
            'answer_text' => 'text|null', 'answer_value' => 'int|null',
        ]],
        'visit_visitors' => ['group' => 'field', 'scope' => 'city', 'source' => 'visit_user', 'columns' => [
            'visit_id' => 'fk:visits|src:Visit_ID', 'account_id' => 'fk:web_accounts|src:User_ID|tx:account',
        ]],
        'me_visits' => ['group' => 'field', 'scope' => 'city', 'source' => 'mevisit', 'legacy_key' => 'ID', 'note' => 'the desktop keys M&E visits by person; project_id is filled from the Kobo monitoring record or the person\'s only project', 'columns' => [
            'person_id' => 'fk:people|src:Person_ID|tx:person', 'project_id' => 'fk:projects|null', 'visit_number' => 'str:20|null|src:Number',
            'kind' => 'str:30|null|src:Kind', 'type' => 'str:30|null|src:Type', 'visit_date' => 'date|null|src:Date|tx:date',
            'profit' => 'dec:15,2|null|src:Profit|tx:money', 'answer_1' => 'text|null|src:Ans1', 'answer_2' => 'text|null|src:Ans2',
            'indicators' => 'text|null|src:Indicators', 'notes' => 'text|null|src:Notes', 'result' => 'int|null|src:Result',
            'created_by_name' => 'str:100|null|src:Created_By', 'edited_by_name' => 'str:100|null|src:Edited_By',
        ]],
        'me_visit_answers' => ['group' => 'field', 'scope' => 'city', 'source' => 'mevisit_answer', 'columns' => [
            'me_visit_id' => 'fk:me_visits|src:Visit_ID', 'me_question_id' => 'fk:me_questions|src:Question_ID|tx:lookup',
            'me_answer_id' => 'fk:me_answers|null|src:Answer_ID|tx:lookup', 'notes' => 'text|null|src:Notes',
        ]],
        'me_visit_visitors' => ['group' => 'field', 'scope' => 'city', 'source' => 'mevisit_user', 'columns' => [
            'me_visit_id' => 'fk:me_visits|src:Visit_ID', 'account_id' => 'fk:web_accounts|src:User_ID|tx:account', 'mark' => 'str:20|null|src:Mark',
        ]],

        // ---------------------------------------------------------------- Kobo
        'kobo_forms' => ['group' => 'kobo', 'scope' => 'city', 'source' => 'kobo_form_mapping + kobo_monitoring_field_mapping + kobo_opening_field_mapping + kobo_monitoring_stage_mapping (joined on Kobo_Form_UID)', 'note' => 'UNIQUE(city_id, form_uid)', 'columns' => [
            'form_uid' => 'str:64|src:Kobo_Form_UID', 'form_name' => 'str:255|null|src:Kobo_Form_Name', 'governorate' => 'str:50|null|src:Governorate',
            'visit_type' => 'str:50|null|src:Visit_Type', 'visit_category' => 'str:50|null|src:Visit_Category', 'target_table' => 'str:60|null|src:Target_Table',
            'is_active' => 'bool|src:Is_Active', 'project_number_field' => 'str:255|null', 'visitor_choice_field' => 'str:255|null',
            'visitor_text_field' => 'str:255|null', 'vehicle_number_field' => 'str:255|null', 'stage_field' => 'str:255|null', 'fixed_stage' => 'tinyint|null',
        ]],
        'kobo_submissions' => ['group' => 'kobo', 'scope' => 'city', 'source' => 'kobo_submission', 'columns' => [
            'root_uuid' => 'str:64|index|src:Kobo_Root_UUID', 'current_uuid' => 'str:64|null|src:Kobo_Current_UUID', 'kobo_submission_id' => 'str:64|null|src:Kobo_Submission_ID',
            'form_uid' => 'str:64|null|src:Kobo_Form_ID', 'form_version' => 'str:64|null|src:Kobo_Form_Version', 'form_name' => 'str:255|null|src:Kobo_Form_Name',
            'submitted_at' => 'datetime|null|src:Kobo_Submission_Time', 'submitted_by' => 'str:100|null|src:Kobo_Submitted_By', 'kobo_status' => 'str:50|null|src:Kobo_Status',
            'validation_status' => 'text|null|src:Kobo_Validation_Status', 'is_deleted_in_kobo' => 'bool|src:Kobo_Is_Deleted', 'deleted_in_kobo_at' => 'datetime|null|src:Kobo_Deleted_At',
            'sync_status' => 'str:30|null|src:Sync_Status', 'local_table' => 'str:60|null|src:Local_Table_Name', 'local_record_id' => 'uint|null|src:Local_Record_ID',
            'raw_json' => 'json|null|src:Raw_JSON', 'raw_json_hash' => 'char:64|null|src:Raw_JSON_Hash', 'error_message' => 'text|null|src:Error_Message',
        ], 'note' => 'local_table/local_record_id are re-pointed to visits.id or me_visits.id'],
        'kobo_questions' => ['group' => 'kobo', 'scope' => 'city', 'source' => 'kobo_question', 'columns' => [
            'governorate' => 'str:50|null|src:Governorate', 'question_key' => 'char:64|src:Question_Key', 'label' => 'text|null|src:Label', 'answer_family' => 'str:50|null|src:Answer_Family',
        ]],
        'kobo_question_versions' => ['group' => 'kobo', 'scope' => 'city', 'source' => 'kobo_question_version', 'columns' => [
            'governorate' => 'str:50|null|src:Governorate', 'form_uid' => 'str:64|src:Kobo_Form_UID', 'form_version' => 'str:64|src:Form_Version',
            'field_path' => 'str:255|src:Field_Path', 'question_key' => 'char:64|src:Question_Key',
        ]],
        'kobo_monitoring_records' => ['group' => 'kobo', 'scope' => 'city', 'source' => 'kobo_monitoring_record', 'columns' => [
            'kobo_submission_id' => 'fk:kobo_submissions|src:Submission_ID', 'project_id' => 'fk:projects|null|src:MicroProject_ID|tx:project',
            'person_id' => 'fk:people|null|src:Person_ID|tx:person', 'person_role' => 'str:30|null|src:Person_Role', 'visit_number' => 'str:20|null|src:Visit_Number',
            'visit_kind' => 'str:30|null|src:Visit_Kind', 'visit_date' => 'date|null|src:Visit_Date|tx:date', 'me_visit_id' => 'fk:me_visits|null|src:Legacy_Visit_ID',
            'legacy_content_hash' => 'char:64|null|src:Legacy_Content_Hash', 'legacy_profit_known' => 'bool|null|src:Legacy_Profit_Known', 'legacy_answers_complete' => 'bool|null|src:Legacy_Answers_Complete',
        ]],
        'kobo_person_reviews' => ['group' => 'kobo', 'scope' => 'city', 'source' => 'kobo_person_review', 'columns' => [
            'kobo_submission_id' => 'fk:kobo_submissions|src:Submission_ID', 'project_id' => 'fk:projects|null|src:Project_ID|tx:project',
            'source_hash' => 'char:64|null|src:Source_Hash', 'kobo_name' => 'text|null|src:Kobo_Name', 'person_id' => 'fk:people|null|src:Person_ID|tx:person',
            'relationship_hash' => 'char:64|null|src:Relationship_Hash', 'state' => 'str:30|src:State', 'revision' => 'int|src:Revision',
        ]],
        'kobo_person_review_audits' => ['group' => 'kobo', 'scope' => 'city', 'source' => 'kobo_person_review_audit', 'columns' => [
            'kobo_submission_id' => 'fk:kobo_submissions|src:Submission_ID', 'project_id' => 'fk:projects|null|src:Project_ID|tx:project', 'person_id' => 'fk:people|null|src:Person_ID|tx:person',
            'decision' => 'str:30|src:Decision', 'reason' => 'text|null|src:Reason', 'actor_name' => 'str:100|null|src:Actor', 'actor_account_id' => 'fk:web_accounts|null|src:Actor_ID|tx:account',
            'recorded_at' => 'datetime|src:Recorded_UTC', 'source_hash' => 'char:64|null|src:Source_Hash', 'relationship_hash' => 'char:64|null|src:Relationship_Hash',
            'kobo_name' => 'text|null|src:Kobo_Name', 'candidate_json' => 'json|null|src:Candidate_JSON',
        ]],
        'kobo_sync_conflicts' => ['group' => 'kobo', 'scope' => 'city', 'source' => 'kobo_sync_conflict', 'columns' => [
            'incoming_submission_id' => 'fk:kobo_submissions|src:Incoming_ID', 'existing_submission_id' => 'fk:kobo_submissions|null|src:Existing_ID',
            'visit_id' => 'fk:visits|null|src:Visit_ID', 'incoming_hash' => 'char:64|null|src:Incoming_Hash', 'existing_hash' => 'char:64|null|src:Existing_Hash',
            'state' => 'str:30|src:State', 'revision' => 'int|src:Revision', 'decision' => 'str:30|null|src:Decision', 'reason' => 'text|null|src:Reason',
            'decided_by_name' => 'str:100|null|src:Decided_By', 'decided_by_account_id' => 'fk:web_accounts|null|src:Decided_User_ID|tx:account',
            'decided_at' => 'datetime|null|src:Decided_At', 'applied_at' => 'datetime|null|src:Applied_At',
        ]],
        'kobo_sync_conflict_audits' => ['group' => 'kobo', 'scope' => 'city', 'source' => 'kobo_sync_conflict_audit', 'columns' => [
            'kobo_sync_conflict_id' => 'fk:kobo_sync_conflicts|src:Conflict_ID', 'event' => 'str:30|src:Event', 'decision' => 'str:30|null|src:Decision',
            'reason' => 'text|null|src:Reason', 'actor_name' => 'str:100|null|src:Actor', 'actor_account_id' => 'fk:web_accounts|null|src:Actor_User_ID|tx:account',
            'recorded_at' => 'datetime|src:Recorded_At', 'incoming_json' => 'json|null|src:Incoming_JSON', 'existing_json' => 'json|null|src:Existing_JSON',
        ]],
        'kobo_user_mappings' => ['group' => 'kobo', 'scope' => 'city', 'source' => 'kobo_user_mapping', 'columns' => [
            'form_uid' => 'str:64|src:Kobo_Form_UID', 'kobo_name' => 'str:100|src:Kobo_Name', 'display_name' => 'str:255|null|src:Display_Name',
            'account_id' => 'fk:web_accounts|null|src:User_ID|tx:account', 'is_ignored' => 'bool|src:Is_Ignored', 'is_active' => 'bool|src:Is_Active',
        ]],
        'kobo_visit_visitors' => ['group' => 'kobo', 'scope' => 'city', 'source' => 'kobo_visit_visitor', 'columns' => [
            'kobo_submission_id' => 'fk:kobo_submissions|src:Submission_ID', 'visitor_order' => 'tinyint|src:Visitor_Order', 'kobo_name' => 'text|null|src:Kobo_Name',
            'display_name' => 'text|null|src:Display_Name', 'account_id' => 'fk:web_accounts|null|src:User_ID|tx:account',
        ]],

        // ---------------------------------------------------------------- profession meetings
        'profession_meetings' => ['group' => 'meetings', 'scope' => 'city', 'source' => 'profession_meeting', 'columns' => [
            'career_id' => 'fk:careers|null|src:Career_ID|tx:lookup', 'project_subcategory_id' => 'fk:project_subcategories|null|src:SubCategory_ID|tx:lookup',
            'meeting_date' => 'date|null|src:MeetingDate|tx:date', 'place' => 'str:255|null|src:Place', 'invited_count' => 'smallint|null|src:InvitedCount',
            'attendance_count' => 'smallint|null|src:AttendanceCount', 'absence_count' => 'smallint|null|src:AbsenceCount',
            'paper_registrar_account_id' => 'fk:web_accounts|null|src:PaperRegistrar_UserID|tx:account', 'program_registrar_account_id' => 'fk:web_accounts|null|src:ProgramRegistrar_UserID|tx:account',
            'edited_by_account_id' => 'fk:web_accounts|null|src:Edited_By_UserID|tx:account', 'record_state' => 'enum:draft,final|src:Record_State',
            'minutes_notes' => 'text|null|src:Minutes_Notes',
        ], 'note' => 'Aleppo 22 Oct rows have no Record_State: imported as final'],
        'profession_meeting_careers' => ['group' => 'meetings', 'scope' => 'city', 'source' => 'profession_meeting_career', 'columns' => [
            'profession_meeting_id' => 'fk:profession_meetings|src:Meeting_ID', 'career_id' => 'fk:careers|src:Career_ID|tx:lookup',
        ]],
        'profession_meeting_answers' => ['group' => 'meetings', 'scope' => 'city', 'source' => 'profession_meeting_answer', 'columns' => [
            'profession_meeting_id' => 'fk:profession_meetings|src:Meeting_ID', 'profession_question_id' => 'fk:profession_questions|src:Question_ID|tx:lookup',
            'value_text' => 'text|null|src:Value_Text', 'value_number' => 'int|null|src:Value_Number',
        ]],
        'profession_meeting_option_answers' => ['group' => 'meetings', 'scope' => 'city', 'source' => 'profession_meeting_option_answer', 'columns' => [
            'profession_meeting_id' => 'fk:profession_meetings|src:Meeting_ID', 'profession_option_id' => 'fk:profession_options|src:Option_ID|tx:lookup',
            'is_selected' => 'bool|src:Is_Selected', 'rank_value' => 'int|null|src:Rank_Value', 'other_text' => 'str:255|null|src:Other_Text',
        ]],
        'profession_meeting_attendees' => ['group' => 'meetings', 'scope' => 'city', 'source' => 'profession_meeting_attendee', 'columns' => [
            'profession_meeting_id' => 'fk:profession_meetings|src:Meeting_ID', 'person_id' => 'fk:people|null|src:Person_ID|tx:person',
            'project_id' => 'fk:projects|null|src:MicroProject_ID|tx:project', 'attendee_name' => 'str:255|null|src:Attendee_Name',
            'speciality' => 'str:255|null|src:Speciality', 'sort_order' => 'smallint|src:Sort_Order',
        ]],
        'profession_meeting_participants' => ['group' => 'meetings', 'scope' => 'city', 'source' => 'profession_meeting_participant', 'columns' => [
            'profession_meeting_id' => 'fk:profession_meetings|src:Meeting_ID', 'profession_question_id' => 'fk:profession_questions|null|src:Question_ID|tx:lookup',
            'person_id' => 'fk:people|null|src:Person_ID|tx:person', 'project_id' => 'fk:projects|null|src:MicroProject_ID|tx:project',
            'person_name' => 'str:255|null|src:Person_Name', 'speciality' => 'str:255|null|src:Speciality', 'notes' => 'str:255|null|src:Notes', 'sort_order' => 'smallint|src:Sort_Order',
        ]],
        'profession_meeting_brands' => ['group' => 'meetings', 'scope' => 'city', 'source' => 'profession_meeting_brand', 'columns' => [
            'profession_meeting_id' => 'fk:profession_meetings|src:Meeting_ID', 'brand_name' => 'str:255|src:Brand_Name', 'sort_order' => 'smallint|src:Sort_Order',
        ]],
        'profession_meeting_challenges' => ['group' => 'meetings', 'scope' => 'city', 'source' => 'profession_meeting_challenge', 'columns' => [
            'profession_meeting_id' => 'fk:profession_meetings|src:Meeting_ID', 'challenge' => 'str:255|src:Challenge', 'challenge_count' => 'int|null|src:Challenge_Count',
            'suggested_solution' => 'str:255|null|src:Suggested_Solution', 'sort_order' => 'smallint|src:Sort_Order',
        ]],
        'profession_meeting_competitions' => ['group' => 'meetings', 'scope' => 'city', 'source' => 'profession_meeting_competition', 'columns' => [
            'profession_meeting_id' => 'fk:profession_meetings|src:Meeting_ID', 'competition_level' => 'str:100|src:Competition_Level', 'count_value' => 'int|null|src:CountValue',
            'reason' => 'str:255|null|src:Reason', 'sort_order' => 'smallint|src:Sort_Order',
        ]],
        'profession_meeting_equipment' => ['group' => 'meetings', 'scope' => 'city', 'source' => 'profession_meeting_equipment', 'columns' => [
            'profession_meeting_id' => 'fk:profession_meetings|src:Meeting_ID', 'specialization' => 'str:255|null|src:Specialization', 'equipment' => 'text|null|src:Equipment', 'sort_order' => 'smallint|src:Sort_Order',
        ]],
        'profession_meeting_seasons' => ['group' => 'meetings', 'scope' => 'city', 'source' => 'profession_meeting_season', 'columns' => [
            'profession_meeting_id' => 'fk:profession_meetings|src:Meeting_ID', 'season_count' => 'int|null|src:Season_Count', 'from_period' => 'str:50|null|src:From_Period',
            'to_period' => 'str:50|null|src:To_Period', 'reason' => 'str:255|null|src:Reason', 'sort_order' => 'smallint|src:Sort_Order',
        ]],
        'profession_meeting_minutes' => ['group' => 'meetings', 'scope' => 'city', 'source' => 'profession_meeting_minute', 'columns' => [
            'profession_meeting_id' => 'fk:profession_meetings|src:Meeting_ID', 'profession_section_id' => 'fk:profession_sections|src:Section_ID|tx:lookup', 'notes' => 'text|null|src:Notes',
        ]],

        // ---------------------------------------------------------------- staff, notices, files, log
        'web_accounts' => ['group' => 'staff', 'scope' => 'existing', 'source' => 'user', 'note' => 'existing migrations 000005/000009 kept. Desktop users of the five cities become accounts through the reviewed duplicate-account process (staff:adopt-legacy); PBKDF2 passwords verified on first login and re-hashed with Argon2id; roles map to account_city_roles; IsVisitor/IsME/IsCommunication/IsCashier map to duties'],
        'account_legacy_links' => ['group' => 'staff', 'scope' => 'existing', 'note' => '(city, legacy UserID) -> account; used by tx:account'],
        'account_city_roles' => ['group' => 'staff', 'scope' => 'existing', 'source' => 'user.UserRoleID + role'],
        'account_city_capabilities' => ['group' => 'staff', 'scope' => 'existing'],
        'account_city_tasks' => ['group' => 'staff', 'scope' => 'existing', 'source' => 'task_user'],
        'account_password_resets' => ['group' => 'staff', 'scope' => 'existing'],
        'account_audit_events' => ['group' => 'staff', 'scope' => 'existing'],
        'audit_events' => ['group' => 'staff', 'scope' => 'existing', 'note' => 'web actions from now on'],
        'web_notices' => ['group' => 'staff', 'scope' => 'existing', 'source' => 'user_notification + kobo_visit_schedule', 'note' => 'imported notices keep body, seen, project, installment, sender and receiver; kobo_visit_schedule.Retired becomes a flag on the notice. Columns to add in M2: city_id, legacy_id, project_id, loan_installment_id, retired_by_kobo'],
        'attachments' => ['group' => 'staff', 'scope' => 'city', 'source' => 'image + file + profession_meeting_attachment', 'note' => 'image rows point to FTP files (image_NewPath); file rows hold the content in the database (Aleppo, 32 rows) and are written to private storage', 'columns' => [
            'project_id' => 'fk:projects|null|tx:project', 'person_id' => 'fk:people|null|tx:person', 'profession_meeting_id' => 'fk:profession_meetings|null',
            'attachment_type_id' => 'fk:attachment_types|null|tx:lookup', 'original_name' => 'str:255|null', 'stored_path' => 'str:500|null|tx:file',
            'extension' => 'str:10|null', 'legacy_source' => 'enum:image,file,meeting', 'uploaded_by_account_id' => 'fk:web_accounts|null|tx:account', 'uploaded_at' => 'datetime|null',
        ]],
        'desktop_activity_log' => ['group' => 'staff', 'scope' => 'city', 'source' => 'log', 'note' => 'read-only history of the desktop log (about 660,000 rows); new activity goes to audit_events', 'columns' => [
            'log_type' => 'str:100|null|src:Log_Type', 'on_table' => 'str:100|null|src:Log_OnTable', 'user_name' => 'str:100|null|src:Log_User',
            'logged_at' => 'datetime|null|src:Log_Date|tx:date', 'seen' => 'bool|null|src:Log_Seen|tx:bool01',
        ]],
    ],

    // Source tables that are archived only (kept in the hashed source archive, not imported).
    'not_imported' => [
        'backgrounds' => 'desktop home-screen image',
        'databases' => 'desktop city list; replaced by cities',
        'version' => 'desktop version check',
        'role' => 'role names; mapped to the web roles',
        'manualfile' => 'desktop manual (Aleppo, Latakia 1 row); the web has its own help page',
        'profession_meeting_migration' => 'desktop upgrade marker',
        't1' => 'temporary calculation table',
        'temp_gender' => 'Damascus temporary table',
        'dept' => 'Damascus temporary copy of person_microproject.P_HasDebts; all 2,043 values equal the main table (checked 19 Nov)',
        'work_exp' => 'Damascus older copy of person_microproject.P_WorkYears; 14 of 2,371 values were edited later in the main table, which stays authoritative',
        'resultstable' => 'Homs/AlWadi temporary report table',
        'visit_schedule_temp' => 'Homs temporary table',
        'kobo_monitoring_field_mapping' => 'merged into kobo_forms',
        'kobo_opening_field_mapping' => 'merged into kobo_forms',
        'kobo_monitoring_stage_mapping' => 'merged into kobo_forms',
        'kobo_visit_schedule' => 'merged into web_notices',
        'office_monitoring' => 'merged into visits (office_comments) and visit_answers',
        'rehabvisit' => 'merged into visit_answers',
    ],
];
