<?php

declare(strict_types=1);

namespace App\Services\Field;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Finance\Fields;
use App\Services\ProjectNumberAllocator;
use App\Support\TableTools\TableSearch;
use App\Support\TableTools\TableSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Profession meetings (M4.15; desktop `ProfessionMeetings_Form`) on the unified schema: a survey of the practitioners of one or more
 * professions in a city, followed by the minutes. Owner decisions (22 Nov 2026):
 *  - a meeting is a draft until it is marked final; a draft saves with only the profession and date, a final one needs every answer and
 *    locks the meeting, which only an administrator (`accounts.manage`) reopens;
 *  - "eligibility" is the beneficiary check: participants and attendees are linked to projects of the meeting's professions in the city;
 *  - the participant questions (19-22) are the participants' interests, reported by `report()`;
 *  - readers hold `profession_meetings.view`, writers `.manage`, deleters `.delete` (administrator and data entry), per city.
 * The survey (sections, questions, options) is shared by every city. Detail rows are replaced as a whole on every save, as on the desktop.
 */
final class ProfessionMeetingService
{
    public const PER_PAGE = 30;

    /** The sortable columns of the meetings list. */
    public const SORT_KEYS = ['date', 'career', 'city', 'place', 'invited', 'attendance', 'absent', 'state'];

    /** Competition levels of question 6, by sort order (`SaveCompetitionRows`). */
    public const LEVELS = [1 => 'منافسة شديدة', 2 => 'منافسة متوسطة', 3 => 'منافسة ضعيفة'];

    public const DEFAULT_CHALLENGES = ['الوضع الاقتصادي', 'اليد العاملة', 'التسويق', 'مكان المشروع', 'نقص المعدات', 'تنفيذ مرحلة من مراحل عملك خارج مكان المشروع'];

    /** The most beneficiaries the eligibility list shows at once (highest project numbers first). */
    public const BENEFICIARY_LIMIT = 300;

    /** Participant questions: who can provide content, trainers, trainees, bazaar (the participants' interests). */
    public const INTEREST_QUESTIONS = [19, 20, 21, 22];

    private const MAX_ROWS = 200;

    private const CHILDREN = ['profession_meeting_careers', 'profession_meeting_answers', 'profession_meeting_option_answers', 'profession_meeting_participants', 'profession_meeting_attendees',
        'profession_meeting_brands', 'profession_meeting_challenges', 'profession_meeting_competitions', 'profession_meeting_equipment', 'profession_meeting_seasons', 'profession_meeting_minutes'];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit) {}

    private function authorize(WebAccount $account, int $city, string ...$capabilities): void
    {
        foreach (['profession_meetings.view', ...$capabilities] as $capability) {
            if (! $this->access->check($account, $city, $capability)) {
                $this->audit->record($account->id, 'access.denied', 'denied', $city);
                abort(403);
            }
        }
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => __('field.meeting.errors.'.$key, $replace)]);
    }

    public function cities(WebAccount $account, string $capability = 'profession_meetings.view'): Collection
    {
        return $this->access->cities($account, $capability);
    }

    /** Local name of a lookup row. */
    private function name(object $row): string
    {
        return app()->isLocale('en') && ($row->name_en ?? '') !== '' ? (string) $row->name_en : (string) $row->name_ar;
    }

    // ---------------------------------------------------------------- list

    /** @return array{rows: LengthAwarePaginator, cities: Collection, creatable: Collection, careers: Collection, years: Collection} */
    public function index(WebAccount $account, array $filters): array
    {
        $permitted = $this->cities($account);
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $all = $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $selected = array_values(array_intersect(array_map('intval', $filters['cities'] ?? []), $all));
        $ids = $selected !== [] ? $selected : $all;
        $this->audit->record($account->id, 'profession_meetings.list', 'succeeded');

        $query = DB::table('profession_meetings as m')->join('cities as ci', 'ci.id', '=', 'm.city_id')->whereIn('m.city_id', $ids)->whereNull('m.deleted_at');
        ! empty($filters['year']) && $query->whereBetween('m.meeting_date', [sprintf('%04d-01-01', $filters['year']), sprintf('%04d-12-31', $filters['year'])]);
        ! empty($filters['state']) && $query->where('m.record_state', $filters['state']);
        ! empty($filters['career']) && $query->whereExists(fn ($q) => $q->selectRaw('1')->from('profession_meeting_careers as mc')->whereColumn('mc.profession_meeting_id', 'm.id')
            ->whereNull('mc.deleted_at')->where('mc.career_id', (int) $filters['career']));
        ! empty($filters['q']) && TableSearch::apply($query, ['m.place'], (string) $filters['q'], function (Builder $any, string $word) {
            // Any profession of the meeting: its list, or the single one of an older record.
            $any->orWhereExists(fn (Builder $e) => $e->selectRaw('1')->from('careers as qc')
                ->where(fn (Builder $mine) => $mine->whereColumn('qc.id', 'm.career_id')->orWhereIn('qc.id', fn (Builder $list) => $list->select('qmc.career_id')->from('profession_meeting_careers as qmc')
                    ->whereColumn('qmc.profession_meeting_id', 'm.id')->whereNull('qmc.deleted_at')))
                ->where(function (Builder $names) use ($word) {
                    TableSearch::orLike($names, 'qc.name_ar', $word);
                    TableSearch::orLike($names, 'qc.name_en', $word);
                }));
        });
        // The career as the list shows it first: the meeting's first listed profession, else the single one of an older record.
        $career = app()->isLocale('en') ? "COALESCE(NULLIF(sc.name_en, ''), sc.name_ar)" : 'sc.name_ar';
        $listed = DB::table('profession_meeting_careers as smc')->join('careers as sc', 'sc.id', '=', 'smc.career_id')->whereColumn('smc.profession_meeting_id', 'm.id')
            ->whereNull('smc.deleted_at')->orderBy('smc.id')->selectRaw($career)->limit(1);
        $single = DB::table('careers as sc')->whereColumn('sc.id', 'm.career_id')->selectRaw($career);
        TableSort::apply($query, [
            'date' => 'm.meeting_date',
            'career' => 'COALESCE(('.$listed->toSql().'), ('.$single->toSql().'))',
            'city' => app()->isLocale('en') ? 'ci.name_en' : 'ci.name_ar', 'place' => "NULLIF(m.place, '')", 'invited' => 'm.invited_count', 'attendance' => 'm.attendance_count',
            'absent' => 'm.absence_count', 'state' => 'm.record_state',
        ], $filters['sort'] ?? null, $filters['dir'] ?? null);
        $rows = $query->orderByDesc('m.meeting_date')->orderByDesc('m.id')->select(['m.id', 'm.city_id', 'm.career_id', 'm.meeting_date', 'm.place', 'm.invited_count', 'm.attendance_count', 'm.absence_count', 'm.record_state', 'ci.name_ar as city_ar', 'ci.name_en as city_en'])
            ->paginate(self::PER_PAGE);
        $careers = DB::table('profession_meeting_careers as mc')->join('careers as c', 'c.id', '=', 'mc.career_id')->whereIn('mc.profession_meeting_id', $rows->getCollection()->pluck('id')->all() ?: [0])
            ->whereNull('mc.deleted_at')->orderBy('mc.id')->get(['mc.profession_meeting_id', 'c.name_ar', 'c.name_en'])->groupBy('profession_meeting_id');
        $fallback = DB::table('careers')->whereIn('id', $rows->getCollection()->pluck('career_id')->filter()->all() ?: [0])->get(['id', 'name_ar', 'name_en'])->keyBy('id');
        $rows->setCollection($rows->getCollection()->map(function ($row) use ($careers, $fallback) {
            $names = ($careers[$row->id] ?? collect())->map(fn ($c) => $this->name($c))->all();
            $row->careers = $names !== [] ? $names : ($row->career_id && isset($fallback[$row->career_id]) ? [$this->name($fallback[$row->career_id])] : []);
            $row->city = app()->isLocale('en') ? $row->city_en : $row->city_ar;

            return $row;
        }));

        return [
            'rows' => $rows, 'cities' => $permitted, 'creatable' => $this->cities($account, 'profession_meetings.manage'),
            'careers' => DB::table('careers')->where('is_active', 1)->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']),
            'years' => $this->years($all),
        ];
    }

    /** Years that have meetings in the cities, newest first (portable: no SQL date function). */
    private function years(array $cities): Collection
    {
        return DB::table('profession_meetings')->whereIn('city_id', $cities)->whereNull('deleted_at')->whereNotNull('meeting_date')->pluck('meeting_date')
            ->map(fn ($d) => (int) substr((string) $d, 0, 4))->unique()->sortDesc()->values();
    }

    // ------------------------------------------------------------- catalog

    /** The survey: sections with their questions and options (shared by every city). */
    public function catalog(): array
    {
        $options = DB::table('profession_options')->where('is_active', 1)->orderBy('sort_order')->orderBy('id')->get()->groupBy('profession_question_id');
        $questions = DB::table('profession_questions')->where('is_active', 1)->orderBy('sort_order')->orderBy('id')->get()->map(function ($q) use ($options) {
            $q->options = ($options[$q->id] ?? collect())->values();
            $q->label = $this->name($q);

            return $q;
        });
        $sections = DB::table('profession_sections')->where('is_active', 1)->orderBy('sort_order')->orderBy('id')->get()->map(function ($s) use ($questions) {
            $s->label = $this->name($s);
            $s->questions = $questions->where('profession_section_id', $s->id)->values();

            return $s;
        });

        return ['sections' => $sections, 'questions' => $questions];
    }

    /** Professions for the selector, with the sub-category name. */
    public function careers(): Collection
    {
        return DB::table('careers as c')->leftJoin('project_subcategories as s', 's.id', '=', 'c.project_subcategory_id')->where('c.is_active', 1)->orderBy('c.sort_order')->orderBy('c.id')
            ->get(['c.id', 'c.name_ar', 'c.name_en', 's.name_ar as sub_ar', 's.name_en as sub_en'])->map(function ($c) {
                $c->label = $this->name($c);
                $c->sub = app()->isLocale('en') && $c->sub_en ? $c->sub_en : $c->sub_ar;

                return $c;
            });
    }

    // ---------------------------------------------------------------- form

    public function blank(): array
    {
        return [
            'careerIds' => [], 'date' => now()->toDateString(), 'place' => '', 'invited' => '', 'attendance' => '', 'opt' => [], 'tx' => [],
            'comp' => [1 => ['n' => '', 'reason' => ''], 2 => ['n' => '', 'reason' => ''], 3 => ['n' => '', 'reason' => '']], 'seasons' => [['n' => '', 'from' => '', 'to' => '', 'reason' => '']],
            'equipment' => [['spec' => '', 'items' => '']], 'challenges' => array_map(fn ($c) => ['name' => $c, 'n' => '', 'solution' => ''], self::DEFAULT_CHALLENGES),
            'brands' => '', 'part' => [], 'attendees' => [], 'minutes' => [], 'notes' => '',
        ];
    }

    private function load(int $id): object
    {
        $meeting = DB::table('profession_meetings')->where('id', $id)->whereNull('deleted_at')->first();
        abort_if($meeting === null, 404);

        return $meeting;
    }

    /**
     * Read model of a meeting for the form page: the meeting row, its state array and what the account may do with it.
     *
     * @return array{meeting: object, city: object, state: array, canManage: bool, canDelete: bool, canReopen: bool, locked: bool, version: string}
     */
    public function form(WebAccount $account, int $id): array
    {
        $meeting = $this->load($id);
        $city = (int) $meeting->city_id;
        $this->authorize($account, $city);
        $this->audit->record($account->id, 'profession_meetings.view', 'succeeded', $city, subjectType: 'meeting_entity', subjectId: $id);
        $final = $meeting->record_state === 'final';

        return [
            'meeting' => $meeting, 'city' => DB::table('cities')->where('id', $city)->first(), 'state' => $this->state($meeting),
            'canManage' => $this->access->check($account, $city, 'profession_meetings.manage'), 'canDelete' => $this->access->check($account, $city, 'profession_meetings.delete'),
            'canReopen' => $final && $this->access->check($account, $city, 'accounts.manage'), 'locked' => $final, 'version' => (string) $meeting->updated_at,
        ];
    }

    /** A new meeting's access check: the writer of the city. */
    public function create(WebAccount $account, int $city): object
    {
        $this->authorize($account, $city, 'profession_meetings.manage');

        return DB::table('cities')->where('id', $city)->firstOrFail();
    }

    /** The stored meeting in the shape the form edits it. */
    public function state(object $meeting): array
    {
        $id = (int) $meeting->id;
        $rows = fn (string $table) => DB::table($table)->where('profession_meeting_id', $id)->whereNull('deleted_at')->orderBy('id')->get();
        $state = $this->blank();
        $careerIds = DB::table('profession_meeting_careers')->where('profession_meeting_id', $id)->whereNull('deleted_at')->orderBy('id')->pluck('career_id')->map(fn ($c) => (int) $c)->all();
        $state['careerIds'] = $careerIds !== [] ? $careerIds : ($meeting->career_id ? [(int) $meeting->career_id] : []);
        $state['date'] = Fields::day($meeting->meeting_date);
        $state['place'] = (string) $meeting->place;
        $state['invited'] = $meeting->invited_count === null ? '' : (string) $meeting->invited_count;
        $state['attendance'] = $meeting->attendance_count === null ? '' : (string) $meeting->attendance_count;
        $state['notes'] = (string) $meeting->minutes_notes;
        foreach ($rows('profession_meeting_option_answers') as $r) {
            $state['opt'][(int) $r->profession_option_id] = ['on' => (bool) $r->is_selected, 'n' => $r->rank_value === null ? '' : (string) $r->rank_value, 'text' => (string) $r->other_text];
        }
        foreach ($rows('profession_meeting_answers') as $r) {
            $state['tx'][(int) $r->profession_question_id] = (string) $r->value_text;
        }
        foreach (DB::table('profession_meeting_competitions')->where('profession_meeting_id', $id)->whereNull('deleted_at')->orderBy('id')->get() as $i => $r) {
            $level = array_search($r->competition_level, self::LEVELS, true) ?: $i + 1;
            $state['comp'][$level] = ['n' => $r->count_value === null ? '' : (string) $r->count_value, 'reason' => (string) $r->reason];
        }
        $seasons = DB::table('profession_meeting_seasons')->where('profession_meeting_id', $id)->whereNull('deleted_at')->orderBy('sort_order')->orderBy('id')->get();
        $seasons->isNotEmpty() && $state['seasons'] = $seasons->map(fn ($r) => ['n' => $r->season_count === null ? '' : (string) $r->season_count, 'from' => (string) $r->from_period, 'to' => (string) $r->to_period, 'reason' => (string) $r->reason])->all();
        $equipment = DB::table('profession_meeting_equipment')->where('profession_meeting_id', $id)->whereNull('deleted_at')->orderBy('sort_order')->orderBy('id')->get();
        $equipment->isNotEmpty() && $state['equipment'] = $equipment->map(fn ($r) => ['spec' => (string) $r->specialization, 'items' => (string) $r->equipment])->all();
        $challenges = DB::table('profession_meeting_challenges')->where('profession_meeting_id', $id)->whereNull('deleted_at')->orderBy('sort_order')->orderBy('id')->get();
        $challenges->isNotEmpty() && $state['challenges'] = $challenges->map(fn ($r) => ['name' => (string) $r->challenge, 'n' => $r->challenge_count === null ? '' : (string) $r->challenge_count, 'solution' => (string) $r->suggested_solution])->all();
        $state['brands'] = DB::table('profession_meeting_brands')->where('profession_meeting_id', $id)->whereNull('deleted_at')->orderBy('sort_order')->orderBy('id')->pluck('brand_name')->implode("\n");
        $numbers = $this->numbers(DB::table('profession_meeting_participants')->where('profession_meeting_id', $id)->whereNull('deleted_at')->pluck('project_id')
            ->merge(DB::table('profession_meeting_attendees')->where('profession_meeting_id', $id)->whereNull('deleted_at')->pluck('project_id')));
        foreach (DB::table('profession_meeting_participants')->where('profession_meeting_id', $id)->whereNull('deleted_at')->orderBy('sort_order')->orderBy('id')->get() as $r) {
            $state['part'][(int) $r->profession_question_id][] = ['project' => $numbers[$r->project_id] ?? '', 'name' => (string) $r->person_name, 'speciality' => (string) $r->speciality];
        }
        foreach (DB::table('profession_meeting_attendees')->where('profession_meeting_id', $id)->whereNull('deleted_at')->orderBy('sort_order')->orderBy('id')->get() as $r) {
            $state['attendees'][] = ['project' => $numbers[$r->project_id] ?? '', 'name' => (string) $r->attendee_name, 'speciality' => (string) $r->speciality];
        }
        foreach (DB::table('profession_meeting_minutes')->where('profession_meeting_id', $id)->whereNull('deleted_at')->get() as $r) {
            $state['minutes'][(int) $r->profession_section_id] = (string) $r->notes;
        }

        return $state;
    }

    /** Display numbers (CITY-####) of the given project ids. */
    private function numbers(Collection $projectIds): array
    {
        $ids = $projectIds->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        return DB::table('projects as p')->join('cities as c', 'c.id', '=', 'p.city_id')->whereIn('p.id', $ids)->get(['p.id', 'c.code', 'p.project_number'])
            ->mapWithKeys(fn ($p) => [(int) $p->id => ProjectNumberAllocator::display((string) $p->code, (int) $p->project_number)])->all();
    }

    /**
     * Eligibility check: the beneficiaries of the meeting's professions in the city (project, state, funded or not), to pick participants
     * and attendees from. Needs only the meeting's view permission.
     *
     * @param  list<int>  $careerIds
     */
    public function beneficiaries(WebAccount $account, int $city, array $careerIds, string $search = ''): Collection
    {
        $this->authorize($account, $city);
        if ($careerIds === []) {
            return collect();
        }
        $query = DB::table('projects as p')->join('cities as c', 'c.id', '=', 'p.city_id')->where('p.city_id', $city)->whereIn('p.career_id', array_map('intval', $careerIds))->whereNull('p.deleted_at');
        $this->searchBeneficiaries($query, trim($search));
        $projects = $query->orderByDesc('p.project_number')->limit(self::BENEFICIARY_LIMIT)->get(['p.id', 'p.project_number', 'c.code']);
        $ids = $projects->pluck('id')->all() ?: [0];
        $states = DB::table('project_state_history as h')->join('project_states as s', 's.id', '=', 'h.project_state_id')->whereIn('h.project_id', $ids)->where('h.is_current', true)->whereNull('h.deleted_at')
            ->get(['h.project_id', 's.name_ar', 's.name_en'])->keyBy('project_id');
        $funded = DB::table('loans')->whereIn('project_id', $ids)->whereNull('deleted_at')->distinct()->pluck('project_id')->flip();
        $people = DB::table('project_applicants as a')->join('people as pe', 'pe.id', '=', 'a.person_id')->whereIn('a.project_id', $ids)->whereNull('a.deleted_at')
            ->orderByRaw("CASE WHEN a.role = 'مستفيد' THEN 0 ELSE 1 END")->orderBy('a.id')->get(['a.project_id', 'pe.first_name', 'pe.last_name', 'pe.father_name'])->groupBy('project_id');

        return $projects->map(function ($p) use ($states, $funded, $people) {
            $who = ($people[$p->id] ?? collect())->first();

            return (object) [
                'project' => ProjectNumberAllocator::display((string) $p->code, (int) $p->project_number),
                'name' => $who === null ? '' : trim(($who->first_name ?? '').' '.($who->last_name ?? '').' '.__('staff.tasks.son_of').' '.($who->father_name ?? '')),
                'state' => isset($states[$p->id]) ? $this->name($states[$p->id]) : '—', 'funded' => isset($funded[$p->id]),
            ];
        });
    }

    /** Narrows the eligibility list before it is cut: a project number, or every word in a name of one of the project's people. */
    private function searchBeneficiaries(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }
        $number = ProjectNumberAllocator::parse($term);
        if ($number !== null) {
            $query->where('p.project_number', $number[1])->when($number[0] !== null, fn (Builder $q) => $q->where('c.code', $number[0]));

            return;
        }
        $query->whereExists(function (Builder $e) use ($term) {
            $e->selectRaw('1')->from('project_applicants as qa')->join('people as qp', 'qp.id', '=', 'qa.person_id')->whereColumn('qa.project_id', 'p.id')->whereNull('qa.deleted_at');
            TableSearch::apply($e, ['qp.first_name', 'qp.father_name', 'qp.last_name'], $term);
        });
    }

    // ---------------------------------------------------------------- save

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : (is_numeric($value) ? (string) $value : '');
    }

    private function nullable(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text === '' ? null : $text;
    }

    /** A whole-number field: blank is null, anything but digits is refused. */
    private function whole(mixed $value, string $field): ?int
    {
        $text = $this->text($value);
        if ($text === '') {
            return null;
        }
        (preg_match('/\A\d{1,6}\z/', $text) === 1) || $this->fail($field, 'number');

        return (int) $text;
    }

    /** Rows of a submitted table without the completely blank ones. */
    private function rows(mixed $raw, array $keys): array
    {
        $rows = [];
        foreach (array_slice(array_values(is_array($raw) ? $raw : []), 0, self::MAX_ROWS) as $row) {
            $row = is_array($row) ? $row : [];
            $clean = [];
            foreach ($keys as $key) {
                $clean[$key] = $this->text($row[$key] ?? '');
            }
            implode('', $clean) !== '' && $rows[] = $clean;
        }

        return $rows;
    }

    /**
     * Saves a meeting as a draft, or as final when `$final` (every answer then required; the meeting locks). A new meeting needs
     * `$city`. Returns the meeting id. A stale `$version` (the meeting's updated_at as the page loaded it) is refused.
     */
    public function save(WebAccount $account, ?int $id, ?int $city, array $state, bool $final, string $version): int
    {
        return DB::transaction(function () use ($account, $id, $city, $state, $final, $version) {
            $meeting = null;
            if ($id !== null) {
                $meeting = DB::table('profession_meetings')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
                abort_if($meeting === null, 404);
                $city = (int) $meeting->city_id;
            }
            abort_if($city === null, 404);
            $this->authorize($account, $city, 'profession_meetings.manage');
            if ($meeting !== null) {
                abort_unless(hash_equals((string) $meeting->updated_at, $version), 409, 'The meeting changed. Reload before saving.');
                $meeting->record_state === 'final' && $this->fail('state', 'locked');
            }
            $plan = $this->plan($city, $state, $final);
            $now = now()->format('Y-m-d H:i:s');
            $header = $plan['header'] + ['record_state' => $final ? 'final' : 'draft', 'edited_by_account_id' => $account->id, 'updated_at' => $now];
            if ($meeting === null) {
                $id = (int) DB::table('profession_meetings')->insertGetId($header + ['city_id' => $city, 'program_registrar_account_id' => $account->id, 'created_at' => $now]);
            } else {
                DB::table('profession_meetings')->where('id', $id)->update($header);
            }
            foreach (self::CHILDREN as $table) {
                DB::table($table)->where('profession_meeting_id', $id)->delete();
            }
            foreach ($plan['children'] as $table => $rows) {
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table($table)->insert(array_map(fn ($row) => $row + ['city_id' => $city, 'profession_meeting_id' => $id, 'created_at' => $now, 'updated_at' => $now], $chunk));
                }
            }
            $this->audit->record($account->id, 'profession_meetings.saved', 'succeeded', $city, subjectType: 'meeting_entity', subjectId: $id,
                details: [['field' => 'state', 'before' => $meeting?->record_state, 'after' => $final ? 'final' : 'draft']]);

            return $id;
        });
    }

    /** Validates the submission and builds the header and the child rows. Draft: profession, date and number formats; final: everything. */
    private function plan(int $city, array $s, bool $final): array
    {
        $careerIds = array_values(array_unique(array_map('intval', is_array($s['careerIds'] ?? null) ? $s['careerIds'] : [])));
        $careerIds !== [] || $this->fail('careerIds', 'career');
        $careers = DB::table('careers')->whereIn('id', $careerIds)->get()->keyBy('id');
        count($careers) === count($careerIds) || $this->fail('careerIds', 'career');
        $date = $this->text($s['date'] ?? '') ?: now()->toDateString();
        Fields::isDate($date) || $this->fail('date', 'date');
        $place = $this->text($s['place'] ?? '');
        mb_strlen($place) <= 200 || $this->fail('place', 'place');
        $invited = $this->whole($s['invited'] ?? null, 'invited');
        $attendance = $this->whole($s['attendance'] ?? null, 'attendance');
        ($invited === null || $attendance === null || $attendance <= $invited) || $this->fail('attendance', 'attendance_over');
        if ($final) {
            $place !== '' || $this->fail('place', 'place');
            ($invited ?? 0) > 0 || $this->fail('invited', 'invited');
            ($attendance ?? 0) > 0 || $this->fail('attendance', 'attendance');
        }
        $att = $attendance ?? 0;
        $catalog = $this->catalog();
        $c = array_fill_keys(['profession_meeting_careers', 'profession_meeting_answers', 'profession_meeting_option_answers', 'profession_meeting_participants', 'profession_meeting_attendees',
            'profession_meeting_brands', 'profession_meeting_challenges', 'profession_meeting_competitions', 'profession_meeting_equipment', 'profession_meeting_seasons', 'profession_meeting_minutes'], []);
        foreach ($careerIds as $careerId) {
            $c['profession_meeting_careers'][] = ['career_id' => $careerId];
        }
        $opt = is_array($s['opt'] ?? null) ? $s['opt'] : [];
        $tx = is_array($s['tx'] ?? null) ? $s['tx'] : [];
        foreach ($catalog['questions'] as $q) {
            $field = 'question'.$q->id;
            $n = (string) $q->number;
            if (in_array($q->answer_type, ['option_number', 'multi_option'], true)) {
                $total = 0;
                $chosen = 0;
                foreach ($q->options as $o) {
                    $row = is_array($opt[$o->id] ?? null) ? $opt[$o->id] : [];
                    $count = $q->answer_type === 'option_number' ? ($this->whole($row['n'] ?? null, $field) ?? 0) : null;
                    $on = $q->answer_type === 'option_number' ? ($count ?? 0) > 0 : ! empty($row['on']);
                    if (! $on) {
                        continue;
                    }
                    $chosen++;
                    $total += $count ?? 0;
                    $other = $this->nullable($row['text'] ?? null);
                    mb_strlen((string) $other) <= 255 || $this->fail($field, 'long');
                    ($other !== null || (! $o->has_details && ! $o->is_other) || ! $final) || $this->fail($field, 'other_text', ['n' => $n]);
                    $c['profession_meeting_option_answers'][] = ['profession_option_id' => (int) $o->id, 'is_selected' => 1, 'rank_value' => $count, 'other_text' => $other];
                }
                if ($final) {
                    $q->answer_type === 'option_number' ? ($total === $att || $this->fail($field, 'total', ['n' => $n])) : ($chosen > 0 || $this->fail($field, 'answer', ['n' => $n]));
                }
            } elseif ($q->answer_type === 'text') {
                $value = $this->text($tx[$q->id] ?? '');
                ($value !== '' || ! $final) || $this->fail($field, 'answer', ['n' => $n]);
                $value !== '' && $c['profession_meeting_answers'][] = ['profession_question_id' => (int) $q->id, 'value_text' => $value, 'value_number' => null];
            }
        }

        $this->tables($city, $careerIds, $s, $att, $final, $catalog, $c);
        $minutes = is_array($s['minutes'] ?? null) ? $s['minutes'] : [];
        foreach ($catalog['sections'] as $section) {
            $text = $this->text($minutes[$section->id] ?? '');
            ($text !== '' || ! $final) || $this->fail('minutes', 'minutes');
            $text !== '' && $c['profession_meeting_minutes'][] = ['profession_section_id' => (int) $section->id, 'notes' => $text];
        }

        return ['header' => [
            'career_id' => $careerIds[0], 'project_subcategory_id' => $careers[$careerIds[0]]->project_subcategory_id, 'meeting_date' => $date, 'place' => $place === '' ? null : $place,
            'invited_count' => $invited, 'attendance_count' => $attendance, 'absence_count' => $invited !== null && $attendance !== null ? $invited - $attendance : null,
            'minutes_notes' => $this->nullable($s['notes'] ?? null),
        ], 'children' => $c];
    }

    /** The table questions (6, 8, 10, 12, 16, 19-22) and the attendees. */
    private function tables(int $city, array $careerIds, array $s, int $att, bool $final, array $catalog, array &$c): void
    {
        $comp = is_array($s['comp'] ?? null) ? $s['comp'] : [];
        $total = 0;
        $any = false;
        foreach (self::LEVELS as $order => $level) {
            $count = $this->whole($comp[$order]['n'] ?? null, 'competition') ?? 0;
            $reason = $this->nullable($comp[$order]['reason'] ?? null);
            ($count <= 0 || $reason !== null || ! $final) || $this->fail('competition', 'competition_reason');
            $total += $count;
            $any = $any || $count > 0 || $reason !== null;
            ($count > 0 || $reason !== null) && $c['profession_meeting_competitions'][] = ['competition_level' => $level, 'count_value' => $count, 'reason' => $reason, 'sort_order' => $order];
        }
        ! $final || $total === $att || $this->fail('competition', 'total', ['n' => '6']);

        $total = 0;
        foreach ($this->rows($s['seasons'] ?? null, ['n', 'from', 'to', 'reason']) as $i => $row) {
            $count = $this->whole($row['n'], 'season');
            if ($count !== null) {
                ($row['from'] !== '' && $row['to'] !== '' && $row['reason'] !== '' || ! $final) || $this->fail('season', 'season_row');
                $total += $count;
            }
            $c['profession_meeting_seasons'][] = ['season_count' => $count, 'from_period' => $this->nullable($row['from']), 'to_period' => $this->nullable($row['to']), 'reason' => $this->nullable($row['reason']), 'sort_order' => $i + 1];
        }
        ! $final || $total === $att || $this->fail('season', 'total', ['n' => '8']);

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $this->text($s['brands'] ?? '')) ?: []), fn ($l) => $l !== ''));
        (! $final || $lines !== []) || $this->fail('brands', 'answer', ['n' => '10']);
        foreach (array_slice($lines, 0, self::MAX_ROWS) as $i => $line) {
            mb_strlen($line) <= 200 || $this->fail('brands', 'long');
            $c['profession_meeting_brands'][] = ['brand_name' => $line, 'sort_order' => $i + 1];
        }

        foreach ($this->rows($s['equipment'] ?? null, ['spec', 'items']) as $i => $row) {
            ($row['spec'] !== '' && $row['items'] !== '' || ! $final) || $this->fail('equipment', 'equipment_row');
            $c['profession_meeting_equipment'][] = ['specialization' => $row['spec'], 'equipment' => $row['items'], 'sort_order' => $i + 1];
        }
        (! $final || $c['profession_meeting_equipment'] !== []) || $this->fail('equipment', 'answer', ['n' => '12']);

        $total = 0;
        foreach ($this->rows($s['challenges'] ?? null, ['name', 'n', 'solution']) as $i => $row) {
            $count = $this->whole($row['n'], 'challenge');
            ($count === null || $count <= 0 || $row['solution'] !== '' || ! $final) || $this->fail('challenge', 'challenge_solution');
            $total += $count ?? 0;
            $c['profession_meeting_challenges'][] = ['challenge' => $this->nullable($row['name']), 'challenge_count' => $count, 'suggested_solution' => $this->nullable($row['solution']), 'sort_order' => $i + 1];
        }
        ! $final || $total === $att || $this->fail('challenge', 'total', ['n' => '16']);

        $byNumber = $catalog['questions']->keyBy(fn ($q) => (int) $q->number);
        foreach (self::INTEREST_QUESTIONS as $number) {
            $question = $byNumber->get($number);
            if ($question === null) {
                continue;
            }
            foreach ($this->rows($s['part'][$question->id] ?? null, ['project', 'name', 'speciality']) as $i => $row) {
                $c['profession_meeting_participants'][] = ['profession_question_id' => (int) $question->id, 'person_name' => $this->nullable($row['name']), 'speciality' => $this->nullable($row['speciality']), 'notes' => null, 'sort_order' => $i + 1]
                    + $this->links($city, $careerIds, $row);
            }
        }
        $rows = $this->rows($s['attendees'] ?? null, ['project', 'name', 'speciality']);
        foreach ($rows as $i => $row) {
            $c['profession_meeting_attendees'][] = ['attendee_name' => $this->nullable($row['name']), 'speciality' => $this->nullable($row['speciality']), 'sort_order' => $i + 1] + $this->links($city, $careerIds, $row);
        }
        ! $final || count($rows) === $att || $this->fail('attendees', 'attendees_total');
    }

    /**
     * The project (by number, of this city and one of the meeting's professions) and, when exactly one person of it has the typed name, the
     * person. An unknown project number is refused.
     *
     * @return array{project_id: ?int, person_id: ?int}
     */
    private function links(int $city, array $careerIds, array $row): array
    {
        $links = ['project_id' => null, 'person_id' => null];
        if ($row['project'] === '') {
            return $links;
        }
        $parsed = ProjectNumberAllocator::parse($row['project']);
        $parsed !== null || $this->fail('project', 'project_unknown', ['n' => $row['project']]);
        $query = DB::table('projects as p')->join('cities as c', 'c.id', '=', 'p.city_id')->where('p.city_id', $city)->where('p.project_number', $parsed[1])->whereIn('p.career_id', $careerIds)->whereNull('p.deleted_at');
        $parsed[0] !== null && $query->where('c.code', $parsed[0]);
        $project = $query->first(['p.id']);
        $project !== null || $this->fail('project', 'project_unknown', ['n' => $row['project']]);
        $links['project_id'] = (int) $project->id;
        $name = $row['name'];
        $matches = DB::table('project_applicants as a')->join('people as pe', 'pe.id', '=', 'a.person_id')->where('a.project_id', $project->id)->whereNull('a.deleted_at')->get(['pe.id', 'pe.first_name', 'pe.last_name', 'pe.father_name'])
            ->filter(function ($p) use ($name) {
                $first = trim(($p->first_name ?? '').' '.($p->last_name ?? ''));

                return $name !== '' && ($name === $first || $name === trim($first.' '.__('staff.tasks.son_of').' '.($p->father_name ?? '')));
            })->unique('id');
        $matches->count() === 1 && $links['person_id'] = (int) $matches->first()->id;

        return $links;
    }

    // ------------------------------------------------- delete and reopening

    /** Deletes a meeting (soft: the row keeps `deleted_at`, the audit keeps what was removed). A final meeting is reopened first by an administrator. */
    public function delete(WebAccount $account, int $id, string $version): void
    {
        DB::transaction(function () use ($account, $id, $version) {
            $meeting = DB::table('profession_meetings')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_if($meeting === null, 404);
            $city = (int) $meeting->city_id;
            $this->authorize($account, $city, 'profession_meetings.delete');
            abort_unless(hash_equals((string) $meeting->updated_at, $version), 409, 'The meeting changed. Reload before deleting.');
            $meeting->record_state === 'final' && ! $this->access->check($account, $city, 'accounts.manage') && $this->fail('state', 'locked');
            // Audited while the meeting still exists: the audit trail checks that its subject is a real meeting of the city.
            $this->audit->record($account->id, 'profession_meetings.deleted', 'succeeded', $city, subjectType: 'meeting_entity', subjectId: $id);
            DB::table('profession_meetings')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now(), 'edited_by_account_id' => $account->id]);
        });
    }

    /** The administrator reopens a final meeting as a draft. */
    public function reopen(WebAccount $account, int $id): void
    {
        DB::transaction(function () use ($account, $id) {
            $meeting = DB::table('profession_meetings')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_if($meeting === null, 404);
            $city = (int) $meeting->city_id;
            $this->authorize($account, $city, 'accounts.manage');
            $meeting->record_state === 'final' || $this->fail('state', 'not_final');
            DB::table('profession_meetings')->where('id', $id)->update(['record_state' => 'draft', 'updated_at' => now(), 'edited_by_account_id' => $account->id]);
            $this->audit->record($account->id, 'profession_meetings.saved', 'succeeded', $city, subjectType: 'meeting_entity', subjectId: $id, details: [['field' => 'state', 'before' => 'final', 'after' => 'draft']]);
        });
    }

    // -------------------------------------------------------------- report

    /**
     * Statistics and interests of the final meetings (drafts are listed but not counted) in the permitted cities: meetings, invited, attending
     * and absent totals; per profession the totals of every counted/chosen option; and the participants' interests (questions 19-22) with
     * their people. Filters: cities, year, career.
     */
    public function report(WebAccount $account, array $filters): array
    {
        $permitted = $this->cities($account);
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $all = $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $selected = array_values(array_intersect(array_map('intval', $filters['cities'] ?? []), $all));
        $ids = $selected !== [] ? $selected : $all;
        $this->audit->record($account->id, 'profession_meetings.list', 'succeeded');
        $meetings = DB::table('profession_meetings as m')->whereIn('m.city_id', $ids)->whereNull('m.deleted_at')->where('m.record_state', 'final');
        ! empty($filters['year']) && $meetings->whereBetween('m.meeting_date', [sprintf('%04d-01-01', $filters['year']), sprintf('%04d-12-31', $filters['year'])]);
        ! empty($filters['career']) && $meetings->whereExists(fn ($q) => $q->selectRaw('1')->from('profession_meeting_careers as mc')->whereColumn('mc.profession_meeting_id', 'm.id')
            ->whereNull('mc.deleted_at')->where('mc.career_id', (int) $filters['career']));
        $meetingIds = (clone $meetings)->pluck('m.id')->map(fn ($id) => (int) $id)->all();
        $in = $meetingIds ?: [0];
        $totals = (clone $meetings)->selectRaw('COUNT(*) as meetings, COALESCE(SUM(m.invited_count),0) as invited, COALESCE(SUM(m.attendance_count),0) as attending, COALESCE(SUM(m.absence_count),0) as absent')->first();
        $drafts = DB::table('profession_meetings')->whereIn('city_id', $ids)->whereNull('deleted_at')->where('record_state', 'draft')->count();

        $catalog = $this->catalog();
        $answers = DB::table('profession_meeting_option_answers')->whereIn('profession_meeting_id', $in)->whereNull('deleted_at')->where('is_selected', 1)
            ->groupBy('profession_option_id')->selectRaw('profession_option_id, COUNT(*) as meetings, COALESCE(SUM(rank_value),0) as persons')->get()->keyBy('profession_option_id');
        $questions = $catalog['questions']->filter(fn ($q) => in_array($q->answer_type, ['option_number', 'multi_option'], true))->map(function ($q) use ($answers) {
            return (object) ['number' => $q->number, 'label' => $q->label, 'counted' => $q->answer_type === 'option_number', 'options' => $q->options->map(fn ($o) => (object) [
                'label' => $this->name($o), 'meetings' => (int) ($answers[$o->id]->meetings ?? 0), 'persons' => (int) ($answers[$o->id]->persons ?? 0)])];
        })->values();

        $interests = [];
        $byId = $catalog['questions']->keyBy('id');
        $people = DB::table('profession_meeting_participants as pp')->join('profession_meetings as m', 'm.id', '=', 'pp.profession_meeting_id')->join('cities as ci', 'ci.id', '=', 'm.city_id')
            ->leftJoin('projects as p', 'p.id', '=', 'pp.project_id')->whereIn('pp.profession_meeting_id', $in)->whereNull('pp.deleted_at')
            ->orderBy('pp.profession_question_id')->orderBy('m.meeting_date')->orderBy('pp.id')
            ->get(['pp.profession_question_id', 'pp.person_name', 'pp.speciality', 'm.id as meeting', 'm.meeting_date', 'ci.code', 'ci.name_ar as city_ar', 'ci.name_en as city_en', 'p.project_number']);
        foreach ($people->groupBy('profession_question_id') as $questionId => $group) {
            $q = $byId[$questionId] ?? null;
            $q !== null && $interests[] = (object) ['number' => $q->number, 'label' => $q->label, 'count' => $group->count(), 'people' => $group->map(fn ($r) => (object) [
                'name' => $r->person_name ?: '—', 'speciality' => $r->speciality, 'meeting' => (int) $r->meeting, 'date' => Fields::day($r->meeting_date),
                'city' => app()->isLocale('en') ? $r->city_en : $r->city_ar, 'project' => $r->project_number ? ProjectNumberAllocator::display((string) $r->code, (int) $r->project_number) : null])->all()];
        }
        usort($interests, fn ($a, $b) => $a->number <=> $b->number);

        $perCareer = DB::table('profession_meeting_careers as mc')->join('careers as c', 'c.id', '=', 'mc.career_id')->join('profession_meetings as m', 'm.id', '=', 'mc.profession_meeting_id')
            ->whereIn('m.id', $in)->whereNull('mc.deleted_at')->groupBy('c.id', 'c.name_ar', 'c.name_en')->selectRaw('c.name_ar, c.name_en, COUNT(*) as meetings, COALESCE(SUM(m.attendance_count),0) as attending')
            ->orderByDesc('meetings')->get()->map(fn ($r) => (object) ['label' => $this->name($r), 'meetings' => (int) $r->meetings, 'attending' => (int) $r->attending]);

        return ['permitted' => $permitted, 'totals' => $totals, 'drafts' => $drafts, 'questions' => $questions, 'interests' => $interests, 'perCareer' => $perCareer,
            'careers' => DB::table('careers')->where('is_active', 1)->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']),
            'years' => $this->years($all)];
    }
}
