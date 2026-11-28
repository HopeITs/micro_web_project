<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * X4 (28 Nov 2026): partnership projects in the invented data. In the real data 86 projects have 2 or 3 people (118 partners), so the
 * number of beneficiaries is higher than the number of projects; the synthetic data had none. Every full project whose number ends the
 * week (number % 7 === 3) gets one partner with a household of their own.
 *
 * Synthetic data only: it refuses to run in production and on a database holding transferred rows (projects with a legacy id). It is
 * safe to run again: a project that already has a partner is skipped. It draws no random numbers, so the rest of the invented data stays
 * as it was. Online staging: `php artisan db:seed --class=SyntheticPartnersSeeder --force`.
 */
final class SyntheticPartnersSeeder extends Seeder
{
    public const ROLE = 'شريك';

    private const FIRST_M = ['جورج', 'إلياس', 'فادي', 'رامي', 'سامر', 'طوني', 'ميشيل', 'نبيل', 'يوسف', 'شادي', 'باسل', 'وسيم', 'جوزيف', 'حنا', 'عماد'];

    private const FIRST_F = ['ريما', 'ميرنا', 'رولا', 'منى', 'هالة', 'سوزان', 'لينا', 'ديما', 'رنا', 'ماري', 'نادين', 'جولي', 'رشا', 'سمر', 'لارا'];

    private const LAST = ['حداد', 'خوري', 'نصار', 'عيسى', 'شاهين', 'زيدان', 'منصور', 'جبور', 'سعادة', 'قسيس', 'بشارة', 'عطية', 'سلوم', 'صليبا', 'دياب'];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('SyntheticPartnersSeeder invents people: it never runs in production.');
        }
        if (DB::table('projects')->whereNotNull('legacy_id')->exists()) {
            throw new RuntimeException('This database holds transferred projects: synthetic partners are not added to real data.');
        }

        $now = now();
        $relation = DB::table('relations')->orderBy('id')->value('id');
        $withPartner = DB::table('project_applicants')->where('role', self::ROLE)->pluck('project_id')->flip();
        $added = 0;
        $projects = DB::table('projects')->whereNull('deleted_at')->where('application_stage', 'full')
            ->orderBy('city_id')->orderBy('project_number')->get(['id', 'city_id', 'project_number']);
        foreach ($projects as $i => $project) {
            if ($project->project_number % 7 !== 3 || isset($withPartner[$project->id])) {
                continue;
            }
            DB::transaction(function () use ($project, $i, $now, $relation) {
                $last = self::LAST[($i + 4) % 15];
                $family = DB::table('families')->insertGetId(['city_id' => $project->city_id, 'family_number' => sprintf('P-%d-%04d', $project->city_id, $project->project_number), 'created_at' => $now, 'updated_at' => $now]);
                $partner = DB::table('people')->insertGetId([
                    'city_id' => $project->city_id, 'first_name' => self::FIRST_M[$i % 15], 'last_name' => $last, 'father_name' => self::FIRST_M[($i + 2) % 15],
                    'sex' => 'male', 'birth_date' => Carbon::create(1975 + $i % 20, 1 + $i % 12, 10), 'is_project_owner' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('family_members')->insert(['city_id' => $project->city_id, 'family_id' => $family, 'person_id' => $partner, 'relation_id' => $relation, 'has_work' => true, 'created_at' => $now, 'updated_at' => $now]);
                foreach (range(1, 1 + $i % 3) as $k) {
                    $member = DB::table('people')->insertGetId(['city_id' => $project->city_id, 'first_name' => self::FIRST_F[($i + $k) % 15], 'last_name' => $last, 'sex' => 'female',
                        'birth_date' => Carbon::create(2000 + $k, 5, 3), 'created_at' => $now, 'updated_at' => $now]);
                    DB::table('family_members')->insert(['city_id' => $project->city_id, 'family_id' => $family, 'person_id' => $member, 'provider_person_id' => $partner, 'created_at' => $now, 'updated_at' => $now]);
                }
                DB::table('project_applicants')->insert(['city_id' => $project->city_id, 'project_id' => $project->id, 'person_id' => $partner, 'role' => self::ROLE, 'has_work' => true, 'created_at' => $now, 'updated_at' => $now]);
            });
            $added++;
        }
        $this->command?->info("Synthetic partners added: {$added}");
    }
}
