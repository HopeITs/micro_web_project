<?php

declare(strict_types=1);

namespace App\Services\Applications;

use Illuminate\Support\Facades\DB;

/**
 * Whether an application may become full (M4.5): the rules of docs/delivery/full-application-readiness.md (from the desktop
 * Save_Application with every validate flag on), checked on the validated form values before anything is written. Catalog names
 * ("لا يعمل", "غير متعلّم", "ملك") are matched by exact text as the desktop does.
 */
final class ApplicationReadiness
{
    private const MALE_SERVICE = ['مسرح', 'عسكري'];

    private const NOT_WORKING = 'لا يعمل';

    private const NOT_EDUCATED = 'غير متعلّم';

    private const OWNED = 'ملك';

    /** @var list<array{section: string, code: string, field: string, row: ?int}> */
    private array $issues = [];

    /**
     * @param  array{project: array, person: array, applicant: array, grids: array<string, list<array>>}  $v
     * @return list<array{section: string, code: string, field: string, row: ?int}>
     */
    public function check(array $v, string $familyNumber): array
    {
        $this->issues = [];
        $p = $v['person'];
        $a = $v['applicant'];
        $g = $v['grids'];

        foreach (['first_name', 'last_name', 'father_name', 'mother_name', 'sex', 'national_number', 'birth_date', 'intermediary', 'home_address', 'marital_status_id', 'parish_id', 'street_id', 'home_ownership_id'] as $f) {
            $this->need('beneficiary', $p, $f);
        }
        if (empty($p['priest_id']) && empty($p['second_priest_id'])) {
            $this->add('beneficiary', 'required', 'priest_id');
        }
        if (empty($p['mobile']) && empty($p['home_phone']) && empty($p['mobile_2'])) {
            $this->add('beneficiary', 'required', 'contact');
        }
        if (($p['sex'] ?? null) === 'male' && in_array($p['military_service'] ?? null, self::MALE_SERVICE, true)) {
            $this->need('beneficiary', $p, 'military_period');
            $this->chosen('beneficiary', $p, 'military_in_war');
        }

        foreach (['loss_story', 'goal', 'goal_how'] as $f) {
            $this->need('story', $a, $f);
        }

        // Work: "yes" needs a current job; current and previous rows need their details (unless the previous work is "not working").
        $this->chosen('work', $a, 'has_work');
        $this->need('work', $a, 'work_years');
        $current = array_filter($g['work_experiences'], fn ($r) => ($r['status'] ?? null) === 'yes');
        $prior = array_filter($g['work_experiences'], fn ($r) => ($r['status'] ?? null) === 'no');
        if (($a['has_work'] ?? null) === true && $current === []) {
            $this->add('work', 'required_rows', 'current');
        }
        foreach ($current as $i => $r) {
            foreach (['occupation_id', 'work_type_id', 'begin_text', 'place_state', 'place', 'salary'] as $f) {
                $this->need('work', $r, $f, $i);
            }
        }
        foreach ($prior as $i => $r) {
            foreach (['occupation_id', 'begin_text', 'end_text'] as $f) {
                $this->need('work', $r, $f, $i);
            }
            if (! empty($r['occupation_id']) && $this->name('occupations', $r['occupation_id']) !== self::NOT_WORKING) {
                foreach (['work_type_id', 'place_state', 'place', 'leaving_reason'] as $f) {
                    $this->need('work', $r, $f, $i);
                }
            }
        }

        $this->chosen('education', $a, 'has_education');
        $this->rows('education', $a, 'has_education', $g['educations'], 'educations', ['education_level_id', 'education_specialty_id']);
        $this->chosen('education', $a, 'has_courses');
        $this->rows('education', $a, 'has_courses', $g['courses'], 'courses', ['project_subcategory_id', 'name', 'date_text']);

        $project = $v['project'];
        foreach (['fund_type_id', 'requested_amount', 'place_type_id'] as $f) {
            $this->need('project', $project, $f);
        }
        foreach (range(1, 5) as $n) {
            $this->chosen('project', $project, 'agreement_'.$n);
        }
        if ($g['materials'] === []) {
            $this->add('project', 'required_rows', 'materials');
        }
        foreach ($g['materials'] as $i => $r) {
            foreach (['name', 'price', 'quantity'] as $f) {
                $this->need('project', $r, $f, $i);
            }
        }
        foreach ($g['project_power_sources'] as $i => $r) {
            $this->need('project', $r, 'power_source_id', $i);
        }

        if (trim($familyNumber) === '') {
            $this->add('family', 'required', 'family_number');
        }
        $this->chosen('family', $a, 'lives_with_family');
        foreach ($g['family'] as $i => $r) {
            foreach (['first_name', 'last_name', 'father_name', 'birth_date', 'sex', 'national_number', 'relation_id', 'person_state_id', 'education_level_id'] as $f) {
                $this->need('family', $r, $f, $i);
            }
            if (! empty($r['education_level_id']) && $this->name('education_levels', $r['education_level_id']) !== self::NOT_EDUCATED) {
                $this->need('family', $r, 'education_specialty_id', $i);
            }
        }

        foreach (['has_loss', 'has_illness', 'has_assets', 'has_aid_sources', 'has_debts', 'has_help'] as $f) {
            $this->chosen('support', $a, $f);
        }
        $this->rows('support', $a, 'has_loss', $g['losses'], 'losses', ['loss_type_id', 'year_text']);
        $this->rows('support', $a, 'has_illness', $g['illnesses'], 'illnesses', ['illness_id']);
        foreach ($g['illnesses'] as $i => $r) {
            if (($r['has_medical_aid'] ?? null) === true) {
                $this->need('support', $r, 'aid_source_id', $i);
            }
        }
        $this->rows('support', $a, 'has_assets', $g['assets'], 'assets', ['asset_kind_id', 'asset_type_id', 'asset_benefit_id']);
        foreach ($g['assets'] as $i => $r) {
            if (! empty($r['asset_type_id']) && $this->name('asset_types', $r['asset_type_id']) === self::OWNED) {
                $this->need('support', $r, 'year_text', $i);
            }
        }
        $this->rows('support', $a, 'has_aid_sources', $g['aid_sources'], 'aid_sources', ['aid_source_id', 'year_text']);
        $this->rows('support', $a, 'has_help', $g['aid_received'], 'aid_received', ['aid_source_id', 'aid_type_id', 'aid_period_id']);
        if ($g['power_sources'] === []) {
            $this->add('support', 'required_rows', 'power_sources');
        }
        foreach ($g['power_sources'] as $i => $r) {
            foreach (['power_source_id', 'note', 'responsibility'] as $f) {
                $this->need('support', $r, $f, $i);
            }
        }

        return $this->issues;
    }

    /** One readable line per issue: "Section — field: missing (row n)". @return list<string> */
    public function messages(array $issues): array
    {
        return array_map(function (array $issue) {
            $label = $issue['field'];
            foreach (['applications.fields.', 'people.fields.', 'applications.grids.', 'fullapp.fields.'] as $prefix) {
                if (__($prefix.$issue['field']) !== $prefix.$issue['field']) {
                    $label = __($prefix.$issue['field']);
                    break;
                }
            }
            $line = __('fullapp.sections.'.$issue['section']).' — '.$label.': '.__('fullapp.codes.'.$issue['code']);

            return $issue['row'] === null ? $line : $line.' ('.__('fullapp.row', ['id' => $issue['row'] + 1]).')';
        }, $issues);
    }

    private function add(string $section, string $code, string $field, ?int $row = null): void
    {
        $this->issues[] = ['section' => $section, 'code' => $code, 'field' => $field, 'row' => $row];
    }

    private function need(string $section, array $values, string $field, ?int $row = null): void
    {
        $value = $values[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $this->add($section, 'required', $field, $row);
        }
    }

    private function chosen(string $section, array $values, string $field): void
    {
        if (! is_bool($values[$field] ?? null)) {
            $this->add($section, 'choice', $field);
        }
    }

    /** A "yes" switch needs rows; every row needs the fields. */
    private function rows(string $section, array $applicant, string $switch, array $rows, string $grid, array $fields): void
    {
        if (($applicant[$switch] ?? null) === true && $rows === []) {
            $this->add($section, 'required_rows', $grid);
        }
        foreach ($rows as $i => $r) {
            foreach ($fields as $f) {
                $this->need($section, $r, $f, $i);
            }
        }
    }

    private function name(string $table, int $id): ?string
    {
        return DB::table($table)->where('id', $id)->value('name_ar');
    }
}
