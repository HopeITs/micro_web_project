<?php

declare(strict_types=1);

namespace App\Services\Field;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Finance\Fields;
use App\Services\Finance\FinanceService;
use App\Services\Finance\Money;
use App\Services\Lifecycle\LifecycleService;
use App\Services\ProjectNumberAllocator;
use App\Services\Staff\ProjectNotices;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the visit services share (M4.12) on the unified schema: the project and its header, the desktop's project kinds (rehabilitation
 * grant, vehicle), the city's visitor accounts (`visits.visitor`, owner decision 1), the visit date rule (owner decision 6), the visit tasks,
 * and which visits were written by the Kobo sync (shown read-only, owner decision 5).
 */
final class VisitSupport
{
    /** Desktop sub-categories 1 and 2 (Taxi, Other Vehicles) use the vehicle forms. */
    private const VEHICLE_SUBCATEGORIES = [1, 2];

    private const VEHICLE_NAMES = ['Taxi', 'Other Vehicles'];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit, private LifecycleService $lifecycle, private ProjectNotices $projectNotices) {}

    // ---------------------------------------------------------------- access

    /** The project, readable with applications.view (another city: 404) and the given capability in its city (else 403, audited). */
    public function project(WebAccount $account, int $projectId, string $capability): object
    {
        $project = $this->lifecycle->project($account, $projectId);
        $this->require($account, (int) $project->city_id, $capability);

        return $project;
    }

    public function can(WebAccount $account, int $city, string $capability): bool
    {
        return $this->access->check($account, $city, $capability);
    }

    /** Refuses (and records the refusal) before any transaction, so the audit row is not rolled back. */
    public function require(WebAccount $account, int $city, string $capability): void
    {
        if (! $this->access->check($account, $city, $capability)) {
            $this->audit->record($account->id, 'access.denied', 'denied', $city);
            abort(403);
        }
    }

    public function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => __('visits.errors.'.$key, $replace)]);
    }

    /** Locks the project row for a write. */
    public function lock(int $projectId): object
    {
        return DB::table('projects')->where('id', $projectId)->lockForUpdate()->first();
    }

    // ---------------------------------------------------------------- the project

    /** @return array{rehab: bool, vehicle: bool} the desktop's hub rules (`ChooseVisitKind_Form`) */
    public function classify(object $project): array
    {
        $type = (string) (DB::table('project_types')->where('id', $project->project_type_id ?? 0)->value('name_ar') ?? '');
        $fund = (string) (DB::table('fund_types')->where('id', $project->fund_type_id ?? 0)->value('name_ar') ?? '');

        return ['rehab' => str_contains($type, 'ترميم') && str_contains($fund, 'منحة'), 'vehicle' => $this->vehicle($project)];
    }

    private function vehicle(object $project): bool
    {
        $sub = $project->project_subcategory_id ?? null;
        if ($sub === null) {
            return false;
        }
        $legacy = DB::table('lookup_legacy_map')->where('city_id', $project->city_id)->where('lookup_table', 'project_subcategories')->where('lookup_id', $sub)->value('legacy_id');
        if ($legacy !== null) {
            return in_array((int) $legacy, self::VEHICLE_SUBCATEGORIES, true);
        }
        $names = DB::table('project_subcategories')->where('id', $sub)->first(['name_ar', 'name_en']);

        return $names !== null && (in_array($names->name_ar, self::VEHICLE_NAMES, true) || in_array($names->name_en, self::VEHICLE_NAMES, true));
    }

    public function current(int $projectId): ?object
    {
        return $this->lifecycle->currentState($projectId);
    }

    /** Only funded projects (Accepted, Financed, Finished, Canceled, Closed) get new visits, as the desktop's project picker. */
    public function funded(?object $current): bool
    {
        return $current !== null && in_array($current->state_en, FinanceService::FUNDED, true);
    }

    public function loan(int $projectId): ?object
    {
        return DB::table('loans')->where('project_id', $projectId)->whereNull('deleted_at')->orderBy('id')->first(['id', 'amount', 'currency', 'taken_on']);
    }

    /** The read-only header of every visit form (desktop order): number, beneficiary, project, phone, address, funded amount, start. */
    public function header(object $project): array
    {
        $code = (string) DB::table('cities')->where('id', $project->city_id)->value('code');
        $beneficiary = $this->people($project)->first();
        $loan = $this->loan((int) $project->id);

        return [
            'number' => ProjectNumberAllocator::display($code, (int) $project->project_number), 'code' => $code, 'name' => (string) ($project->name ?? ''),
            'beneficiary' => $beneficiary->name ?? '', 'phone' => $beneficiary->mobile ?? '', 'address' => (string) ($project->place_address ?? ''),
            'amount' => $loan === null ? null : (Money::canonical($loan->amount) ?? '0').' '.$loan->currency, 'loan_date' => $loan === null ? null : Fields::day($loan->taken_on),
        ];
    }

    /** The project's people (beneficiary first, then partners): id, name as the desktop forms show it, role, mobile. */
    public function people(object $project): Collection
    {
        return DB::table('project_applicants as a')->join('people as p', 'p.id', '=', 'a.person_id')->where('a.project_id', $project->id)->whereNull('a.deleted_at')
            ->orderByRaw("CASE WHEN a.role = 'مستفيد' THEN 0 ELSE 1 END")->orderBy('a.id')
            ->get(['p.id', 'p.first_name', 'p.last_name', 'p.father_name', 'p.mobile', 'a.role'])->unique('id')
            ->map(fn ($p) => (object) ['id' => (int) $p->id, 'role' => (string) ($p->role ?? ''), 'mobile' => (string) ($p->mobile ?? ''), 'beneficiary' => $p->role === 'مستفيد',
                'name' => trim(trim(($p->first_name ?? '').' '.($p->last_name ?? '')).' ابن/ة '.($p->father_name ?? ''))])->values();
    }

    // ---------------------------------------------------------------- the date

    /** Owner decision 6: required, a real day, not in the future, not before the loan date. */
    public function date(mixed $value, int $projectId): string
    {
        $date = trim(Fields::text($value));
        $date !== '' || $this->fail('date', 'date_required');
        Fields::isDate($date) || $this->fail('date', 'date');
        $date <= now()->toDateString() || $this->fail('date', 'date_future');
        $loan = $this->loan($projectId);
        if ($loan !== null && Fields::day($loan->taken_on) !== '' && $date < Fields::day($loan->taken_on)) {
            $this->fail('date', 'date_before_loan', ['date' => Fields::day($loan->taken_on)]);
        }

        return $date;
    }

    // ---------------------------------------------------------------- visitors

    /** The city's visitor accounts (enabled holders of `visits.visitor`), plus the accounts already on the visit (placeholders included). */
    public function visitorOptions(int $city, array $chosen = []): Collection
    {
        $ids = DB::table('account_city_capabilities as g')->join('web_accounts as a', 'a.id', '=', 'g.account_id')
            ->where('g.city_id', $city)->where('g.capability', 'visits.visitor')->whereNull('g.revoked_at')->whereNull('a.disabled_at')->pluck('a.id')
            ->merge($chosen)->map(fn ($id) => (int) $id)->unique()->all();

        return DB::table('web_accounts')->whereIn('id', $ids ?: [0])->get(['id', 'username'])
            ->map(fn ($a) => (object) ['id' => (int) $a->id, 'name' => (string) $a->username])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * Validates the chosen visitors (owner decision 2: at least one; no account twice; each a visitor of the city, or already on the visit).
     *
     * @param  list<mixed>  $input  account ids, blanks ignored
     * @param  list<int>  $kept  accounts already on the visit (may stay even without the capability today)
     * @return list<int>
     */
    public function visitors(int $city, array $input, int $max, array $kept = []): array
    {
        $ids = [];
        foreach (array_values($input) as $value) {
            if (Fields::blank($value)) {
                continue;
            }
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $id === false && $this->fail('visitors', 'visitor');
            in_array($id, $ids, true) && $this->fail('visitors', 'visitor_twice');
            $ids[] = $id;
        }
        $ids === [] && $this->fail('visitors', 'visitor_required');
        count($ids) <= $max || $this->fail('visitors', 'visitor_max', ['max' => $max]);
        $allowed = $this->visitorOptions($city, $kept)->pluck('id')->all();
        foreach ($ids as $id) {
            in_array($id, $allowed, true) || $this->fail('visitors', 'visitor');
        }

        return $ids;
    }

    /** @return array<int, string> account id => username */
    public function names(array $ids): array
    {
        return DB::table('web_accounts')->whereIn('id', $ids ?: [0])->pluck('username', 'id')->mapWithKeys(fn ($n, $id) => [(int) $id => (string) $n])->all();
    }

    // ---------------------------------------------------------------- tasks and notices

    /** `Update_Task_MicroProject`: ticks or unticks the project's existing assignment of the named task. Returns whether it changed. */
    public function task(object $project, string $name, bool $done): bool
    {
        $task = DB::table('project_tasks as t')->join('task_definitions as d', 'd.id', '=', 't.task_definition_id')->where('t.project_id', $project->id)
            ->whereNull('t.deleted_at')->where('d.name_ar', $name)->first(['t.id', 't.state']);
        if ($task === null || ((int) $task->state === 1) === $done) {
            return false;
        }
        DB::table('project_tasks')->where('id', $task->id)->update(['state' => $done ? 1 : 0, 'task_date' => now(), 'updated_at' => now()]);

        return true;
    }

    /** Marks the shared visit notices of these texts seen (1) or unseen (0); optionally only those titled with the person's name. */
    public function notices(object $project, array $bodies, int $seen, ?string $title = null): int
    {
        $query = DB::table('web_notices')->where('city_id', $project->city_id)->where('project_id', $project->id)->whereNull('account_id')
            ->whereIn('kind', ['visit', 'clearance'])->whereIn('body', $bodies)->where('seen', $seen === 1 ? 0 : 1);
        if ($title !== null) {
            $query->where('title', $title);
        }

        return $query->update(['seen' => $seen]);
    }

    public function clearProject(object $project): int
    {
        return $this->projectNotices->clearProject($project);
    }

    public function stateName(string $nameEn): ?string
    {
        return DB::table('project_states')->where('name_en', $nameEn)->value('name_ar');
    }

    // ---------------------------------------------------------------- Kobo

    /** @return array<int, true> ids of `visits` written by the Kobo sync */
    public function koboVisits(array $ids): array
    {
        return DB::table('kobo_submissions')->where('local_table', 'visits')->whereIn('local_record_id', $ids ?: [0])->whereNull('deleted_at')
            ->pluck('local_record_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /** @return array<int, true> ids of `me_visits` written by the Kobo sync */
    public function koboMeVisits(array $ids): array
    {
        return DB::table('kobo_monitoring_records')->whereIn('me_visit_id', $ids ?: [0])->whereNull('deleted_at')->pluck('me_visit_id')
            ->merge(DB::table('kobo_submissions')->where('local_table', 'me_visits')->whereIn('local_record_id', $ids ?: [0])->whereNull('deleted_at')->pluck('local_record_id'))
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /** Free-text visitor names of Kobo visits (`kobo_visit_visitors.display_name`), by local record id. */
    public function koboVisitorNames(string $table, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $table === 'visits'
            ? DB::table('kobo_submissions as s')->join('kobo_visit_visitors as v', 'v.kobo_submission_id', '=', 's.id')->where('s.local_table', 'visits')->whereIn('s.local_record_id', $ids)
                ->whereNull('v.deleted_at')->orderBy('v.visitor_order')->get(['s.local_record_id as record', 'v.display_name', 'v.kobo_name'])
            : DB::table('kobo_monitoring_records as r')->join('kobo_visit_visitors as v', 'v.kobo_submission_id', '=', 'r.kobo_submission_id')->whereIn('r.me_visit_id', $ids)
                ->whereNull('v.deleted_at')->orderBy('v.visitor_order')->get(['r.me_visit_id as record', 'v.display_name', 'v.kobo_name']);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->record][] = (string) ($row->display_name ?: $row->kobo_name);
        }

        return $out;
    }

    /** "2026-11-03" for a stored date, '' when empty. */
    public static function day(mixed $value): string
    {
        return Fields::day($value);
    }

    public static function today(): string
    {
        return CarbonImmutable::today()->toDateString();
    }
}
