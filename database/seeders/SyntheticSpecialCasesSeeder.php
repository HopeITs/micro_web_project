<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Invented special cases (M4.13) for the synthetic projects, recorded by the test role accounts: per city, phone calls and interviews on
 * financed projects (one dated before its loan, as 11 real cases are), and an objection on a rejected project. Runs after the role
 * accounts; does nothing when special cases already exist. Every text here is made up.
 */
final class SyntheticSpecialCasesSeeder extends Seeder
{
    private const REASONS = [
        ['تأجيل أقساط', 'طلب المستفيد تأجيل قسطين بسبب مرض أحد أفراد العائلة.', 'تمت الموافقة على تأجيل قسط واحد ومتابعة الوضع الشهر القادم.'],
        ['تخفيض قسط', 'انخفض دخل المشروع بعد ارتفاع أسعار المواد.', null],
        ['تغيير عنوان المشروع', 'انتقل المشروع إلى محل جديد في نفس الحي.', 'تم تحديث العنوان وإبلاغ فريق الزيارات.'],
        ['سفر', 'أبلغ المستفيد بسفره لمدة شهر وتكليف أخيه بإدارة المشروع.', 'تم تسجيل الملاحظة.'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Synthetic special cases are never seeded in production.');
        }
        if (DB::table('special_cases')->exists()) {
            return;
        }
        $types = DB::table('special_case_types')->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
        $recorders = DB::table('web_accounts')->whereIn('username', ['role.data-entry', 'role.administrator'])->orderBy('username', 'desc')->pluck('id')->all();
        if ($types === [] || $recorders === []) {
            $this->command?->warn('no special case types or role accounts; synthetic special cases not added');

            return;
        }
        $rejected = DB::table('project_states')->where('name_en', 'Rejected')->value('id');
        $now = now();
        $added = 0;
        foreach (DB::table('cities')->orderBy('id')->pluck('id') as $city) {
            $funded = DB::table('loans as l')->join('projects as p', 'p.id', '=', 'l.project_id')->where('p.city_id', $city)->whereNull('l.deleted_at')
                ->orderBy('p.project_number')->limit(3)->get(['p.id', 'l.taken_on']);
            foreach ($funded as $i => $project) {
                $taken = Carbon::parse($project->taken_on);
                // The first case of the first project predates its loan; the others follow it.
                $dates = $i === 0 ? [$taken->copy()->subDays(5), $taken->copy()->addMonths(2)] : [$taken->copy()->addMonths(1 + $i)];
                foreach ($dates as $j => $date) {
                    [$reason, $description, $action] = self::REASONS[($i + $j) % count(self::REASONS)];
                    DB::table('special_cases')->insert(['city_id' => $city, 'project_id' => $project->id, 'account_id' => $recorders[($i + $j) % count($recorders)],
                        'special_case_type_id' => $types[($i + $j) % count($types)], 'case_date' => min($date, now())->toDateString(), 'reason' => $reason,
                        'description' => $description, 'action' => $action, 'created_at' => $now, 'updated_at' => $now]);
                    $added++;
                }
            }
            $objection = $rejected === null ? null : DB::table('project_state_history')->where('city_id', $city)->where('project_state_id', $rejected)->where('is_current', true)->orderBy('project_id')->value('project_id');
            if ($objection !== null) {
                DB::table('special_cases')->insert(['city_id' => $city, 'project_id' => $objection, 'account_id' => $recorders[0], 'special_case_type_id' => $types[count($types) - 1],
                    'case_date' => '2025-09-15', 'reason' => 'اعتراض على رفض الطلب', 'description' => 'راجع المستفيد المكتب للاعتراض على قرار الرفض وقدّم أوراقاً إضافية.',
                    'action' => 'أُحيل الطلب إلى اللجنة لإعادة الدراسة.', 'created_at' => $now, 'updated_at' => $now]);
                $added++;
            }
        }
        $this->command?->info(sprintf('synthetic special cases: %d', $added));
    }
}
