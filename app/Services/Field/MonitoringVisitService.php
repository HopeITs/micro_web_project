<?php

declare(strict_types=1);

namespace App\Services\Field;

use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Finance\Fields;
use App\Services\Finance\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monitoring and evaluation (M&E) visits (M4.12, desktop `V_ME_Other_Form`, `V_ME_Taxi_Form`, `M_and_E.cs`) on the unified schema:
 * `me_visits` (always with `project_id`), `me_visit_answers`, `me_visit_visitors` and the catalogue `me_questions` / `me_answers` /
 * `me_profit_marks` / `me_result_ranges`.
 *
 * A visit belongs to one person of the project (beneficiary or partner) and has a number (1–9, once per person), a date, the net monthly
 * profit and one answer per question of its kind (`o` other projects, `v` vehicles). The result is the sum of the chosen answers' marks plus
 * the mark of the active profit range; its evaluation comes from `me_result_ranges`. Reading needs `applications.view` + `me_visits.view`,
 * writing `me_visits.manage`, deleting `me_visits.delete`. Owner decisions (21 Nov 2026) 2 (a visitor), 4 (soft delete), 5 (Kobo read-only)
 * and 6 (date) apply as for the other visits.
 */
final class MonitoringVisitService
{
    public function __construct(private VisitSupport $support, private AccountAuditTrail $audit) {}

    // ---------------------------------------------------------------- reading

    /** The project's M&E visits (not deleted), by person then number. */
    public function visits(int $projectId): Collection
    {
        return DB::table('me_visits')->where('project_id', $projectId)->whereNull('deleted_at')->orderBy('person_id')->orderByRaw('CAST(visit_number AS UNSIGNED)')->orderBy('id')->get();
    }

    private function answerRows(array $ids): Collection
    {
        return DB::table('me_visit_answers')->whereIn('me_visit_id', $ids ?: [0])->whereNull('deleted_at')->orderBy('id')->get();
    }

    private function visitorRows(array $ids): Collection
    {
        return DB::table('me_visit_visitors')->whereIn('me_visit_id', $ids ?: [0])->whereNull('deleted_at')->orderBy('id')->get(['id', 'me_visit_id', 'account_id', 'mark']);
    }

    public function version(Collection $visits): string
    {
        $ids = $visits->pluck('id')->all();
        $plain = fn (Collection $rows) => $rows->map(fn ($r) => collect((array) $r)->map(fn ($v) => $v === null ? null : (string) $v)->all())->values()->all();

        return hash('sha256', json_encode([$plain($visits), $plain($this->answerRows($ids)), $plain($this->visitorRows($ids))], JSON_THROW_ON_ERROR));
    }

    /** The evaluation name of a result (`me_result_ranges`), or null. */
    public function evaluation(?int $result): ?object
    {
        return $result === null ? null : DB::table('me_result_ranges')->where('is_active', true)->where('min_value', '<=', $result)->where('max_value', '>=', $result)
            ->orderBy('sort_order')->first(['name_ar', 'name_en']);
    }

    /** The project's people with their visits (number, date, result, evaluation, visitors, Kobo mark) and the next number of each. */
    public function people(object $project): Collection
    {
        $visits = $this->visits((int) $project->id);
        $ids = $visits->pluck('id')->map(fn ($id) => (int) $id)->all();
        $kobo = $this->support->koboMeVisits($ids);
        $koboNames = $this->support->koboVisitorNames('me_visits', array_keys($kobo));
        $rows = $this->visitorRows($ids);
        $names = $this->support->names($rows->pluck('account_id')->all());
        $people = $this->support->people($project);
        // A visit kept on the project for someone no longer linked to it still shows (the transfer chose the project for old visits).
        foreach ($visits->pluck('person_id')->unique() as $personId) {
            if (! $people->contains('id', (int) $personId)) {
                $p = DB::table('people')->where('id', $personId)->first(['id', 'first_name', 'last_name', 'father_name', 'mobile']);
                $p && $people->push((object) ['id' => (int) $p->id, 'role' => '', 'mobile' => (string) $p->mobile, 'beneficiary' => false, 'linked' => false,
                    'name' => trim(trim(($p->first_name ?? '').' '.($p->last_name ?? '')).' ابن/ة '.($p->father_name ?? ''))]);
            }
        }

        return $people->map(function ($person) use ($visits, $kobo, $koboNames, $rows, $names) {
            $person->linked ??= true;
            $person->visits = $visits->where('person_id', $person->id)->values()->map(fn ($v) => (object) [
                'id' => (int) $v->id, 'number' => (string) $v->visit_number, 'kind' => (string) $v->kind, 'date' => Fields::day($v->visit_date),
                'result' => $v->result === null ? null : (int) $v->result, 'evaluation' => $this->evaluation($v->result === null ? null : (int) $v->result), 'kobo' => isset($kobo[(int) $v->id]),
                'visitors' => isset($kobo[(int) $v->id]) && $rows->where('me_visit_id', $v->id)->isEmpty() ? ($koboNames[(int) $v->id] ?? [])
                    : $rows->where('me_visit_id', $v->id)->map(fn ($r) => ($names[(int) $r->account_id] ?? '#'.$r->account_id).($r->mark ? ' ('.$r->mark.')' : ''))->values()->all(),
            ]);
            $person->next = $this->next($visits->where('person_id', $person->id));

            return $person;
        });
    }

    /** Desktop: the suggested number is the person's highest + 1. */
    private function next(Collection $visits): int
    {
        return 1 + (int) $visits->map(fn ($v) => (int) $v->visit_number)->max();
    }

    /** The questionnaire of a kind, in the desktop's sections: [{section, questions: [{id, name, answers: [{id, name, mark}]}]}]. */
    public function questionnaire(string $kind, array $given = []): Collection
    {
        $questions = DB::table('me_questions')->where('type', 'like', '%'.$kind)->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', array_keys($given) ?: [0]))
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en', 'type']);
        $answers = DB::table('me_answers')->whereIn('me_question_id', $questions->pluck('id')->all() ?: [0])
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', array_values($given) ?: [0]))->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'me_question_id', 'name_ar', 'name_en', 'mark'])->groupBy('me_question_id');
        $en = app()->getLocale() === 'en';

        return $questions->map(fn ($q) => (object) [
            'id' => (int) $q->id, 'section' => (int) substr((string) $q->type, 0, 1), 'name' => $en && $q->name_en ? $q->name_en : $q->name_ar, 'name_ar' => $q->name_ar,
            'answers' => ($answers->get($q->id) ?? collect())->map(fn ($a) => (object) ['id' => (int) $a->id, 'name' => $en && $a->name_en ? $a->name_en : $a->name_ar, 'name_ar' => $a->name_ar, 'mark' => (int) ($a->mark ?? 0)])->values(),
        ])->filter(fn ($q) => $q->answers->isNotEmpty())->groupBy('section')->map(fn ($items, $section) => (object) ['section' => (int) $section, 'questions' => $items->values()])->values();
    }

    /** A blank form for a person ($visitId null) or a stored visit. */
    public function form(WebAccount $account, int $projectId, ?int $personId, ?int $visitId): array
    {
        $project = $this->support->project($account, $projectId, 'me_visits.view');
        $city = (int) $project->city_id;
        $visits = $this->visits($projectId);
        $visit = $visitId === null ? null : $visits->firstWhere('id', $visitId);
        abort_if($visitId !== null && $visit === null, 404);
        $personId ??= (int) $visit->person_id;
        $person = $this->people($project)->firstWhere('id', $personId);
        abort_if($person === null || ($visit === null && ! $person->linked), 404);
        $class = $this->support->classify($project);
        if ($visit === null) {
            abort_unless($this->creatable($account, $project, $class), 404);
        }
        $kind = $visit !== null ? (string) $visit->kind : ($class['vehicle'] ? 'v' : 'o');
        $given = $visit === null ? [] : $this->answerRows([(int) $visit->id])->keyBy('me_question_id')->all();
        $chosen = $visit === null ? collect() : $this->visitorRows([(int) $visit->id]);
        $kobo = $visit !== null && $this->support->koboMeVisits([(int) $visit->id]) !== [];

        return [
            'project' => $project, 'header' => $this->support->header($project), 'person' => $person, 'visit' => $visit, 'kind' => $kind,
            'sections' => $this->questionnaire($kind, collect($given)->mapWithKeys(fn ($a) => [(int) $a->me_question_id => (int) $a->me_answer_id])->all()),
            'given' => $given, 'visitors' => $chosen->values()->all(), 'visitorOptions' => $this->support->visitorOptions($city, $chosen->pluck('account_id')->all()),
            'next' => $this->next($visits->where('person_id', $personId)), 'kobo' => $kobo,
            'koboVisitors' => $kobo ? ($this->support->koboVisitorNames('me_visits', [(int) $visit->id])[(int) $visit->id] ?? []) : [],
            'evaluation' => $visit === null ? null : $this->evaluation($visit->result === null ? null : (int) $visit->result),
            'canManage' => $this->support->can($account, $city, 'me_visits.manage') && ! $kobo,
            'canDelete' => $visit !== null && ! $kobo && $this->support->can($account, $city, 'me_visits.delete'),
            'version' => $this->version($visits),
        ];
    }

    /** New M&E visits: me_visits.manage, a funded project that is not a rehabilitation grant. */
    public function creatable(WebAccount $account, object $project, array $class): bool
    {
        return $this->support->can($account, (int) $project->city_id, 'me_visits.manage') && ! $class['rehab'] && $this->support->funded($this->support->current((int) $project->id));
    }

    /** The other people of the project who have no visit of this number yet (the desktop's `Partners_MessageBox`). */
    public function partnersWithout(object $project, int $personId, string $number): Collection
    {
        $visits = $this->visits((int) $project->id);

        return $this->support->people($project)->filter(fn ($p) => $p->id !== $personId && ! $visits->contains(fn ($v) => (int) $v->person_id === $p->id && (string) $v->visit_number === $number))->values();
    }

    // ---------------------------------------------------------------- writing

    /**
     * Records or edits a visit. $input: number, date, profit, answer_1, indicators, notes, a[questionId] (answer id), n[questionId] (note),
     * visitors: list of {account, mark}. Returns the visit id.
     */
    public function save(WebAccount $account, int $projectId, int $personId, ?int $visitId, array $input, string $version): int
    {
        $project = $this->support->project($account, $projectId, 'me_visits.view');
        $this->support->require($account, (int) $project->city_id, 'me_visits.manage');

        return DB::transaction(function () use ($account, $projectId, $personId, $visitId, $input, $version) {
            $project = $this->support->lock($projectId);
            $city = (int) $project->city_id;
            $visits = $this->visits($projectId);
            hash_equals($this->version($visits), $version) || $this->support->fail('version', 'stale');
            $visit = $visitId === null ? null : $visits->firstWhere('id', $visitId);
            $visitId !== null && $visit === null && abort(404);
            $class = $this->support->classify($project);
            if ($visit === null) {
                $this->support->people($project)->contains('id', $personId) || $this->support->fail('person', 'person');
                $class['rehab'] && $this->support->fail('visit', 'rehab_me');
                $this->support->funded($this->support->current($projectId)) || $this->support->fail('visit', 'not_funded');
            } else {
                $personId = (int) $visit->person_id;
                $this->support->koboMeVisits([(int) $visit->id]) === [] || $this->support->fail('visit', 'kobo');
            }
            $kind = $visit !== null ? (string) $visit->kind : ($class['vehicle'] ? 'v' : 'o');
            $number = trim(Fields::text((string) ($input['number'] ?? '')));
            preg_match('/\A[1-9]\z/', $number) === 1 || $this->support->fail('number', 'number');
            $visits->contains(fn ($v) => (int) $v->person_id === $personId && (string) $v->visit_number === $number && (int) $v->id !== (int) ($visit->id ?? 0))
                && $this->support->fail('number', 'number_used', ['number' => $number]);
            $date = $this->support->date($input['date'] ?? null, $projectId);
            $profit = Money::normalize(trim(Fields::text((string) ($input['profit'] ?? ''))));
            $profit !== '' || $this->support->fail('profit', 'profit_required');
            preg_match('/\A-?\d{1,13}(?:\.\d{1,2})?\z/', $profit) === 1 || $this->support->fail('profit', 'profit');
            $profit = Money::canonical($profit) ?? '0';
            $range = DB::table('me_profit_marks')->where('is_active', true)->where('min_value', '<=', $profit)->where('max_value', '>=', $profit)->orderBy('sort_order')->first(['mark', 'name_ar']);
            $range === null && $this->support->fail('profit', 'profit_range');

            $stored = $visit === null ? collect() : $this->answerRows([(int) $visit->id])->keyBy('me_question_id');
            $sections = $this->questionnaire($kind, $stored->mapWithKeys(fn ($a) => [(int) $a->me_question_id => (int) $a->me_answer_id])->all());
            $chosen = [];
            $sum = 0;
            foreach ($sections->flatMap(fn ($s) => $s->questions) as $question) {
                $raw = $input['a'][$question->id] ?? null;
                Fields::blank($raw) && $this->support->fail('a.'.$question->id, 'question_required');
                $answer = $question->answers->firstWhere('id', (int) $raw);
                $answer === null && $this->support->fail('a.'.$question->id, 'choice');
                $note = trim(Fields::text($input['n'][$question->id] ?? ''));
                mb_strlen($note) <= 65535 || $this->support->fail('n.'.$question->id, 'long');
                $chosen[$question->id] = ['answer' => $answer, 'note' => $note === '' ? null : $note, 'question' => $question];
                $sum += $answer->mark;
            }
            $result = $sum + (int) $range->mark;
            $kept = $visit === null ? collect() : $this->visitorRows([(int) $visit->id]);
            $visitors = $this->visitors($city, (array) ($input['visitors'] ?? []), $kept);
            $text = function (string $key) use ($input): ?string {
                $value = trim(Fields::text($input[$key] ?? ''));
                mb_strlen($value) <= 65535 || $this->support->fail($key, 'long');

                return $value === '' ? null : $value;
            };
            $columns = ['visit_number' => $number, 'visit_date' => $date, 'profit' => $profit, 'answer_1' => $text('answer_1'), 'indicators' => $text('indicators'), 'notes' => $text('notes'), 'result' => $result];
            $actor = mb_substr((string) $account->username, 0, 100);
            $now = now();
            if ($visit === null) {
                $visitId = (int) DB::table('me_visits')->insertGetId($columns + ['city_id' => $city, 'person_id' => $personId, 'project_id' => $projectId, 'kind' => $kind, 'type' => null,
                    'created_by_name' => $actor, 'created_at' => $now, 'updated_at' => $now]);
                $details = AccountAuditTrail::changes([], $columns) ?? [];
            } else {
                $visitId = (int) $visit->id;
                $before = ['visit_number' => (string) $visit->visit_number, 'visit_date' => Fields::day($visit->visit_date), 'profit' => Money::canonical($visit->profit), 'answer_1' => $visit->answer_1,
                    'indicators' => $visit->indicators, 'notes' => $visit->notes, 'result' => $visit->result === null ? null : (int) $visit->result];
                $details = AccountAuditTrail::changes($before, $columns) ?? [];
            }
            $details = [...$details, ...$this->writeAnswers($city, $visitId, $chosen, $stored), ...$this->writeVisitors($city, $visitId, $visitors, $kept)];
            if ($visit !== null) {
                if ($details === []) {
                    return $visitId;
                }
                DB::table('me_visits')->where('id', $visitId)->update($columns + ['edited_by_name' => $actor, 'updated_at' => $now]);
            } else {
                $details = [...$details, ...$this->created($project, $personId, $kind, $number)];
            }
            $person = $this->support->people($project)->firstWhere('id', $personId);
            $details = [['field' => 'visit', 'before' => null, 'after' => 'M'.$number.' · '.($person->name ?? '#'.$personId)], ...$details];
            $this->audit->record($account->id, 'me_visits.saved', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId, details: $details);

            return $visitId;
        });
    }

    /**
     * Up to two visitors (an older visit that already has more keeps that many), each with a mark (ضعيف / وسط / جيد; an unchanged older stored mark is kept).
     *
     * @return list<array{account: int, mark: ?string}>
     */
    private function visitors(int $city, array $input, Collection $kept): array
    {
        $ids = [];
        $marks = [];
        foreach (array_values($input) as $index => $row) {
            $row = is_array($row) ? $row : ['account' => $row];
            if (Fields::blank($row['account'] ?? null)) {
                continue;
            }
            $ids[] = $row['account'];
            $marks[] = trim(Fields::text($row['mark'] ?? ''));
        }
        $ids = $this->support->visitors($city, $ids, max(VisitKinds::MAX_ME_VISITORS, $kept->count()), $kept->pluck('account_id')->map(fn ($id) => (int) $id)->all());
        $out = [];
        foreach ($ids as $i => $id) {
            $mark = $marks[$i];
            $old = $kept->firstWhere('account_id', $id);
            if (! in_array($mark, VisitKinds::MARKS, true) && ! ($old !== null && (string) $old->mark === $mark)) {
                $this->support->fail('visitors', 'mark');
            }
            $out[] = ['account' => $id, 'mark' => $mark === '' ? null : $mark];
        }

        return $out;
    }

    private function writeAnswers(int $city, int $visitId, array $chosen, Collection $stored): array
    {
        $details = [];
        $now = now();
        foreach ($chosen as $questionId => $item) {
            $old = $stored->get($questionId);
            if ($old !== null && (int) $old->me_answer_id === $item['answer']->id && (($old->notes ?? '') === '' ? null : $old->notes) === $item['note']) {
                continue;
            }
            if ($old === null) {
                DB::table('me_visit_answers')->insert(['city_id' => $city, 'me_visit_id' => $visitId, 'me_question_id' => $questionId, 'me_answer_id' => $item['answer']->id,
                    'notes' => $item['note'], 'created_at' => $now, 'updated_at' => $now]);
            } else {
                DB::table('me_visit_answers')->where('id', $old->id)->update(['me_answer_id' => $item['answer']->id, 'notes' => $item['note'], 'updated_at' => $now]);
            }
            $oldName = $old === null ? null : DB::table('me_answers')->where('id', $old->me_answer_id)->value('name_ar');
            $label = $item['question']->name_ar.': ';
            $details[] = ['field' => 'answer', 'before' => $old === null ? null : $label.trim(($oldName ?? '—').($old->notes ? ' · '.$old->notes : '')),
                'after' => $label.$item['answer']->name_ar.($item['note'] ? ' · '.$item['note'] : '')];
        }

        return $details;
    }

    private function writeVisitors(int $city, int $visitId, array $visitors, Collection $kept): array
    {
        $describe = function (array $rows): ?string {
            $names = $this->support->names(array_column($rows, 'account'));

            return $rows === [] ? null : implode('، ', array_map(fn ($r) => ($names[$r['account']] ?? '#'.$r['account']).($r['mark'] ? ' ('.$r['mark'].')' : ''), $rows));
        };
        $before = $kept->map(fn ($r) => ['account' => (int) $r->account_id, 'mark' => $r->mark === '' ? null : $r->mark])->values()->all();
        if ($before === $visitors) {
            return [];
        }
        $now = now();
        foreach ($kept as $row) {
            $wanted = collect($visitors)->firstWhere('account', (int) $row->account_id);
            if ($wanted === null) {
                DB::table('me_visit_visitors')->where('id', $row->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            } elseif ($wanted['mark'] !== ($row->mark === '' ? null : $row->mark)) {
                DB::table('me_visit_visitors')->where('id', $row->id)->update(['mark' => $wanted['mark'], 'updated_at' => $now]);
            }
        }
        foreach ($visitors as $row) {
            if ($kept->firstWhere('account_id', $row['account']) === null) {
                DB::table('me_visit_visitors')->insert(['city_id' => $city, 'me_visit_id' => $visitId, 'account_id' => $row['account'], 'mark' => $row['mark'], 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        return [['field' => 'visitors', 'before' => $describe($before), 'after' => $describe($visitors)]];
    }

    /** The notice texts a visit of this number settles: M{n}, MFinal for number 4 (the schedule's last visit), and the Solar variants. */
    private function bodies(string $kind, string $number): array
    {
        $letter = strtoupper($kind);
        $labels = ['M'.$number, ...($number === '4' ? ['MFinal'] : [])];
        $out = [];
        foreach ($labels as $label) {
            $out[] = 'Visit '.$label.'-'.$letter;
            $out[] = 'Visit '.$label.'-'.$letter.' (Solar)';
        }

        return $out;
    }

    private function created(object $project, int $personId, string $kind, string $number): array
    {
        $details = [];
        $task = VisitKinds::ME_TASKS[(int) $number] ?? null;
        if ($task !== null && $this->support->task($project, $task, true)) {
            $details[] = ['field' => 'task', 'before' => null, 'after' => $task];
        }
        $person = $this->support->people($project)->firstWhere('id', $personId);
        $seen = $this->support->notices($project, $this->bodies($kind, $number), 1, $person->name ?? null);
        if ($seen > 0) {
            $details[] = ['field' => 'notices_seen', 'before' => null, 'after' => $seen];
        }

        return $details;
    }

    /** Soft-deletes the visit with its answers and visitors; unticks the number's task when no other visit of that number remains; the notice is unseen again. */
    public function delete(WebAccount $account, int $projectId, int $visitId, string $version): void
    {
        $project = $this->support->project($account, $projectId, 'me_visits.view');
        $this->support->require($account, (int) $project->city_id, 'me_visits.delete');
        DB::transaction(function () use ($account, $projectId, $visitId, $version) {
            $project = $this->support->lock($projectId);
            $visits = $this->visits($projectId);
            hash_equals($this->version($visits), $version) || $this->support->fail('version', 'stale');
            $visit = $visits->firstWhere('id', $visitId);
            $visit === null && abort(404);
            $this->support->koboMeVisits([(int) $visit->id]) === [] || $this->support->fail('visit', 'kobo');
            $now = now();
            DB::table('me_visit_answers')->where('me_visit_id', $visitId)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
            DB::table('me_visit_visitors')->where('me_visit_id', $visitId)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
            DB::table('me_visits')->where('id', $visitId)->update(['deleted_at' => $now, 'updated_at' => $now]);
            $number = (string) $visit->visit_number;
            $person = $this->support->people($project)->firstWhere('id', (int) $visit->person_id);
            $details = [['field' => 'visit', 'before' => 'M'.$number.' · '.($person->name ?? '#'.$visit->person_id).' · '.Fields::day($visit->visit_date), 'after' => null]];
            $task = VisitKinds::ME_TASKS[(int) $number] ?? null;
            $others = $visits->contains(fn ($v) => (int) $v->id !== $visitId && (string) $v->visit_number === $number);
            if ($task !== null && ! $others && $this->support->task($project, $task, false)) {
                $details[] = ['field' => 'task', 'before' => $task, 'after' => null];
            }
            $unseen = $this->support->notices($project, $this->bodies((string) $visit->kind, $number), 0, $person->name ?? null);
            if ($unseen > 0) {
                $details[] = ['field' => 'notices_unseen', 'before' => null, 'after' => $unseen];
            }
            $this->audit->record($account->id, 'me_visits.deleted', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId, details: $details);
        });
    }
}
