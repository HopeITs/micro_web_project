<?php

declare(strict_types=1);

namespace App\Services\People;

/**
 * The person form (M4.3) in the paper order of the desktop Application_New_Form person block (t3_tableLayoutPanel), and the
 * person sub-tables (the desktop grids) with their columns. Kinds: text (max), textarea, date, int, decimal, bool, sex,
 * lookup (table, city = the list belongs to the person's city).
 */
final class PersonFields
{
    public const PERSON = [
        'identity' => [
            'first_name' => ['kind' => 'text', 'max' => 100, 'required' => true],
            'last_name' => ['kind' => 'text', 'max' => 100, 'required' => true],
            'sex' => ['kind' => 'sex'],
            'full_name_en' => ['kind' => 'text', 'max' => 255],
            'father_name' => ['kind' => 'text', 'max' => 100],
            'mother_name' => ['kind' => 'text', 'max' => 100],
            'registration_place' => ['kind' => 'text', 'max' => 100],
            'national_number' => ['kind' => 'text', 'max' => 60],
            'birth_date' => ['kind' => 'date'],
            'marital_status_id' => ['kind' => 'lookup', 'table' => 'marital_statuses'],
            'parish_id' => ['kind' => 'lookup', 'table' => 'parishes'],
            'intermediary' => ['kind' => 'textarea'],
            'priest_id' => ['kind' => 'lookup', 'table' => 'priests', 'city' => true],
            'second_priest_id' => ['kind' => 'lookup', 'table' => 'priests', 'city' => true],
        ],
        'contact' => [
            'mobile' => ['kind' => 'text', 'max' => 60],
            'home_phone' => ['kind' => 'text', 'max' => 60],
            'mobile_2' => ['kind' => 'text', 'max' => 60],
            'street_id' => ['kind' => 'lookup', 'table' => 'streets', 'city' => true],
            'home_address' => ['kind' => 'textarea'],
            'home_ownership_id' => ['kind' => 'lookup', 'table' => 'asset_types'],
            'home_state_note' => ['kind' => 'text', 'max' => 255],
        ],
        // Shown for men only, as the desktop does (P_Sex_comboBox_SelectedIndexChanged).
        'military' => [
            'military_service' => ['kind' => 'text', 'max' => 50],
            'military_service_note' => ['kind' => 'text', 'max' => 255],
            'military_period' => ['kind' => 'int', 'min' => 0, 'max' => 600],
            'military_service_date' => ['kind' => 'date'],
            'military_in_war' => ['kind' => 'bool'],
        ],
        'other' => [
            'maristes_course' => ['kind' => 'text', 'max' => 100],
            'person_state_id' => ['kind' => 'lookup', 'table' => 'person_states'],
        ],
    ];

    /** Sub-tables of a person: key => table and columns (the desktop grid columns in order). */
    public const DETAILS = [
        'educations' => ['table' => 'person_educations', 'fields' => [
            'education_level_id' => ['kind' => 'lookup', 'table' => 'education_levels'],
            'education_specialty_id' => ['kind' => 'lookup', 'table' => 'education_specialties'],
        ]],
        'work_experiences' => ['table' => 'person_work_experiences', 'fields' => [
            // The desktop W_Status: yes = the current job, no = a previous one.
            'status' => ['kind' => 'choice', 'options' => ['yes', 'no']],
            'occupation_id' => ['kind' => 'lookup', 'table' => 'occupations'],
            'work_type_id' => ['kind' => 'lookup', 'table' => 'work_types'],
            'description' => ['kind' => 'textarea'],
            'begin_text' => ['kind' => 'text', 'max' => 50],
            'end_text' => ['kind' => 'text', 'max' => 50],
            'place_state' => ['kind' => 'text', 'max' => 100],
            'place' => ['kind' => 'textarea'],
            'contact' => ['kind' => 'textarea'],
            'salary' => ['kind' => 'decimal'],
            'leaving_reason' => ['kind' => 'textarea'],
        ]],
        'courses' => ['table' => 'person_courses', 'fields' => [
            'project_subcategory_id' => ['kind' => 'lookup', 'table' => 'project_subcategories'],
            'name' => ['kind' => 'text', 'max' => 255],
            'place' => ['kind' => 'text', 'max' => 255],
            'donor_text' => ['kind' => 'text', 'max' => 255],
            'date_text' => ['kind' => 'text', 'max' => 50],
        ]],
        'losses' => ['table' => 'person_losses', 'fields' => [
            'loss_type_id' => ['kind' => 'lookup', 'table' => 'loss_types'],
            'year_text' => ['kind' => 'text', 'max' => 20],
            'note' => ['kind' => 'textarea'],
        ]],
        'illnesses' => ['table' => 'person_illnesses', 'fields' => [
            'illness_id' => ['kind' => 'lookup', 'table' => 'illnesses'],
            'note' => ['kind' => 'textarea'],
            'has_medical_aid' => ['kind' => 'bool'],
            'donor_text' => ['kind' => 'textarea'],
            'aid_source_id' => ['kind' => 'lookup', 'table' => 'aid_sources'],
            'aid_period_id' => ['kind' => 'lookup', 'table' => 'aid_periods'],
            'medical_need_id' => ['kind' => 'lookup', 'table' => 'medical_needs'],
        ]],
        'assets' => ['table' => 'person_assets', 'fields' => [
            'asset_kind_id' => ['kind' => 'lookup', 'table' => 'asset_kinds'],
            'year_text' => ['kind' => 'text', 'max' => 20],
            'asset_type_id' => ['kind' => 'lookup', 'table' => 'asset_types'],
            'description' => ['kind' => 'textarea'],
            'asset_benefit_id' => ['kind' => 'lookup', 'table' => 'asset_benefits'],
            'note' => ['kind' => 'textarea'],
        ]],
        'aid_sources' => ['table' => 'person_aid_sources', 'fields' => [
            'aid_source_id' => ['kind' => 'lookup', 'table' => 'aid_sources'],
            'year_text' => ['kind' => 'text', 'max' => 20],
            'amount_text' => ['kind' => 'textarea'],
            'project_type_text' => ['kind' => 'textarea'],
            'notes' => ['kind' => 'textarea'],
        ]],
        'aid_received' => ['table' => 'person_aid_received', 'fields' => [
            'aid_source_id' => ['kind' => 'lookup', 'table' => 'aid_sources'],
            'aid_type_id' => ['kind' => 'lookup', 'table' => 'aid_types'],
            'aid_period_id' => ['kind' => 'lookup', 'table' => 'aid_periods'],
            'notes' => ['kind' => 'textarea'],
        ]],
        'goals' => ['table' => 'person_goals', 'fields' => [
            'goal_id' => ['kind' => 'lookup', 'table' => 'goals', 'required' => true],
        ]],
        'power_sources' => ['table' => 'person_power_sources', 'fields' => [
            'power_source_id' => ['kind' => 'lookup', 'table' => 'power_sources'],
            'note' => ['kind' => 'textarea'],
            'cost' => ['kind' => 'decimal'],
            'responsibility' => ['kind' => 'textarea'],
        ]],
    ];

    /** Family member row (desktop FamilyMember grid). */
    public const MEMBER = [
        'relation_id' => ['kind' => 'lookup', 'table' => 'relations'],
        'education_level_id' => ['kind' => 'lookup', 'table' => 'education_levels'],
        'education_specialty_id' => ['kind' => 'lookup', 'table' => 'education_specialties'],
        'has_work' => ['kind' => 'bool'],
        'work_name' => ['kind' => 'textarea'],
    ];

    /** @return array<string, array> every person field, flat */
    public static function personFlat(): array
    {
        return array_merge(...array_values(self::PERSON));
    }
}
