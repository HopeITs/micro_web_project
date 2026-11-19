<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Invented sample data for building and testing the site before the real data is transferred (M2.3).
 * Every name, number and amount here is made up. Per city: streets, priests, committee members, people
 * with families, projects (numbers 1001+ in every city, so the same number exists in all five cities),
 * applicants, state history, loans with instalments, visits and notices. Deterministic; refuses production.
 */
final class SyntheticDataSeeder extends Seeder
{
    private const FIRST_M = ['جورج', 'إلياس', 'ميشيل', 'فادي', 'رامي', 'سامر', 'نبيل', 'يوسف', 'حنا', 'طوني', 'باسل', 'شادي', 'وسيم', 'ماهر', 'عماد'];

    private const FIRST_F = ['ماري', 'رنا', 'لينا', 'هالة', 'نسرين', 'ريم', 'سلوى', 'جورجيت', 'منى', 'ديما', 'رولا', 'سمر', 'هيام', 'نادين', 'كارولين'];

    private const LAST = ['حداد', 'خوري', 'نصار', 'عبود', 'شاهين', 'سعادة', 'معلوف', 'قسيس', 'بشارة', 'دياب', 'غنطوس', 'زيدان', 'منصور', 'عيسى', 'جبور'];

    private const STREETS = ['الشارع الرئيسي', 'حي الكنيسة', 'ساحة البلدية', 'طريق المدرسة', 'الحي الشرقي', 'الحي الغربي', 'شارع السوق'];

    private const PROJECTS = ['محل بقالة', 'ورشة نجارة', 'صالون حلاقة', 'تكسي أجرة', 'محل خياطة', 'بسطة خضار', 'ورشة صيانة موبايلات', 'فرن معجنات', 'مزرعة دواجن', 'محل أدوات منزلية'];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Synthetic data is never seeded in production.');
        }
        if (DB::table('projects')->exists()) {
            $this->command?->warn('projects already exist; synthetic data not added again');

            return;
        }
        mt_srand(20261120);
        $now = now();
        $donors = [];
        foreach (['مانح تجريبي أ', 'مانح تجريبي ب', 'مانح تجريبي ج'] as $i => $name) {
            $id = DB::table('donors')->insertGetId(['name_ar' => $name, 'name_en' => 'Sample donor '.chr(65 + $i), 'abbreviation' => 'SD'.($i + 1), 'sort_order' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            $donors[$id] = [];
            foreach ([1, 2] as $g) {
                $donors[$id][] = DB::table('donor_groups')->insertGetId(['donor_id' => $id, 'name_ar' => "دفعة {$g} - {$name}", 'sort_order' => $g, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $lookup = fn (string $t) => DB::table($t)->pluck('id')->all();
        $careers = DB::table('careers')->whereNotNull('project_subcategory_id')->get(['id', 'project_subcategory_id'])->all();
        $subcats = DB::table('project_subcategories')->pluck('project_category_id', 'id')->all();
        $states = DB::table('project_states')->pluck('id', 'name_en')->all();
        $relations = $lookup('relations');
        $marital = $lookup('marital_statuses');
        $fundTypes = $lookup('fund_types');
        $types = DB::table('project_subtypes')->get(['id', 'project_type_id'])->all();

        foreach (DB::table('cities')->orderBy('id')->get() as $city) {
            $c = $city->id;
            $streets = [];
            foreach (self::STREETS as $i => $s) {
                $streets[] = DB::table('streets')->insertGetId(['city_id' => $c, 'name_ar' => $s, 'sort_order' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach (['الأب '.self::FIRST_M[0].' '.self::LAST[$c % 15], 'الأب '.self::FIRST_M[6].' '.self::LAST[($c + 3) % 15]] as $i => $p) {
                DB::table('priests')->insert(['city_id' => $c, 'name_ar' => $p, 'sort_order' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            $positions = $lookup('checklist_positions');
            for ($i = 0; $i < 5; $i++) {
                DB::table('checklist_members')->insert(['city_id' => $c, 'name_ar' => $this->name(true), 'checklist_position_id' => $positions[$i % count($positions)] ?? null, 'sort_order' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }

            for ($n = 0; $n < 40; $n++) {
                $male = $n % 3 !== 1;
                $last = self::LAST[mt_rand(0, 14)];
                $family = DB::table('families')->insertGetId(['city_id' => $c, 'family_number' => sprintf('%s-%05d', $city->code, 7000 + $n), 'created_at' => $now, 'updated_at' => $now]);
                $person = DB::table('people')->insertGetId([
                    'city_id' => $c, 'first_name' => ($male ? self::FIRST_M : self::FIRST_F)[mt_rand(0, 14)], 'last_name' => $last,
                    'father_name' => self::FIRST_M[mt_rand(0, 14)], 'mother_name' => self::FIRST_F[mt_rand(0, 14)],
                    'birth_date' => Carbon::create(mt_rand(1965, 2000), mt_rand(1, 12), mt_rand(1, 28)), 'sex' => $male ? 'male' : 'female',
                    'national_number' => sprintf('0%010d', mt_rand(100000000, 999999999)), 'mobile' => sprintf('09%08d', mt_rand(10000000, 99999999)),
                    'street_id' => $streets[mt_rand(0, count($streets) - 1)], 'home_address' => 'عنوان تجريبي '.($n + 1),
                    'marital_status_id' => $marital[mt_rand(0, count($marital) - 1)], 'household_size' => mt_rand(1, 7), 'family_size' => mt_rand(2, 8),
                    'is_project_owner' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('family_members')->insert(['city_id' => $c, 'family_id' => $family, 'person_id' => $person, 'relation_id' => $relations[0] ?? null, 'has_work' => true, 'created_at' => $now, 'updated_at' => $now]);
                for ($k = 0; $k < mt_rand(1, 3); $k++) {
                    $member = DB::table('people')->insertGetId(['city_id' => $c, 'first_name' => self::FIRST_F[mt_rand(0, 14)], 'last_name' => $last, 'sex' => 'female', 'birth_date' => Carbon::create(mt_rand(1990, 2018), mt_rand(1, 12), 3), 'created_at' => $now, 'updated_at' => $now]);
                    DB::table('family_members')->insert(['city_id' => $c, 'family_id' => $family, 'person_id' => $member, 'relation_id' => $relations[mt_rand(1, min(5, count($relations) - 1))] ?? null, 'provider_person_id' => $person, 'created_at' => $now, 'updated_at' => $now]);
                }

                // Projects 1001.. in every city: the same number exists in all five cities on purpose.
                $number = 1001 + $n;
                $career = $careers[mt_rand(0, count($careers) - 1)];
                $type = $types[mt_rand(0, count($types) - 1)];
                $requested = Carbon::create(2024, 1, 1)->addDays(mt_rand(0, 650));
                $stage = $n % 4 === 0 ? 'preliminary' : 'full';
                $donor = array_rand($donors);
                $project = DB::table('projects')->insertGetId([
                    'city_id' => $c, 'project_number' => $number, 'application_stage' => $stage, 'request_date' => $requested,
                    'name' => self::PROJECTS[mt_rand(0, 9)], 'requested_amount' => mt_rand(20, 300) * 100000, 'fund_type_id' => $fundTypes[mt_rand(0, count($fundTypes) - 1)],
                    'project_type_id' => $type->project_type_id, 'project_subtype_id' => $type->id, 'career_id' => $career->id,
                    'project_subcategory_id' => $career->project_subcategory_id, 'project_category_id' => $subcats[$career->project_subcategory_id] ?? null,
                    'street_id' => $streets[mt_rand(0, count($streets) - 1)], 'place_address' => 'مكان المشروع التجريبي '.($n + 1),
                    'donor_id' => $stage === 'full' ? $donor : null, 'donor_group_id' => $stage === 'full' ? $donors[$donor][0] : null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('project_applicants')->insert(['city_id' => $c, 'project_id' => $project, 'person_id' => $person, 'role' => 'مستفيد', 'has_work' => true, 'goal' => 'هدف تجريبي: تأمين دخل ثابت للعائلة', 'created_at' => $now, 'updated_at' => $now]);

                // State path: some rejected, some under study, most financed; a few finished.
                $path = match (true) {
                    $stage === 'preliminary' => ['On Hold'],
                    $n % 9 === 1 => ['Under Review', 'Rejected'],
                    $n % 7 === 0 => ['Under Review', 'Accepted', 'Financed', 'Finished'],
                    $n % 5 === 2 => ['Under Review'],
                    default => ['Under Review', 'Accepted', 'Financed'],
                };
                $path = array_values(array_filter($path, fn ($s) => isset($states[$s])));
                foreach ($path as $i => $s) {
                    DB::table('project_state_history')->insert(['city_id' => $c, 'project_id' => $project, 'project_state_id' => $states[$s], 'changed_on' => $requested->copy()->addDays(20 * ($i + 1)), 'is_current' => $i === count($path) - 1, 'created_at' => $now, 'updated_at' => $now]);
                }

                if (in_array('Financed', $path, true)) {
                    $amount = mt_rand(20, 250) * 100000;
                    $count = 12;
                    $taken = $requested->copy()->addDays(45);
                    $loan = DB::table('loans')->insertGetId(['city_id' => $c, 'project_id' => $project, 'currency' => 'SYP', 'amount' => $amount, 'taken_on' => $taken, 'installments_count' => $count, 'installment_amount' => round($amount / $count, 2), 'receipt_number' => 'R-'.$number, 'created_at' => $now, 'updated_at' => $now]);
                    $paidUntil = in_array('Finished', $path, true) ? $count : mt_rand(0, $count);
                    for ($i = 1; $i <= $count; $i++) {
                        $due = $taken->copy()->addMonths($i);
                        DB::table('loan_installments')->insert(['city_id' => $c, 'loan_id' => $loan, 'amount' => round($amount / $count, 2), 'due_on' => $due, 'is_paid' => $i <= $paidUntil, 'received_on' => $i <= $paidUntil ? $due->copy()->addDays(mt_rand(0, 5)) : null, 'created_at' => $now, 'updated_at' => $now]);
                    }
                    $visit = DB::table('visits')->insertGetId(['city_id' => $c, 'project_id' => $project, 'kind' => 'opening', 'type' => 'other', 'visit_date' => $taken->copy()->addDays(10), 'created_at' => $now, 'updated_at' => $now]);
                    for ($q = 1; $q <= 3; $q++) {
                        DB::table('visit_answers')->insert(['city_id' => $c, 'visit_id' => $visit, 'form' => 'visit', 'question_number' => $q, 'answer_text' => 'إجابة تجريبية', 'answer_value' => mt_rand(1, 3), 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }
            DB::table('project_number_sequences')->where('city_id', $c)->update(['next_number' => DB::table('projects')->where('city_id', $c)->max('project_number') + 1]);
            $this->command?->info(sprintf('%s: synthetic people, families and 40 projects (1001–1040)', $city->name_en));
        }
    }

    private function name(bool $male): string
    {
        return ($male ? self::FIRST_M : self::FIRST_F)[mt_rand(0, 14)].' '.self::LAST[mt_rand(0, 14)];
    }
}
