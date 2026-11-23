<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Kobo\KoboCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Invented Kobo data (M4.14) so the read-only Kobo page can be seen and tested locally: per city, two catalogue forms (their uids and
 * questions come from resources/kobo/question-catalog.json, which holds no personal data), monitoring submissions with made-up answers on
 * the synthetic projects, one import error, one submission deleted in Kobo, one replaced after review, visitor names (left unlinked, so
 * the visitor statistics of the web visits are unchanged) and mappings, a
 * pending and a resolved sync conflict and two beneficiary reviews. Never runs in production; does nothing when Kobo data already exists.
 */
final class SyntheticKoboSeeder extends Seeder
{
    private const REGIONS = ['ALP' => 'aleppo', 'DAM' => 'damascus', 'HMS' => 'homs', 'LAT' => 'latakia', 'WAD' => 'wadi'];

    private const VISITORS = ['سامر حداد', 'ريم خوري', 'جورج نصار', 'لينا عبود'];

    private const TEXTS = ['المشروع يعمل بشكل جيد', 'ارتفاع أسعار المواد الأولية', 'زيادة عدد الزبائن', 'لا يوجد', 'تحسن ملحوظ في الدخل'];

    private int $seq = 0;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Synthetic Kobo data is never seeded in production.');
        }
        if (DB::table('kobo_submissions')->exists() || DB::table('kobo_forms')->exists()) {
            return;
        }
        DB::transaction(fn () => $this->seed());
    }

    private function seed(): void
    {
        mt_srand(1414);
        $accounts = DB::table('web_accounts')->whereIn('username', ['role.monitoring', 'role.administrator', 'role.data-entry'])->orderBy('username')->pluck('id')->all();
        $added = 0;
        foreach (DB::table('cities')->orderBy('id')->get(['id', 'code']) as $city) {
            $region = self::REGIONS[$city->code] ?? null;
            $forms = $region === null ? [] : $this->forms($region);
            $projects = DB::table('project_applicants as a')->join('projects as p', 'p.id', '=', 'a.project_id')->where('p.city_id', $city->id)
                ->whereNull('a.deleted_at')->whereNull('p.deleted_at')->orderBy('p.project_number')->limit(8)->get(['p.id', 'p.project_number', 'a.person_id', 'a.role']);
            if ($forms === [] || $projects->isEmpty()) {
                continue;
            }
            $formIds = [];
            foreach ($forms as $i => [$uid, $type]) {
                $formIds[$uid] = DB::table('kobo_forms')->insertGetId(['city_id' => $city->id, 'form_uid' => $uid, 'form_name' => $type.' - '.($i + 1).' - '.$city->code,
                    'governorate' => $region, 'visit_type' => $type, 'visit_category' => 'general', 'target_table' => 'me_visits', 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now()]);
            }
            $ids = [];
            foreach ($projects as $n => $project) {
                [$uid, $type] = $forms[$n % count($forms)];
                $visits = $type === 'monitoring' ? 1 + $n % 3 : 1;
                for ($v = 1; $v <= $visits; $v++) {
                    $status = match (true) {
                        $n === 1 && $v === 1 => 'local_error', $n === 2 => 'deleted', default => 'local_inserted'
                    };
                    $date = Carbon::create(2026, 1, 10)->addDays($n * 17 + $v * 45);
                    $id = $this->submission($city, $uid, $type, $project, $v, $date, $status);
                    $ids[] = [$id, $project, $uid];
                    if ($status !== 'local_error') {
                        DB::table('kobo_monitoring_records')->insert(['city_id' => $city->id, 'kobo_submission_id' => $id, 'project_id' => $project->id, 'person_id' => $project->person_id,
                            'person_role' => $project->role ?: 'مستفيد', 'visit_number' => $type === 'monitoring' ? $v : null, 'visit_kind' => 'o', 'visit_date' => $date->toDateString(),
                            'created_at' => now(), 'updated_at' => now()]);
                    }
                    foreach ([self::VISITORS[($n + $v) % 4], self::VISITORS[($n + $v + 1) % 4]] as $order => $name) {
                        DB::table('kobo_visit_visitors')->insert(['city_id' => $city->id, 'kobo_submission_id' => $id, 'visitor_order' => $order + 1, 'kobo_name' => 'user'.(($n + $v + $order) % 4 + 1),
                            'display_name' => $name, 'account_id' => null, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $added++;
                }
            }
            foreach (range(1, 4) as $u) {
                DB::table('kobo_user_mappings')->insert(['city_id' => $city->id, 'form_uid' => $u === 4 ? $forms[0][0] : '', 'kobo_name' => 'user'.$u, 'display_name' => self::VISITORS[$u - 1],
                    'account_id' => $u <= count($accounts) ? $accounts[$u - 1] : null, 'is_ignored' => $u === 4, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->reviews($city, $ids, $projects->first());
        }
        $this->command?->info("synthetic Kobo submissions added: {$added}");
    }

    /** @return list<array{0: string, 1: string}> two forms of the region: one with a visit number (monitoring), one without (opening). */
    private function forms(string $region): array
    {
        $monitoring = $opening = null;
        foreach (KoboCatalog::forms() as $uid => $form) {
            if ($form['region'] !== $region) {
                continue;
            }
            $staged = collect($form['fields'])->contains(fn ($f) => preg_match('/^رقم الزيار/u', KoboCatalog::normalize((string) $f['label'])));
            if ($staged) {
                $monitoring ??= $uid;
            } else {
                $opening ??= $uid;
            }
        }

        return array_values(array_filter([$monitoring ? [$monitoring, 'monitoring'] : null, $opening ? [$opening, 'opening'] : null]));
    }

    /** Made-up answers for every question of the form, keyed by field path. */
    private function answers(string $uid, object $project, int $visit): array
    {
        $raw = ['_id' => 900000 + ++$this->seq, 'formhub/uuid' => 'synthetic', '__version__' => 'v1'];
        foreach (KoboCatalog::fields($uid) as $path => $field) {
            $label = KoboCatalog::normalize((string) $field['label']);
            $kind = KoboCatalog::kind($field);
            $raw[$path] = match (true) {
                str_starts_with($label, 'رقم المشروع') => (string) $project->project_number,
                str_starts_with($label, 'رقم الزيار') && $field['choices'] !== [] => (string) $field['choices'][min($visit, count($field['choices'])) - 1]['code'],
                mt_rand(1, 6) === 1 => null,
                $kind === 'select_one' && $field['choices'] !== [] => (string) $field['choices'][mt_rand(0, count($field['choices']) - 1)]['code'],
                $kind === 'select_multiple' && $field['choices'] !== [] => implode(' ', array_unique([$field['choices'][mt_rand(0, count($field['choices']) - 1)]['code'], $field['choices'][mt_rand(0, count($field['choices']) - 1)]['code']])),
                $kind === 'integer' => (string) (mt_rand(1, 40) * 25000),
                $kind === 'text' => self::TEXTS[mt_rand(0, count(self::TEXTS) - 1)],
                default => null,
            };
        }

        return array_filter($raw, fn ($v) => $v !== null);
    }

    private function submission(object $city, string $uid, string $type, object $project, int $visit, Carbon $date, string $status, ?array $raw = null): int
    {
        $raw ??= $this->answers($uid, $project, $visit);
        $json = json_encode($raw, JSON_UNESCAPED_UNICODE);
        $form = DB::table('kobo_forms')->where('city_id', $city->id)->where('form_uid', $uid)->value('form_name');

        return DB::table('kobo_submissions')->insertGetId(['city_id' => $city->id, 'root_uuid' => 'synthetic-'.$city->code.'-'.$raw['_id'], 'current_uuid' => 'synthetic-'.$city->code.'-'.$raw['_id'],
            'kobo_submission_id' => (string) $raw['_id'], 'form_uid' => $uid, 'form_version' => 'v1', 'form_name' => $form, 'submitted_at' => $date->copy()->setTime(11, 30),
            'submitted_by' => 'user'.($visit % 4 + 1), 'kobo_status' => 'submitted_via_web', 'validation_status' => '{}', 'is_deleted_in_kobo' => $status === 'deleted',
            'deleted_in_kobo_at' => $status === 'deleted' ? $date->copy()->addDays(3) : null, 'sync_status' => $status, 'local_table' => null, 'local_record_id' => null,
            'raw_json' => $json, 'raw_json_hash' => hash('sha256', (string) $json),
            'error_message' => $status === 'local_error' ? 'تعذر تحديد المستفيد بشكل مؤكد. افتح مطابقة المستفيدين لاختيار الشخص المرتبط بالمشروع.' : null,
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** A pending and a resolved conflict (an incoming copy with two answers changed) and a pending and a confirmed beneficiary review. */
    private function reviews(object $city, array $ids, object $first): void
    {
        $kept = array_values(array_filter($ids, fn ($i) => DB::table('kobo_submissions')->where('id', $i[0])->value('sync_status') === 'local_inserted'));
        foreach (array_slice($kept, 0, 2) as $k => [$existing, $project, $uid]) {
            $raw = json_decode((string) DB::table('kobo_submissions')->where('id', $existing)->value('raw_json'), true);
            $changed = 0;
            foreach (KoboCatalog::fields($uid) as $path => $field) {
                if ($changed < 2 && KoboCatalog::kind($field) === 'select_one' && count($field['choices']) > 1 && isset($raw[$path]) && KoboCatalog::reported($field)) {
                    $raw[$path] = $raw[$path] === $field['choices'][0]['code'] ? $field['choices'][1]['code'] : $field['choices'][0]['code'];
                    $changed++;
                }
            }
            $raw['_id'] = 900000 + ++$this->seq;
            $resolved = $k === 1;
            $incoming = $this->submission($city, $uid, 'monitoring', $project, 1, Carbon::create(2026, 9, 1 + $k), $resolved ? 'review_replaced' : 'local_updated', $raw);
            $at = Carbon::create(2026, 9, 5 + $k, 10);
            $conflict = DB::table('kobo_sync_conflicts')->insertGetId(['city_id' => $city->id, 'incoming_submission_id' => $incoming, 'existing_submission_id' => $existing,
                'incoming_hash' => hash('sha256', 'i'.$incoming), 'existing_hash' => hash('sha256', 'e'.$existing), 'state' => $resolved ? 'resolved' : 'pending', 'revision' => 1,
                'decision' => $resolved ? 'replace' : null, 'reason' => $resolved ? 'صحّح الزائر الإجابات بعد مراجعة المستفيد.' : null, 'decided_by_name' => $resolved ? self::VISITORS[0] : null,
                'decided_at' => $resolved ? $at : null, 'applied_at' => $resolved ? $at : null, 'created_at' => now(), 'updated_at' => now()]);
            $events = $resolved ? [['created', null, null], ['decided', 'replace', 'صحّح الزائر الإجابات بعد مراجعة المستفيد.'], ['applied', 'replace', null]] : [['created', null, null]];
            foreach ($events as $e => [$event, $decision, $reason]) {
                DB::table('kobo_sync_conflict_audits')->insert(['city_id' => $city->id, 'kobo_sync_conflict_id' => $conflict, 'event' => $event, 'decision' => $decision, 'reason' => $reason,
                    'actor_name' => $event === 'created' ? 'sync' : self::VISITORS[0], 'recorded_at' => $at->copy()->addMinutes($e), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach (array_slice($kept, 2, 2) as $k => [$submission, $project]) {
            $confirmed = $k === 1;
            DB::table('kobo_person_reviews')->insert(['city_id' => $city->id, 'kobo_submission_id' => $submission, 'project_id' => $project->id, 'source_hash' => hash('sha256', 's'.$submission),
                'kobo_name' => $confirmed ? 'أبو سامي' : 'أم جورج', 'person_id' => $confirmed ? $project->person_id : null, 'relationship_hash' => null,
                'state' => $confirmed ? 'confirmed' : 'pending', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            if ($confirmed) {
                DB::table('kobo_person_review_audits')->insert(['city_id' => $city->id, 'kobo_submission_id' => $submission, 'project_id' => $project->id, 'person_id' => $project->person_id,
                    'decision' => 'confirmed', 'reason' => 'الاسم في Kobo هو لقب صاحب المشروع.', 'actor_name' => self::VISITORS[1], 'recorded_at' => Carbon::create(2026, 9, 10, 9),
                    'kobo_name' => 'أبو سامي', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
