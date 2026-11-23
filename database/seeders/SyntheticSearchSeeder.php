<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Invented detail rows (M4.17, seeded by the search test and `php artisan db:seed --class=SyntheticSearchSeeder`) so every one of the 22 search types has something to show on the synthetic data: per city, for the first three
 * financed projects, their materials and power source, the owner's assets, losses, courses, work, education, aid and illness rows, a checklist
 * with opinions, attachments of the three categories, M&E visits with visitors and two profession meetings. Runs after the role accounts; does
 * nothing when checklists already exist. Every text here is made up.
 */
final class SyntheticSearchSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Synthetic search data is never seeded in production.');
        }
        if (DB::table('checklists')->exists()) {
            return;
        }
        $now = now();
        $stamp = ['created_at' => $now, 'updated_at' => $now];
        $first = fn (string $table) => DB::table($table)->orderBy('id')->value('id');
        $accounts = DB::table('web_accounts')->whereIn('username', ['role.monitoring', 'role.data-entry'])->orderBy('username')->pluck('id')->all();
        $types = DB::table('attachment_types')->whereIn('name_en', ['Profile', 'Reciept', 'Rent'])->pluck('id')->all();
        if ($types === []) {
            $types = DB::table('attachment_types')->orderBy('id')->limit(3)->pluck('id')->all();
        }

        foreach (DB::table('cities')->orderBy('id')->pluck('id') as $city) {
            $projects = DB::table('loans as l')->join('projects as p', 'p.id', '=', 'l.project_id')->where('p.city_id', $city)->orderBy('p.project_number')->limit(3)->get(['p.id', 'p.career_id', 'p.project_subcategory_id']);
            $members = DB::table('checklist_members')->where('city_id', $city)->orderBy('id')->pluck('id')->all();
            foreach ($projects as $n => $project) {
                $owner = DB::table('project_applicants')->where('project_id', $project->id)->orderBy('id')->value('person_id');
                if ($owner === null) {
                    continue;
                }
                $row = ['city_id' => $city] + $stamp;
                DB::table('project_materials')->insert([['project_id' => $project->id, 'name' => 'آلة خياطة', 'quantity' => 2, 'price' => 250000, 'local_contribution' => 0, 'comments' => 'جديدة'] + $row,
                    ['project_id' => $project->id, 'name' => 'قماش', 'quantity' => 30, 'price' => 15000, 'local_contribution' => 50000, 'comments' => 'مواد أولية'] + $row]);
                DB::table('project_power_sources')->insert(['project_id' => $project->id, 'power_source_id' => $first('power_sources'), 'note' => 'مولدة صغيرة', 'cost' => 120000, 'responsibility' => 'المستفيد'] + $row);
                DB::table('person_power_sources')->insert(['person_id' => $owner, 'power_source_id' => $first('power_sources'), 'note' => 'ألواح شمسية', 'cost' => 30000, 'responsibility' => 'المستفيد'] + $row);
                DB::table('person_aid_received')->insert(['person_id' => $owner, 'aid_source_id' => $first('aid_sources'), 'aid_type_id' => $first('aid_types'), 'aid_period_id' => $first('aid_periods'), 'notes' => 'سلة غذائية'] + $row);
                DB::table('person_aid_sources')->insert(['person_id' => $owner, 'project_id' => $project->id, 'aid_source_id' => $first('aid_sources'), 'year_text' => '2023', 'amount_text' => '500000', 'project_type_text' => 'مشروع صغير', 'notes' => 'أدوات'] + $row);
                DB::table('person_assets')->insert(['person_id' => $owner, 'asset_kind_id' => $first('asset_kinds'), 'asset_type_id' => $first('asset_types'), 'asset_benefit_id' => $first('asset_benefits'), 'year_text' => '2019', 'description' => 'سيارة صغيرة', 'note' => 'ملك'] + $row);
                DB::table('person_courses')->insert(['person_id' => $owner, 'name' => 'دورة خياطة', 'place' => 'معهد المدينة', 'donor_text' => 'جهة تجريبية', 'date_text' => '2022', 'project_subcategory_id' => $project->project_subcategory_id] + $row);
                DB::table('person_goals')->insert(['person_id' => $owner, 'goal_id' => $first('goals')] + $row);
                DB::table('person_illnesses')->insert(['person_id' => $owner, 'illness_id' => $first('illnesses'), 'has_medical_aid' => true, 'aid_source_id' => $first('aid_sources'), 'aid_period_id' => $first('aid_periods'), 'medical_need_id' => $first('medical_needs'), 'note' => 'حالة مزمنة'] + $row);
                DB::table('person_losses')->insert(['person_id' => $owner, 'loss_type_id' => $first('loss_types'), 'year_text' => '2016', 'note' => 'خسارة منزل'] + $row);
                DB::table('person_work_experiences')->insert([
                    ['person_id' => $owner, 'occupation_id' => $first('occupations'), 'work_type_id' => $first('work_types'), 'description' => 'عمل حالي', 'begin_text' => '2021', 'place' => 'السوق', 'place_state' => 'ملك', 'contact' => 'ذاتي', 'status' => 'yes', 'salary' => 400000, 'end_text' => null, 'leaving_reason' => null] + $row,
                    ['person_id' => $owner, 'occupation_id' => $first('occupations'), 'work_type_id' => $first('work_types'), 'description' => 'عمل سابق', 'begin_text' => '2015', 'end_text' => '2019', 'place' => 'الورشة', 'place_state' => 'أجار', 'contact' => 'رب عمل', 'status' => 'no', 'leaving_reason' => 'إغلاق الورشة', 'salary' => null] + $row,
                ]);
                DB::table('person_educations')->insert(['person_id' => $owner, 'education_level_id' => $first('education_levels'), 'education_specialty_id' => $first('education_specialties')] + $row);

                $checklist = DB::table('checklists')->insertGetId(['person_id' => $owner, 'project_id' => $project->id, 'has_home_visit' => true, 'home_visit_date' => '2025-02-10', 'home_visit_time' => '10:00', 'home_visitor_1_id' => $members[0] ?? null,
                    'home_visitor_2_id' => $members[1] ?? null, 'home_visit_note' => 'زيارة تجريبية', 'has_project_visit' => true, 'project_visit_date' => '2025-02-12', 'project_visitor_1_id' => $members[2] ?? null,
                    'needs_professional_test' => $n === 0, 'professional_test_need_note' => $n === 0 ? 'يحتاج اختباراً' : null, 'has_professional_training' => false, 'has_management_training' => true, 'management_trainer' => 'مدرب تجريبي',
                    'management_training_date' => '2025-03-01', 'notes' => 'قائمة تدقيق تجريبية', 'team_date' => '2025-03-05', 'committee_date' => '2025-03-10', 'rejection_message' => $n === 1 ? 1 : 0] + $row);
                $opinion = DB::table('checklist_opinions')->orderBy('id')->value('id');
                foreach (['k', 't', 'c'] as $i => $location) {
                    DB::table('checklist_member_opinions')->insert(['person_id' => $owner, 'checklist_member_id' => $members[$i] ?? $members[0], 'checklist_opinion_id' => $location === 'k' ? null : $opinion,
                        'notes' => 'رأي تجريبي', 'name_notes' => $location === 'k' ? 'شخصية محلية' : null, 'role_notes' => $location === 'k' ? 'مختار' : null, 'location' => $location, 'recorded_at' => $now] + $row);
                }

                foreach ($types as $type) {
                    DB::table('attachments')->insert(['project_id' => $project->id, 'person_id' => $owner, 'attachment_type_id' => $type, 'original_name' => 'synthetic-'.$project->id.'-'.$type.'.pdf',
                        'stored_path' => 'synthetic/'.$project->id.'/'.$type.'.pdf', 'extension' => 'pdf', 'legacy_source' => 'image', 'uploaded_at' => $now] + $row);
                }

                if ($n === 0) {
                    foreach ([1 => 55, 2 => 90] as $number => $result) {
                        $visit = DB::table('me_visits')->insertGetId(['person_id' => $owner, 'project_id' => $project->id, 'visit_number' => (string) $number, 'kind' => 'o', 'type' => null,
                            'visit_date' => '2025-0'.(3 + $number).'-15', 'profit' => 100000 * $number, 'result' => $result, 'created_by_name' => 'seed'] + $row);
                        foreach (array_slice($accounts, 0, 2) as $i => $account) {
                            DB::table('me_visit_visitors')->insert(['me_visit_id' => $visit, 'account_id' => $account, 'mark' => ['جيد', 'وسط'][$i]] + $row);
                        }
                    }
                }
            }
            $career = DB::table('careers')->whereNotNull('project_subcategory_id')->orderBy('id')->first(['id', 'project_subcategory_id']);
            foreach ([['2025-04-10', 'قاعة الكنيسة', 12, 9, 3], ['2025-06-02', 'مركز المدينة', 20, 15, 5]] as [$date, $place, $invited, $came, $absent]) {
                DB::table('profession_meetings')->insert(['career_id' => $career?->id, 'project_subcategory_id' => $career?->project_subcategory_id, 'meeting_date' => $date, 'place' => $place,
                    'invited_count' => $invited, 'attendance_count' => $came, 'absence_count' => $absent, 'record_state' => 'final'] + ['city_id' => $city] + $stamp);
            }
        }
        $this->command?->info('synthetic search detail rows added');
    }
}
