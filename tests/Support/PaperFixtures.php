<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * A fully filled, invented application for the print tests and the browser checks of the official papers (M4.20).
 * Every name and number is made up. `richApplication` fills the project's beneficiary and returns what it created, so a
 * caller can remove it again (`remove`).
 */
final class PaperFixtures
{
    /** @return array<string, list<int>> the rows created, by table */
    public static function richApplication(int $projectId): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $project = DB::table('projects')->where('id', $projectId)->first();
        $city = (int) $project->city_id;
        $link = DB::table('project_applicants')->where('project_id', $projectId)->orderBy('id')->first();
        $personId = (int) $link->person_id;
        $id = fn (string $table, int $n = 0) => (int) (DB::table($table)->orderBy('id')->skip($n)->value('id') ?? 0) ?: null;
        $made = [];
        $insert = function (string $table, array $row) use (&$made, $city, $now): int {
            $newId = (int) DB::table($table)->insertGetId($row + ['city_id' => $city, 'created_at' => $now, 'updated_at' => $now]);
            $made[$table][] = $newId;

            return $newId;
        };

        DB::table('people')->where('id', $personId)->update([
            'father_name' => 'سليمان', 'mother_name' => 'ريما', 'registration_place' => 'حلب 1234/5', 'national_number' => '01020304050', 'birth_date' => '1986-04-12',
            'marital_status_id' => $id('marital_statuses'), 'parish_id' => $id('parishes'), 'intermediary' => 'لجنة الرعية', 'mobile' => '0999111222', 'mobile_2' => '0999333444',
            'home_phone' => '0211234567', 'home_address' => 'شارع الجلاء، بناء 7، طابق 2', 'home_state_note' => 'عقد إيجار سنوي', 'home_ownership_id' => $id('asset_types'),
            'military_service' => 'مؤدّاة', 'military_period' => 24, 'military_service_date' => '2011-03-01', 'military_in_war' => 1, 'family_size' => 5, 'household_size' => 4,
            'full_name_en' => 'Jood Test', 'sex' => 'male', 'updated_at' => $now,
        ]);
        DB::table('projects')->where('id', $projectId)->update([
            'request_date' => '2026-11-10', 'name' => 'ورشة خياطة صغيرة', 'requested_amount' => 12500000, 'speciality' => 'خياطة', 'fund_type_id' => $id('fund_types'),
            'career_id' => $id('careers'), 'project_type_id' => $id('project_types'), 'project_subtype_id' => $id('project_subtypes'), 'place_type_id' => $id('asset_types', 1),
            'place_address' => 'حي الأعظمية، قرب الجامع', 'place_note' => 'محل مستأجر', 'amper_number' => 15, 'amper_type' => 1,
            'agreement_1' => 1, 'agreement_2' => 1, 'agreement_3' => 0, 'agreement_4' => 1, 'agreement_5' => 0, 'profit_description' => 'يرغب بتوسيع الورشة لاحقاً', 'updated_at' => $now,
        ]);
        DB::table('project_applicants')->where('id', $link->id)->update([
            'loss_story' => 'فقد المستفيد عمله بعد إغلاق المعمل الذي كان يعمل فيه، ويعيل أسرته من أعمال متفرقة.', 'goal' => 'تأمين دخل ثابت للأسرة',
            'goal_how' => 'من خلال الورشة سيؤمن دخلاً شهرياً كافياً', 'other_notes' => 'ملاحظات اختبارية', 'has_work' => 1, 'work_years' => '8', 'has_education' => 1, 'has_courses' => 1,
            'lives_with_family' => 1, 'lives_with_family_note' => 'عائلة الأخ', 'has_loss' => 1, 'has_illness' => 1, 'has_assets' => 1, 'has_aid_sources' => 1, 'has_help' => 1,
            'has_debts' => 1, 'debts_amount' => '2,000,000', 'debts_note' => 'دين لأحد الأقارب', 'updated_at' => $now,
        ]);

        $insert('person_work_experiences', ['person_id' => $personId, 'status' => 'yes', 'occupation_id' => $id('occupations'), 'work_type_id' => $id('work_types'), 'description' => 'خياط رجالي', 'begin_text' => '2022', 'place_state' => 'إيجار', 'place' => 'حلب - الجميلية', 'contact' => 'أبو سامي 0999555666', 'salary' => 450000]);
        $insert('person_work_experiences', ['person_id' => $personId, 'status' => 'no', 'occupation_id' => $id('occupations', 1), 'work_type_id' => $id('work_types', 1), 'description' => 'عامل في معمل', 'begin_text' => '2015', 'end_text' => '2021', 'place_state' => 'ملك', 'place' => 'المنطقة الصناعية', 'contact' => 'إدارة المعمل', 'leaving_reason' => 'إغلاق المعمل']);
        $insert('person_work_experiences', ['person_id' => $personId, 'status' => 'no', 'occupation_id' => $id('occupations', 2), 'work_type_id' => $id('work_types'), 'description' => 'مساعد', 'begin_text' => '2010', 'end_text' => '2014', 'leaving_reason' => 'السفر']);
        $insert('person_educations', ['person_id' => $personId, 'education_level_id' => $id('education_levels'), 'education_specialty_id' => $id('education_specialties')]);
        $insert('person_educations', ['person_id' => $personId, 'education_level_id' => $id('education_levels', 1), 'education_specialty_id' => $id('education_specialties', 1)]);
        $insert('person_courses', ['person_id' => $personId, 'project_subcategory_id' => $id('project_subcategories'), 'name' => 'دورة خياطة متقدمة', 'place' => 'معهد الأمل', 'donor_text' => 'جمعية خيرية', 'date_text' => '2023']);
        $insert('person_losses', ['person_id' => $personId, 'loss_type_id' => $id('loss_types'), 'year_text' => '2016', 'note' => 'خسارة محل']);
        $insert('person_illnesses', ['person_id' => $personId, 'illness_id' => $id('illnesses'), 'note' => 'ضغط شرياني', 'has_medical_aid' => 1, 'donor_text' => 'مستوصف الحي', 'aid_period_id' => $id('aid_periods'), 'medical_need_id' => $id('medical_needs')]);
        $insert('person_assets', ['person_id' => $personId, 'asset_kind_id' => $id('asset_kinds'), 'year_text' => '2019', 'asset_type_id' => $id('asset_types'), 'description' => 'قطعة أرض صغيرة', 'asset_benefit_id' => $id('asset_benefits')]);
        $insert('person_aid_sources', ['person_id' => $personId, 'aid_source_id' => $id('aid_sources'), 'year_text' => '2020', 'amount_text' => '500$', 'project_type_text' => 'مشروع صغير', 'notes' => 'ماكينة خياطة']);
        $insert('person_aid_received', ['person_id' => $personId, 'aid_source_id' => $id('aid_sources', 1), 'aid_type_id' => $id('aid_types'), 'aid_period_id' => $id('aid_periods'), 'notes' => 'سلة غذائية']);
        $insert('person_power_sources', ['person_id' => $personId, 'power_source_id' => $id('power_sources'), 'note' => 'أمبيرات', 'cost' => 80000, 'responsibility' => 'شخصي']);
        foreach ([0, 2, 5] as $n) {
            if ($goal = $id('goals', $n)) {
                $insert('person_goals', ['person_id' => $personId, 'goal_id' => $goal]);
            }
        }
        $insert('project_materials', ['project_id' => $projectId, 'name' => 'ماكينة خياطة صناعية', 'comments' => 'جديدة', 'quantity' => 2, 'price' => 3500000, 'local_contribution' => 0]);
        $insert('project_materials', ['project_id' => $projectId, 'name' => 'طاولة قص', 'comments' => 'خشب', 'quantity' => 1, 'price' => 900000, 'local_contribution' => 0]);
        $insert('project_materials', ['project_id' => $projectId, 'name' => 'مكواة بخار', 'comments' => 'مستعملة', 'quantity' => 1, 'price' => 600000, 'local_contribution' => 0]);
        $insert('project_power_sources', ['project_id' => $projectId, 'power_source_id' => $id('power_sources'), 'note' => 'اشتراك أمبيرات', 'cost' => 120000, 'responsibility' => 'شخصي']);

        // The family: the beneficiary's own family row plus three members.
        $familyId = (int) DB::table('family_members')->where('person_id', $personId)->value('family_id');
        foreach ([['ميساء', 'ابنة', 2014, 0], ['فادي', 'ابن', 2016, 0], ['سعاد', 'زوجة', 1988, 1]] as $i => [$first, , $born, $works]) {
            $member = $insert('people', ['first_name' => $first, 'last_name' => 'الاختبار', 'father_name' => 'جود', 'sex' => $i === 1 ? 'male' : 'female', 'birth_date' => $born.'-01-01', 'national_number' => '0'.(1000000000 + $i), 'person_state_id' => $id('person_states')]);
            $insert('family_members', ['family_id' => $familyId, 'person_id' => $member, 'relation_id' => $id('relations', $i), 'education_level_id' => $id('education_levels'), 'education_specialty_id' => $id('education_specialties'), 'has_work' => $works, 'work_name' => $works ? 'خياطة منزلية' : null, 'provider_person_id' => $personId]);
        }

        return $made;
    }

    /** Removes what `richApplication` created (the updates to the existing rows stay). */
    public static function remove(array $made): void
    {
        foreach (array_reverse($made, true) as $table => $ids) {
            DB::table($table)->whereIn('id', $ids)->delete();
        }
    }
}
