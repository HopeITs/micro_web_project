<?php

declare(strict_types=1);

namespace App\Services\Kobo;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\People\PeopleService;
use App\Services\ProjectNumberAllocator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kobo, read and review only (M4.14, owner decision 23 Nov 2026): the submissions the desktop synced (they arrive through the data
 * transfer), their readable answers, the sync errors, the conflict and beneficiary-matching queues with their history, and the visitor
 * name mappings. Nothing is downloaded from Kobo and no decision is taken here: the desktop stays the system of record for Kobo until
 * M4.14b replaces it with web forms. Access follows the desktop's Sync Kobo screen (administrators, data entry): `visits.manage`.
 */
final class KoboReadService
{
    public const PER_PAGE = 25;

    /** Status groups of `kobo_submissions.sync_status` as people read them. */
    public const STATUSES = [
        'imported' => ['local_inserted', 'local_updated'],
        'error' => ['local_error', 'error'],
        'deleted' => ['deleted'],
        'reviewed' => ['review_replaced', 'review_ignored', 'review_deferred', 'review_pending'],
    ];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit) {}

    /** @return list<int> */
    public function cityIds(WebAccount $account): array
    {
        return $this->access->cities($account, 'visits.manage')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function cities(WebAccount $account): Collection
    {
        return $this->access->cities($account, 'visits.manage');
    }

    /** The page was opened: refused (and recorded) without visits.manage in any city. */
    public function opened(WebAccount $account): void
    {
        $this->permitted($account);
        $this->audit->record($account->id, 'kobo.list', 'succeeded');
    }

    private function permitted(WebAccount $account, array $chosen = []): array
    {
        $cities = $this->cityIds($account);
        if ($cities === []) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $chosen = array_values(array_intersect(array_map('intval', $chosen), $cities));

        return $chosen ?: $cities;
    }

    /** Submissions joined to their form, project, person and local visit. */
    private function base(array $cities): Builder
    {
        $records = DB::table('kobo_monitoring_records')->whereNull('deleted_at')
            ->selectRaw('kobo_submission_id, MIN(project_id) as project_id, MIN(person_id) as person_id, MIN(visit_number) as visit_number, MIN(visit_date) as visit_date, MIN(me_visit_id) as me_visit_id')
            ->groupBy('kobo_submission_id');

        return DB::table('kobo_submissions as s')->join('cities as c', 'c.id', '=', 's.city_id')
            ->leftJoin('kobo_forms as f', fn ($j) => $j->on('f.form_uid', '=', 's.form_uid')->on('f.city_id', '=', 's.city_id')->whereNull('f.deleted_at'))
            ->leftJoinSub($records, 'mr', 'mr.kobo_submission_id', '=', 's.id')
            ->leftJoin('visits as v', fn ($j) => $j->on('v.id', '=', 's.local_record_id')->where('s.local_table', '=', 'visits'))
            ->leftJoin('projects as p', 'p.id', '=', DB::raw('COALESCE(mr.project_id, v.project_id)'))
            ->leftJoin('people as pe', 'pe.id', '=', 'mr.person_id')
            ->whereNull('s.deleted_at')->whereIn('s.city_id', $cities);
    }

    private const COLUMNS = ['s.id', 's.city_id', 's.form_uid', 's.form_name', 's.submitted_at', 's.submitted_by', 's.sync_status', 's.is_deleted_in_kobo', 's.local_table', 's.local_record_id', 's.error_message',
        'c.code as city_code', 'f.visit_type', 'f.visit_category', 'p.id as project_id', 'p.project_number', 'pe.first_name', 'pe.father_name', 'pe.last_name',
        'mr.visit_number', 'mr.me_visit_id', 'mr.visit_date as me_date', 'v.visit_date as opening_date', 'v.kind as visit_kind'];

    /**
     * @param  array{cities?: list<int>, status?: string, type?: string, form?: string, from?: ?string, to?: ?string, q?: string}  $filters
     */
    public function submissions(WebAccount $account, array $filters): LengthAwarePaginator
    {
        $query = $this->base($this->permitted($account, $filters['cities'] ?? []));
        if (isset(self::STATUSES[$filters['status'] ?? ''])) {
            $statuses = self::STATUSES[$filters['status']];
            $query->where(fn ($q) => $q->whereIn('s.sync_status', $statuses)->when($filters['status'] === 'deleted', fn ($d) => $d->orWhere('s.is_deleted_in_kobo', true)));
        }
        if (in_array($filters['type'] ?? '', ['opening', 'monitoring', 'final'], true)) {
            $query->where('f.visit_type', $filters['type']);
        }
        if (($filters['form'] ?? '') !== '') {
            $query->where('s.form_uid', $filters['form']);
        }
        $date = DB::raw('COALESCE(mr.visit_date, v.visit_date, DATE(s.submitted_at))');
        if (! empty($filters['from'])) {
            $query->where($date, '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where($date, '<=', $filters['to']);
        }
        $term = trim(mb_substr((string) ($filters['q'] ?? ''), 0, 100));
        if ($term !== '') {
            $number = ProjectNumberAllocator::parse($term);
            if ($number !== null) {
                $query->where('p.project_number', $number[1])->when($number[0], fn ($q) => $q->where('c.code', $number[0]));
            } else {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $query->where(fn ($q) => $q->where('pe.first_name', 'like', $like)->orWhere('pe.last_name', 'like', $like)->orWhere('s.form_name', 'like', $like)
                    ->orWhere('s.submitted_by', 'like', $like)->orWhere('s.kobo_submission_id', 'like', $like));
            }
        }
        $page = $query->orderByDesc(DB::raw('COALESCE(mr.visit_date, v.visit_date, DATE(s.submitted_at))'))->orderByDesc('s.id')
            ->paginate(self::PER_PAGE, [...self::COLUMNS, DB::raw('COALESCE(mr.visit_date, v.visit_date, DATE(s.submitted_at)) as visit_day')]);
        $names = $this->visitorNames($page->getCollection()->pluck('id')->all());
        $page->setCollection($page->getCollection()->map(fn ($row) => $this->decorate($row, $names)));

        return $page;
    }

    private function decorate(object $row, array $names): object
    {
        $row->number = $row->project_number !== null ? ProjectNumberAllocator::display($row->city_code, (int) $row->project_number) : null;
        $row->person = $row->first_name !== null ? PeopleService::fullName($row) : null;
        $row->visitors = $names[(int) $row->id] ?? [];
        $row->status = $row->is_deleted_in_kobo ? 'deleted' : $this->statusGroup((string) $row->sync_status);
        $row->day = $row->visit_day ? substr((string) $row->visit_day, 0, 10) : null;

        return $row;
    }

    public function statusGroup(string $status): string
    {
        foreach (self::STATUSES as $group => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $group;
            }
        }

        return 'other';
    }

    /** @return array<int, list<string>> visitor names (display name, else the Kobo name) by submission, in order */
    public function visitorNames(array $ids): array
    {
        $out = [];
        foreach (DB::table('kobo_visit_visitors')->whereIn('kobo_submission_id', $ids ?: [0])->whereNull('deleted_at')->orderBy('visitor_order')->get() as $v) {
            $out[(int) $v->kobo_submission_id][] = (string) ($v->display_name ?: $v->kobo_name);
        }

        return $out;
    }

    /** One submission with its readable answers. */
    public function submission(WebAccount $account, int $id): object
    {
        $row = $this->base($this->permitted($account))->where('s.id', $id)->first([...self::COLUMNS, 's.raw_json', 's.kobo_submission_id', 's.form_version', 's.kobo_status',
            DB::raw('COALESCE(mr.visit_date, v.visit_date, DATE(s.submitted_at)) as visit_day')]);
        abort_if($row === null, 404);
        $row = $this->decorate($row, $this->visitorNames([$id]));
        $raw = json_decode((string) $row->raw_json, true) ?: [];
        $row->answers = KoboCatalog::readable((string) $row->form_uid, $raw);
        $row->known_form = KoboCatalog::fields((string) $row->form_uid) !== [];
        unset($row->raw_json);
        $this->audit->record($account->id, 'kobo.view', 'succeeded', (int) $row->city_id);

        return $row;
    }

    /** The forms the account may see, for the filter. @return Collection<int, object{form_uid: string, form_name: string, city_code: string}> */
    public function forms(WebAccount $account): Collection
    {
        return DB::table('kobo_forms as f')->join('cities as c', 'c.id', '=', 'f.city_id')->whereIn('f.city_id', $this->permitted($account))->whereNull('f.deleted_at')
            ->orderBy('c.id')->orderBy('f.visit_type')->orderBy('f.form_name')->get(['f.form_uid', 'f.form_name', 'f.visit_type', 'f.visit_category', 'c.code as city_code']);
    }

    /** Counts per status group, for the tabs. @return array<string, int> */
    public function counts(WebAccount $account, array $cities = []): array
    {
        $rows = DB::table('kobo_submissions')->whereNull('deleted_at')->whereIn('city_id', $this->permitted($account, $cities))
            ->selectRaw('sync_status, is_deleted_in_kobo, COUNT(*) as n')->groupBy('sync_status', 'is_deleted_in_kobo')->get();
        $out = array_fill_keys([...array_keys(self::STATUSES), 'all'], 0);
        foreach ($rows as $r) {
            $group = $r->is_deleted_in_kobo ? 'deleted' : $this->statusGroup((string) $r->sync_status);
            $out[$group] = ($out[$group] ?? 0) + (int) $r->n;
            $out['all'] += (int) $r->n;
        }
        $out['conflicts'] = DB::table('kobo_sync_conflicts')->whereNull('deleted_at')->whereIn('city_id', $this->permitted($account, $cities))->whereNotIn('state', ['resolved'])->count();
        $out['reviews'] = DB::table('kobo_person_reviews')->whereNull('deleted_at')->whereIn('city_id', $this->permitted($account, $cities))->whereNotIn('state', ['confirmed'])->count();

        return $out;
    }

    // ---------------------------------------------------------------- review queues (read only)

    /** Conflicts: incoming vs existing submission, the decision and its history, and the differing answers. */
    public function conflicts(WebAccount $account, array $cities = []): Collection
    {
        $rows = DB::table('kobo_sync_conflicts as k')->join('cities as c', 'c.id', '=', 'k.city_id')
            ->leftJoin('kobo_submissions as i', 'i.id', '=', 'k.incoming_submission_id')->leftJoin('kobo_submissions as e', 'e.id', '=', 'k.existing_submission_id')
            ->leftJoin('visits as v', 'v.id', '=', 'k.visit_id')->leftJoin('projects as p', 'p.id', '=', 'v.project_id')
            ->whereNull('k.deleted_at')->whereIn('k.city_id', $this->permitted($account, $cities))
            ->orderByRaw("CASE WHEN k.state = 'resolved' THEN 1 ELSE 0 END")->orderByDesc('k.id')
            ->get(['k.*', 'c.code as city_code', 'i.form_uid', 'i.form_name', 'i.raw_json as incoming_json', 'e.raw_json as existing_json', 'i.kobo_submission_id as incoming_ref', 'e.kobo_submission_id as existing_ref',
                'p.id as project_id', 'p.project_number']);
        $audits = DB::table('kobo_sync_conflict_audits')->whereIn('kobo_sync_conflict_id', $rows->pluck('id')->all() ?: [0])->whereNull('deleted_at')->orderBy('recorded_at')->orderBy('id')
            ->get(['kobo_sync_conflict_id', 'event', 'decision', 'reason', 'actor_name', 'recorded_at'])->groupBy('kobo_sync_conflict_id');

        return $rows->map(function ($k) use ($audits) {
            $k->number = $k->project_number !== null ? ProjectNumberAllocator::display($k->city_code, (int) $k->project_number) : null;
            $k->differences = $this->differences((string) $k->form_uid, json_decode((string) $k->incoming_json, true) ?: [], json_decode((string) $k->existing_json, true) ?: []);
            $k->history = ($audits->get($k->id) ?? collect())->values();
            unset($k->incoming_json, $k->existing_json);

            return $k;
        });
    }

    /** Fields whose answers differ between two submissions; a missing field differs from an empty one, as the desktop compares. */
    private function differences(string $formUid, array $incoming, array $existing): array
    {
        $fields = KoboCatalog::fields($formUid);
        $out = [];
        foreach (array_unique([...array_keys($incoming), ...array_keys($existing)]) as $path) {
            if (KoboCatalog::isMeta((string) $path)) {
                continue;
            }
            $a = array_key_exists($path, $incoming) ? $incoming[$path] : null;
            $b = array_key_exists($path, $existing) ? $existing[$path] : null;
            if (array_key_exists($path, $incoming) === array_key_exists($path, $existing) && $a === $b) {
                continue;
            }
            $field = $fields[$path] ?? ['label' => $path, 'type' => 'text', 'choices' => []];
            $out[] = ['label' => (string) $field['label'], 'incoming' => array_key_exists($path, $incoming) ? KoboCatalog::display($field, $a) : null,
                'existing' => array_key_exists($path, $existing) ? KoboCatalog::display($field, $b) : null];
        }

        return $out;
    }

    /** Beneficiary matching: the Kobo name, the project's people to choose from, the state, the chosen person and the history. */
    public function reviews(WebAccount $account, array $cities = []): Collection
    {
        $rows = DB::table('kobo_person_reviews as r')->join('cities as c', 'c.id', '=', 'r.city_id')->leftJoin('projects as p', 'p.id', '=', 'r.project_id')
            ->leftJoin('people as pe', 'pe.id', '=', 'r.person_id')->leftJoin('kobo_submissions as s', 's.id', '=', 'r.kobo_submission_id')
            ->whereNull('r.deleted_at')->whereIn('r.city_id', $this->permitted($account, $cities))
            ->orderByRaw("CASE WHEN r.state = 'confirmed' THEN 1 ELSE 0 END")->orderByDesc('r.id')
            ->get(['r.*', 'c.code as city_code', 'p.project_number', 'pe.first_name', 'pe.father_name', 'pe.last_name', 's.form_name', 's.submitted_at']);
        $candidates = DB::table('project_applicants as a')->join('people as pe', 'pe.id', '=', 'a.person_id')->whereIn('a.project_id', $rows->pluck('project_id')->filter()->all() ?: [0])
            ->whereNull('a.deleted_at')->get(['a.project_id', 'a.role', 'pe.id', 'pe.first_name', 'pe.father_name', 'pe.last_name'])->groupBy('project_id');
        $audits = DB::table('kobo_person_review_audits')->whereIn('kobo_submission_id', $rows->pluck('kobo_submission_id')->all() ?: [0])->whereNull('deleted_at')
            ->orderBy('recorded_at')->get(['kobo_submission_id', 'decision', 'reason', 'actor_name', 'recorded_at'])->groupBy('kobo_submission_id');

        return $rows->map(function ($r) use ($candidates, $audits) {
            $r->number = $r->project_number !== null ? ProjectNumberAllocator::display($r->city_code, (int) $r->project_number) : null;
            $r->chosen = $r->first_name !== null ? PeopleService::fullName($r) : null;
            $r->candidates = ($candidates->get($r->project_id) ?? collect())->map(fn ($c) => (object) ['id' => (int) $c->id, 'name' => PeopleService::fullName($c), 'role' => $c->role])->values();
            $r->history = ($audits->get($r->kobo_submission_id) ?? collect())->values();

            return $r;
        });
    }

    /** Visitor names: the mappings (Kobo name → account or display name) and the names that stayed unlinked. */
    public function visitors(WebAccount $account, array $cities = []): array
    {
        $permitted = $this->permitted($account, $cities);
        $mappings = DB::table('kobo_user_mappings as m')->join('cities as c', 'c.id', '=', 'm.city_id')->leftJoin('web_accounts as a', 'a.id', '=', 'm.account_id')
            ->leftJoin('kobo_forms as f', fn ($j) => $j->on('f.form_uid', '=', 'm.form_uid')->on('f.city_id', '=', 'm.city_id'))
            ->whereNull('m.deleted_at')->whereIn('m.city_id', $permitted)->orderBy('c.id')->orderBy('m.kobo_name')
            ->get(['m.id', 'c.code as city_code', 'm.form_uid', 'f.form_name', 'm.kobo_name', 'm.display_name', 'a.username', 'm.is_ignored', 'm.is_active']);
        $unlinked = DB::table('kobo_visit_visitors as v')->join('kobo_submissions as s', 's.id', '=', 'v.kobo_submission_id')->join('cities as c', 'c.id', '=', 'v.city_id')
            ->whereNull('v.deleted_at')->whereNull('v.account_id')->where('s.is_deleted_in_kobo', false)->whereIn('v.city_id', $permitted)
            ->selectRaw('c.code as city_code, v.display_name, COUNT(*) as visits')->groupBy('c.code', 'v.display_name')->orderBy('c.code')->orderBy('v.display_name')->get();

        return ['mappings' => $mappings, 'unlinked' => $unlinked];
    }
}
