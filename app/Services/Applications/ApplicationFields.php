<?php

declare(strict_types=1);

namespace App\Services\Applications;

use App\Services\People\PersonFields;

/**
 * The application form (M4.5) in the paper order of the desktop Application_New_Form, on the unified schema. `full` marks what the
 * desktop hides on the preliminary form (ApplicationState "zero", Application_New_Form.cs:564–596). Field kinds are those of
 * {@see PersonFields} and are validated by PeopleService::validate.
 */
final class ApplicationFields
{
    /** projects columns, grouped as on paper. */
    public const PROJECT = [
        // استمارة المشروع
        'project' => [
            'project_subcategory_id' => ['kind' => 'lookup', 'table' => 'project_subcategories', 'group' => ['table' => 'project_categories', 'column' => 'project_category_id']],
            // The careers of the chosen field only (283 in all); choosing a career first fills its field.
            'career_id' => ['kind' => 'lookup', 'table' => 'careers', 'parent' => 'project_subcategory_id', 'parent_column' => 'project_subcategory_id'],
            'speciality' => ['kind' => 'text', 'max' => 255],
            'project_type_id' => ['kind' => 'lookup', 'table' => 'project_types'],
            'request_date' => ['kind' => 'date'],
            'project_subtype_id' => ['kind' => 'lookup', 'table' => 'project_subtypes', 'parent' => 'project_type_id', 'parent_column' => 'project_type_id'],
            'name' => ['kind' => 'textarea', 'required' => true],
            'requested_amount' => ['kind' => 'decimal', 'full' => true],
            'fund_type_id' => ['kind' => 'lookup', 'table' => 'fund_types', 'full' => true],
        ],
        // معلومات خاصة بالمشروع
        'premises' => [
            'place_type_id' => ['kind' => 'lookup', 'table' => 'asset_types', 'full' => true],
            'place_address' => ['kind' => 'textarea', 'full' => true],
            'place_note' => ['kind' => 'textarea', 'full' => true],
            'amper_type' => ['kind' => 'int', 'max' => 1000, 'full' => true],
            'amper_number' => ['kind' => 'int', 'max' => 1000, 'full' => true],
            'agreement_1' => ['kind' => 'bool', 'full' => true],
            'agreement_2' => ['kind' => 'bool', 'full' => true],
            'agreement_3' => ['kind' => 'bool', 'full' => true],
            'agreement_4' => ['kind' => 'bool', 'full' => true],
            'agreement_5' => ['kind' => 'bool', 'full' => true],
        ],
        'extra' => [
            'profit_description' => ['kind' => 'textarea'],
            'income_kind' => ['kind' => 'text', 'max' => 100, 'full' => true],
            'income_kind_note' => ['kind' => 'text', 'max' => 255, 'full' => true],
            'street_id' => ['kind' => 'lookup', 'table' => 'streets', 'city' => true, 'full' => true],
            'note' => ['kind' => 'textarea', 'full' => true],
        ],
    ];

    /** project_applicants columns of the beneficiary: the story block and the has/has-not switches above the desktop grids. */
    public const APPLICANT = [
        'story' => [
            'loss_story' => ['kind' => 'textarea', 'full' => true],
            'goal' => ['kind' => 'textarea', 'full' => true],
            'goal_how' => ['kind' => 'textarea', 'full' => true],
            'other_notes' => ['kind' => 'textarea', 'full' => true],
        ],
        'work' => [
            'has_work' => ['kind' => 'bool'],
            'work_years' => ['kind' => 'text', 'max' => 255],
            'has_education' => ['kind' => 'bool', 'full' => true],
            'has_courses' => ['kind' => 'bool', 'full' => true],
        ],
        'family' => [
            'lives_with_family' => ['kind' => 'bool'],
            'lives_with_family_note' => ['kind' => 'textarea'],
            'has_loss' => ['kind' => 'bool', 'full' => true],
            'has_illness' => ['kind' => 'bool', 'full' => true],
            'has_assets' => ['kind' => 'bool', 'full' => true],
        ],
        'aid' => [
            'has_aid_sources' => ['kind' => 'bool'],
            'aid_source_note' => ['kind' => 'textarea'],
            'has_debts' => ['kind' => 'bool', 'full' => true],
            'debts_amount' => ['kind' => 'textarea', 'full' => true],
            'debts_note' => ['kind' => 'textarea', 'full' => true],
            'has_help' => ['kind' => 'bool', 'full' => true],
        ],
    ];

    /**
     * Yes/no questions that open their own table or fields (owner request, 20 Nov 2026): the table sits right under its question and
     * rows can be added only after "Yes". `only` limits the rule to some rows: "works now" governs the current job, while previous
     * jobs can always be entered (owner decision). Answering "No" takes the governed rows and fields out, with an undo, until saving.
     */
    public const SWITCHES = [
        'has_work' => ['grid' => 'work_experiences', 'only' => ['status' => 'yes']],
        'has_education' => ['grid' => 'educations'],
        'has_courses' => ['grid' => 'courses'],
        'has_loss' => ['grid' => 'losses'],
        'has_illness' => ['grid' => 'illnesses'],
        'has_assets' => ['grid' => 'assets'],
        'has_aid_sources' => ['grid' => 'aid_sources', 'fields' => ['aid_source_note']],
        'has_debts' => ['fields' => ['debts_amount', 'debts_note']],
        'has_help' => ['grid' => 'aid_received'],
    ];

    /** Whether a grid row is governed by its question (all rows, or those matching `only`). */
    public static function governed(string $switch, array $row): bool
    {
        foreach (self::SWITCHES[$switch]['only'] ?? [] as $field => $value) {
            if (($row[$field] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> grid => its question */
    public static function gridSwitches(): array
    {
        $out = [];
        foreach (self::SWITCHES as $switch => $rule) {
            if (isset($rule['grid'])) {
                $out[$rule['grid']] = $switch;
            }
        }

        return $out;
    }

    /** Person fields the desktop hides on the preliminary form. */
    public const PERSON_FULL_ONLY = ['full_name_en', 'registration_place', 'priest_id', 'second_priest_id', 'mobile_2', 'military_service', 'military_service_note', 'military_period', 'military_service_date', 'military_in_war'];

    /**
     * The internal grids (the desktop DataGridViews): the beneficiary's family and sub-tables, and the project's own tables.
     * `owner` says whose rows they are; `full` grids are hidden on the preliminary form.
     */
    public const GRIDS = [
        'family' => ['owner' => 'family', 'section' => 'family'],
        'work_experiences' => ['owner' => 'person', 'section' => 'work'],
        'educations' => ['owner' => 'person', 'full' => true, 'section' => 'work'],
        'courses' => ['owner' => 'person', 'full' => true, 'section' => 'work'],
        'losses' => ['owner' => 'person', 'full' => true, 'section' => 'family'],
        'illnesses' => ['owner' => 'person', 'full' => true, 'section' => 'family'],
        'assets' => ['owner' => 'person', 'full' => true, 'section' => 'family'],
        'aid_sources' => ['owner' => 'person', 'section' => 'aid'],
        'aid_received' => ['owner' => 'person', 'full' => true, 'section' => 'aid'],
        'power_sources' => ['owner' => 'person', 'section' => 'aid', 'full' => true],
        'goals' => ['owner' => 'person', 'section' => 'story', 'full' => true],
        'materials' => ['owner' => 'project', 'section' => 'premises', 'full' => true, 'table' => 'project_materials', 'fields' => [
            'name' => ['kind' => 'textarea'],
            'quantity' => ['kind' => 'decimal'],
            'price' => ['kind' => 'decimal'],
            'local_contribution' => ['kind' => 'decimal'],
            'comments' => ['kind' => 'textarea'],
        ]],
        'project_power_sources' => ['owner' => 'project', 'section' => 'premises', 'full' => true, 'table' => 'project_power_sources', 'fields' => [
            'power_source_id' => ['kind' => 'lookup', 'table' => 'power_sources'],
            'note' => ['kind' => 'textarea'],
            'cost' => ['kind' => 'decimal'],
            'responsibility' => ['kind' => 'textarea'],
        ]],
        'risks' => ['owner' => 'project', 'section' => 'premises', 'full' => true, 'table' => 'project_risks', 'fields' => [
            'risk_id' => ['kind' => 'lookup', 'table' => 'risks', 'required' => true],
        ]],
    ];

    /** The family grid: the member as a person and as a family row. */
    public const FAMILY_PERSON = ['first_name', 'father_name', 'last_name', 'sex', 'birth_date', 'national_number', 'person_state_id'];

    /** @return array{table: string, fields: array<string, array>} */
    public static function grid(string $kind): array
    {
        $grid = self::GRIDS[$kind];
        if ($kind === 'family') {
            $person = array_intersect_key(PersonFields::personFlat(), array_flip(self::FAMILY_PERSON));
            $person['first_name']['required'] = true;
            $person['last_name']['required'] = false;

            return ['table' => 'family_members', 'fields' => $person + PersonFields::MEMBER];
        }
        if ($grid['owner'] === 'person') {
            return PersonFields::DETAILS[$kind];
        }

        return ['table' => $grid['table'], 'fields' => $grid['fields']];
    }

    /** @param  array<string, array<string, array>>  $groups  @return array<string, array> */
    public static function flat(array $groups, string $stage): array
    {
        $out = [];
        foreach ($groups as $fields) {
            foreach ($fields as $field => $spec) {
                if ($stage === 'full' || ! ($spec['full'] ?? false)) {
                    $out[$field] = $spec;
                }
            }
        }

        return $out;
    }

    /** @return array<string, array> the person fields shown at the stage */
    public static function person(string $stage): array
    {
        $fields = PersonFields::personFlat();

        return $stage === 'full' ? $fields : array_diff_key($fields, array_flip(self::PERSON_FULL_ONLY));
    }

    /** @return list<string> grids shown at the stage */
    public static function grids(string $stage): array
    {
        return array_keys(array_filter(self::GRIDS, fn ($g) => $stage === 'full' || ! ($g['full'] ?? false)));
    }
}
