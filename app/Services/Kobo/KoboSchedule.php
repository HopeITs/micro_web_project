<?php

declare(strict_types=1);

namespace App\Services\Kobo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The visit schedule rules of the Kobo forms (desktop `KoboProductionSchedule` and `KoboVisitCompletion`) on `web_notices`.
 *
 * Agriculture and livestock monitoring forms carry no visit number: the stage comes from the previous visit of the same person and
 * the answer "was production started/achieved": M1 → (started ? final : M2), M2 → final, and the next reminder falls six months later.
 * A reminder retired by this rule is flagged `retired_by_kobo` so a person's own "seen" is never confused with it. A visit settles the
 * reminder of its stage on the occurrence nearest to the visit date (the latest on or before it, else the first after it).
 */
final class KoboSchedule
{
    public const PRODUCTION = ['agriculture', 'livestock'];

    public static function production(?string $category): bool
    {
        return in_array($category, self::PRODUCTION, true);
    }

    /** "production started?" answer of a production form: yes → true, no → false, otherwise null. */
    public static function started(array $raw, string $category): ?bool
    {
        $field = $category === 'agriculture' ? 'production_data/production_achieved' : 'production_data/production_started';
        $value = $raw[$field] ?? null;
        $value = is_string($value) ? mb_strtolower(trim($value)) : '';

        return match ($value) {
            'yes', 'نعم' => true,
            'no', 'لا' => false,
            default => null,
        };
    }

    /** The stage after a visit: 0 means none can be derived. */
    public static function next(int $stage, ?bool $started): int
    {
        return match (true) {
            $stage === 4 => 0,
            $stage === 2 => 4,
            $stage === 1 => $started === null ? 0 : ($started ? 4 : 2),
            default => 0,
        };
    }

    /** A project of a production type (agriculture / livestock), by its type name. */
    public static function productionType(string $typeName): ?string
    {
        $lower = mb_strtolower($typeName);
        if (str_contains($typeName, 'زراع') || str_contains($lower, 'agricultur')) {
            return 'agriculture';
        }
        if (str_contains($typeName, 'حيوان') || str_contains($typeName, 'مواشي') || str_contains($lower, 'livestock')) {
            return 'livestock';
        }

        return null;
    }

    /** The final form of a general category takes the project's own production category (desktop `FinalCategory`). */
    public static function finalCategory(string $category, string $visitType, string $projectType): string
    {
        return $visitType === 'final' && $category === 'general' ? (self::productionType($projectType) ?? $category) : $category;
    }

    public static function dueAfter(string $date): string
    {
        return CarbonImmutable::parse($date)->addMonths(6)->toDateString();
    }

    /**
     * The occurrence a visit settles: the latest date on or before the visit, else the first after it.
     *
     * @param  list<string>  $dates
     */
    public static function occurrence(array $dates, string $actual): ?string
    {
        $previous = $next = null;
        foreach ($dates as $date) {
            if ($date <= $actual && ($previous === null || $date > $previous)) {
                $previous = $date;
            }
            if ($date > $actual && ($next === null || $date < $next)) {
                $next = $date;
            }
        }

        return $previous ?? $next;
    }

    /** Marks seen (1) the reminders of these bodies on the occurrence nearest the date; returns how many changed. */
    public function complete(object $project, array $bodies, string $date): int
    {
        $changed = 0;
        foreach ($bodies as $body) {
            $rows = DB::table('web_notices')->where('city_id', $project->city_id)->where('project_id', $project->id)->whereNull('account_id')
                ->whereIn('kind', ['visit', 'clearance'])->where('body', $body)->get(['id', 'notice_date', 'seen']);
            $chosen = self::occurrence($rows->map(fn ($r) => substr((string) $r->notice_date, 0, 10))->all(), $date);
            if ($chosen === null) {
                continue;
            }
            $changed += DB::table('web_notices')->whereIn('id', $rows->filter(fn ($r) => substr((string) $r->notice_date, 0, 10) === $chosen && ! $r->seen)->pluck('id')->all() ?: [0])->update(['seen' => true]);
        }

        return $changed;
    }

    /** Retires the project's pending reminders matching the bodies (and optionally not matching a kept body and date). */
    private function retire(object $project, array $bodies, ?string $keepBody = null, ?string $keepDate = null): void
    {
        $query = DB::table('web_notices')->where('city_id', $project->city_id)->where('project_id', $project->id)->whereNull('account_id')->where('kind', 'visit')
            ->where('seen', false)->whereIn('body', $bodies);
        if ($keepBody !== null) {
            $query->whereNot(fn ($q) => $q->where('body', $keepBody)->where('notice_date', $keepDate));
        }
        $query->update(['seen' => true, 'retired_by_kobo' => true]);
    }

    private function ensure(object $project, string $body, string $due, string $caption, ?int $sender): void
    {
        $existing = DB::table('web_notices')->where('city_id', $project->city_id)->where('project_id', $project->id)->whereNull('account_id')->where('kind', 'visit')
            ->where('body', $body)->where('notice_date', $due);
        if ((clone $existing)->exists()) {
            (clone $existing)->where('retired_by_kobo', true)->update(['seen' => false, 'retired_by_kobo' => false]);

            return;
        }
        DB::table('web_notices')->insert(['city_id' => $project->city_id, 'account_id' => null, 'sender_account_id' => $sender, 'project_id' => $project->id, 'project_number' => $project->project_number,
            'kind' => 'visit', 'notice_date' => $due, 'title' => mb_substr($caption, 0, 255), 'body' => $body, 'seen' => false, 'retired_by_kobo' => false, 'created_at' => now()]);
    }

    private const ADVANCE = ['Visit M2-O', 'Visit M3-O', 'Visit M4-O', 'Visit M5-O', 'Visit MFinal-O'];

    /** The opening of a production project plans its first monitoring visit six months after the opening (unless a monitoring visit exists). */
    public function opening(object $project, string $date, ?int $sender): void
    {
        $monitored = DB::table('kobo_monitoring_records as r')->join('kobo_submissions as s', 's.id', '=', 'r.kobo_submission_id')
            ->where('r.project_id', $project->id)->whereNull('r.deleted_at')->whereNull('s.deleted_at')->whereIn('s.sync_status', ['local_inserted', 'local_updated'])->exists();
        if ($monitored) {
            return;
        }
        $notices = DB::table('web_notices')->where('city_id', $project->city_id)->where('project_id', $project->id)->whereNull('account_id')->where('kind', 'visit')
            ->where('body', 'Visit Opening-O')->orderBy('notice_date')->get(['notice_date', 'title']);
        $due = self::dueAfter(self::occurrence($notices->map(fn ($n) => substr((string) $n->notice_date, 0, 10))->all(), $date) ?? $date);
        $this->retire($project, [...self::ADVANCE, 'Visit M1-O'], 'Visit M1-O', $due);
        $this->ensure($project, 'Visit M1-O', $due, (string) ($notices->first()->title ?? ''), $sender);
    }

    /** After a production visit: only the person's latest visit steers the next reminder. */
    public function after(object $project, int $personId, int $stage, string $date, string $category, array $raw, string $caption, ?int $sender, int $submissionId): void
    {
        $later = DB::table('kobo_monitoring_records as r')->join('kobo_submissions as s', 's.id', '=', 'r.kobo_submission_id')->where('r.project_id', $project->id)->where('r.person_id', $personId)
            ->where('r.visit_date', '>', $date)->where('s.id', '<>', $submissionId)->whereNull('r.deleted_at')->whereNull('s.deleted_at')->where('s.sync_status', '<>', 'review_replaced')->exists();
        if ($later) {
            return;
        }
        $next = self::next($stage, self::started($raw, $category));
        $body = $next === 0 ? '' : ($next === 4 ? 'Visit MFinal-O' : 'Visit M2-O');
        $due = self::dueAfter($date);
        $this->retire($project, self::ADVANCE, $body === '' ? null : $body, $body === '' ? null : $due);
        if ($next !== 0) {
            $this->ensure($project, $body, $due, $caption, $sender);
        }
    }
}
