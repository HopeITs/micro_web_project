<?php

declare(strict_types=1);

namespace App\Services\Field;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Finance\Fields;
use App\Services\Finance\Money;
use App\Services\ProjectNumberAllocator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The visit results report (M4.12, owner decision 8; desktop `Visit_Results_Form`, procedures `count_mevisit_results` and
 * `beneficiaries_me_answers`) over every visit, the ones written by Kobo included: the count of each answer per question, and the
 * beneficiaries' answers (one row per visit). Filters: dates, visit (M&E number or all M&E, openings, closings), kind (vehicles / other),
 * project type, category, sub-category, career, only the last M&E visit of each person, cities. Reading needs `applications.view`,
 * `visits.view` and `me_visits.view` in a city; the export also `reports.export`. The Kobo-only columns come with M4.14.
 */
final class VisitResultsService
{
    public const PER_PAGE = 50;

    public const VISITS = ['M', 'M1', 'M2', 'M3', 'M4', 'M5', 'opening', 'closing'];

    public const VIEWS = ['counts', 'answers'];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit, private MonitoringVisitService $me) {}

    public function cities(WebAccount $account): Collection
    {
        return $this->access->citiesWithAll($account, ['applications.view', 'visits.view', 'me_visits.view']);
    }

    private function permitted(WebAccount $account, bool $export): Collection
    {
        $permitted = $this->cities($account);
        if ($export) {
            $permitted = $permitted->filter(fn ($city) => $this->access->check($account, (int) $city->id, 'reports.export'))->values();
        }
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }

        return $permitted;
    }

    public function opened(WebAccount $account): void
    {
        $this->permitted($account, false);
        $this->audit->record($account->id, 'visits.results', 'succeeded');
    }

    /** @return array<string, mixed> the filters, cleaned (view, visit, kind, from, to, type, category, subcategory, career, last, cities) */
    public static function clean(array $input): array
    {
        $int = fn ($v) => ($i = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false ? null : $i;
        $date = fn ($v) => is_string($v) && Fields::isDate($v) ? $v : null;

        return [
            'view' => in_array($input['view'] ?? '', self::VIEWS, true) ? $input['view'] : 'counts',
            'visit' => in_array($input['visit'] ?? '', self::VISITS, true) ? $input['visit'] : 'M',
            'kind' => in_array($input['kind'] ?? '', ['o', 'v'], true) ? $input['kind'] : 'o',
            'from' => $date($input['from'] ?? null), 'to' => $date($input['to'] ?? null),
            'type' => $int($input['type'] ?? null), 'category' => $int($input['category'] ?? null), 'subcategory' => $int($input['subcategory'] ?? null), 'career' => $int($input['career'] ?? null),
            'last' => in_array($input['last'] ?? false, [true, 1, '1', 'on', 'true'], true),
            'cities' => array_values(array_filter(array_map('intval', (array) ($input['cities'] ?? [])))),
        ];
    }

    private function ids(Collection $permitted, array $filters): array
    {
        $all = $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $selected = array_values(array_intersect($filters['cities'], $all));

        return $selected !== [] ? $selected : $all;
    }

    private function me(array $filters): bool
    {
        return str_starts_with($filters['visit'], 'M');
    }

    /** The filtered visits (me_visits m, or visits v) joined to their project p. */
    private function base(array $cities, array $filters): Builder
    {
        $me = $this->me($filters);
        $query = $me
            ? DB::table('me_visits as m')->join('projects as p', 'p.id', '=', 'm.project_id')->whereNull('m.deleted_at')->where('m.kind', $filters['kind'])
            : DB::table('visits as m')->join('projects as p', 'p.id', '=', 'm.project_id')->whereNull('m.deleted_at')
                ->whereIn('m.kind', $filters['visit'] === 'opening' ? [strtoupper($filters['kind']).'-op'] : [strtoupper($filters['kind']).'-cl', ...($filters['kind'] === 'o' ? [VisitKinds::REHAB] : [])]);
        $query->whereIn('m.city_id', $cities)->whereNull('p.deleted_at');
        if ($me && preg_match('/\AM([1-5])\z/', $filters['visit'], $n) === 1) {
            $query->where('m.visit_number', $n[1]);
        }
        $filters['from'] && $query->where('m.visit_date', '>=', $filters['from']);
        $filters['to'] && $query->where('m.visit_date', '<=', $filters['to']);
        foreach (['type' => 'project_type_id', 'category' => 'project_category_id', 'subcategory' => 'project_subcategory_id', 'career' => 'career_id'] as $key => $column) {
            $filters[$key] && $query->where('p.'.$column, $filters[$key]);
        }

        return $query;
    }

    /** M&E "last visit per beneficiary": the highest number of each person among the filtered visits (desktop `onlyLastVisit`). */
    private function visitIds(array $cities, array $filters): ?array
    {
        if (! $this->me($filters) || ! $filters['last']) {
            return null;
        }
        $rows = $this->base($cities, $filters)->get(['m.id', 'm.person_id', 'm.visit_number', 'm.visit_date']);

        return $rows->groupBy('person_id')->map(fn ($g) => $g->sortByDesc(fn ($r) => sprintf('%05d|%s|%010d', (int) $r->visit_number, (string) $r->visit_date, (int) $r->id))->first()->id)
            ->map(fn ($id) => (int) $id)->values()->all();
    }

    private function filtered(array $cities, array $filters): Builder
    {
        $query = $this->base($cities, $filters);
        $ids = $this->visitIds($cities, $filters);
        if ($ids !== null) {
            $query->whereIn('m.id', $ids ?: [0]);
        }

        return $query;
    }

    public function report(WebAccount $account, array $filters): array
    {
        $permitted = $this->permitted($account, false);
        $cities = $this->ids($permitted, $filters);
        $out = ['cityOptions' => $permitted, 'canExport' => $permitted->contains(fn ($c) => $this->access->check($account, (int) $c->id, 'reports.export')),
            'types' => DB::table('project_types')->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']),
            'categories' => DB::table('project_categories')->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']),
            'subcategories' => DB::table('project_subcategories')->when($filters['category'], fn ($q) => $q->where('project_category_id', $filters['category']))->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']),
            'careers' => $filters['subcategory'] ? DB::table('careers')->where('project_subcategory_id', $filters['subcategory'])->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']) : collect(),
            'total' => (clone $this->filtered($cities, $filters))->count('m.id')];
        if ($filters['view'] === 'counts') {
            return $out + ['counts' => $this->counts($cities, $filters)];
        }
        $rows = $this->filtered($cities, $filters)->orderBy('m.visit_date')->orderBy('m.id')->select(['m.*', 'p.project_number', 'p.name as project_name'])->paginate(self::PER_PAGE);
        [$columns, $mapped] = $this->answers($rows->getCollection(), $filters);
        $rows->setCollection($mapped);

        return $out + ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Count of each answer per question: M&E by catalogue answer, opening/closing by stored choice of each slot.
     *
     * @return Collection<int, object{question: string, answers: list<object{name: string, count: int}>}>
     */
    private function counts(array $cities, array $filters): Collection
    {
        $visits = $this->filtered($cities, $filters)->select('m.id');
        if ($this->me($filters)) {
            $counts = DB::table('me_visit_answers as a')->whereIn('a.me_visit_id', $visits)->whereNull('a.deleted_at')->whereNotNull('a.me_answer_id')
                ->groupBy('a.me_question_id', 'a.me_answer_id')->select(['a.me_question_id', 'a.me_answer_id', DB::raw('COUNT(*) as total')])->get()
                ->groupBy('me_question_id');

            return $this->me->questionnaire($filters['kind'])->flatMap(fn ($s) => $s->questions)->map(fn ($q) => (object) [
                'question' => $q->name, 'answers' => $q->answers->map(fn ($a) => (object) ['name' => $a->name, 'count' => (int) (($counts[$q->id] ?? collect())->firstWhere('me_answer_id', $a->id)->total ?? 0)])->all(),
            ])->values();
        }
        $kind = $filters['visit'] === 'opening' ? strtoupper($filters['kind']).'-op' : strtoupper($filters['kind']).'-cl';
        $definition = VisitKinds::definition($kind);
        $counts = DB::table('visit_answers as a')->whereIn('a.visit_id', $visits)->whereNull('a.deleted_at')->whereIn('a.form', ['visit', 'office_monitoring'])
            ->groupBy('a.form', 'a.question_number', 'a.answer_value')->select(['a.form', 'a.question_number', 'a.answer_value', DB::raw('COUNT(*) as total')])->get();
        $out = collect();
        foreach ($definition['questions'] as $slot => $domain) {
            if ($domain === null) {
                continue;
            }
            $out->push((object) ['question' => __('visits.question', ['n' => VisitKinds::questionNumber($kind, $slot)]), 'answers' => collect($domain)->map(fn ($key, $value) => (object) [
                'name' => __('visits.choice.'.$key), 'count' => (int) ($counts->first(fn ($c) => $c->form === 'visit' && (int) $c->question_number === $slot && (int) $c->answer_value === $value)->total ?? 0)])->values()->all()]);
        }
        for ($slot = 1; $slot <= $definition['office']; $slot++) {
            $out->push((object) ['question' => __('visits.office.'.$definition['office_labels'].'.'.$slot), 'answers' => collect(VisitKinds::RATING)->map(fn ($key, $value) => (object) [
                'name' => __('visits.choice.'.$key), 'count' => (int) ($counts->first(fn ($c) => $c->form === 'office_monitoring' && (int) $c->question_number === $slot && (int) $c->answer_value === $value)->total ?? 0)])->values()->all()]);
        }

        return $out;
    }

    /** Columns and rows of the beneficiaries' answers for a page (or chunk) of visits. */
    private function answers(Collection $visits, array $filters): array
    {
        $ids = $visits->pluck('id')->map(fn ($id) => (int) $id)->all();
        $codes = DB::table('cities')->pluck('code', 'id');
        $base = ['city', 'number', 'beneficiary', 'date'];
        if ($this->me($filters)) {
            $questions = $this->me->questionnaire($filters['kind'])->flatMap(fn ($s) => $s->questions)->values();
            $people = DB::table('people')->whereIn('id', $visits->pluck('person_id')->unique()->all() ?: [0])->get(['id', 'first_name', 'last_name', 'father_name'])->keyBy('id');
            $given = DB::table('me_visit_answers as a')->leftJoin('me_answers as o', 'o.id', '=', 'a.me_answer_id')->whereIn('a.me_visit_id', $ids ?: [0])->whereNull('a.deleted_at')
                ->get(['a.me_visit_id', 'a.me_question_id', 'o.name_ar', 'o.name_en', 'a.notes'])->groupBy('me_visit_id');
            $en = app()->getLocale() === 'en';
            $columns = [...array_map(fn ($k) => __('visits.results.columns.'.$k), [...$base, 'visit_number', 'profit', 'result', 'evaluation']), ...$questions->pluck('name')->all(), __('visits.me.answer_1')];
            $rows = $visits->map(function ($v) use ($codes, $people, $given, $questions, $en) {
                $p = $people[$v->person_id] ?? null;
                $evaluation = $this->me->evaluation($v->result === null ? null : (int) $v->result);
                $answers = ($given[$v->id] ?? collect())->keyBy('me_question_id');

                return [(string) ($codes[$v->city_id] ?? ''), ProjectNumberAllocator::display((string) ($codes[$v->city_id] ?? ''), (int) $v->project_number),
                    $p === null ? '' : trim(trim(($p->first_name ?? '').' '.($p->last_name ?? '')).' ابن/ة '.($p->father_name ?? '')), Fields::day($v->visit_date),
                    (string) $v->visit_number, Money::canonical($v->profit) ?? '', $v->result === null ? '' : (int) $v->result, $evaluation ? ($en && $evaluation->name_en ? $evaluation->name_en : $evaluation->name_ar) : '',
                    ...$questions->map(fn ($q) => ($a = $answers->get($q->id)) === null ? '' : trim(($en && $a->name_en ? $a->name_en : (string) $a->name_ar).($a->notes ? ' · '.$a->notes : '')))->all(),
                    (string) ($v->answer_1 ?? '')];
            });

            return [$columns, $rows];
        }
        $kind = $filters['visit'] === 'opening' ? strtoupper($filters['kind']).'-op' : strtoupper($filters['kind']).'-cl';
        $definition = VisitKinds::definition($kind);
        $names = DB::table('project_applicants as a')->join('people as pe', 'pe.id', '=', 'a.person_id')->whereIn('a.project_id', $visits->pluck('project_id')->unique()->all() ?: [0])
            ->whereNull('a.deleted_at')->orderByRaw("CASE WHEN a.role = 'مستفيد' THEN 0 ELSE 1 END")->orderBy('a.id')->get(['a.project_id', 'pe.first_name', 'pe.last_name', 'pe.father_name'])
            ->groupBy('project_id')->map(fn ($g) => trim(trim(($g->first()->first_name ?? '').' '.($g->first()->last_name ?? '')).' ابن/ة '.($g->first()->father_name ?? '')));
        $given = DB::table('visit_answers')->whereIn('visit_id', $ids ?: [0])->whereNull('deleted_at')->get()->groupBy('visit_id');
        $columns = array_map(fn ($k) => __('visits.results.columns.'.$k), $base);
        foreach (array_keys($definition['questions']) as $slot) {
            $columns[] = __('visits.question', ['n' => VisitKinds::questionNumber($kind, $slot)]);
        }
        for ($slot = 1; $slot <= $definition['office']; $slot++) {
            $columns[] = __('visits.office.'.$definition['office_labels'].'.'.$slot);
        }
        $columns[] = $definition['phase'] === 'closing' ? __('visits.question', ['n' => 8]) : __('visits.fields.indicators');
        if ($definition['comments']) {
            $columns[] = __('visits.fields.office_comments');
        }
        $label = function (?object $a, array $domain): string {
            if ($a === null) {
                return '';
            }
            $value = $a->answer_value === null ? VisitKinds::UNSET : (int) $a->answer_value;

            return trim((isset($domain[$value]) ? __('visits.choice.'.$domain[$value]) : '').($a->answer_text ? ' · '.$a->answer_text : ''), ' ·');
        };
        $rows = $visits->map(function ($v) use ($codes, $names, $given, $definition, $label) {
            $answers = $given[$v->id] ?? collect();
            $row = [(string) ($codes[$v->city_id] ?? ''), ProjectNumberAllocator::display((string) ($codes[$v->city_id] ?? ''), (int) $v->project_number), $names[$v->project_id] ?? '', Fields::day($v->visit_date)];
            foreach ($definition['questions'] as $slot => $domain) {
                $row[] = $label($answers->first(fn ($a) => $a->form === 'visit' && (int) $a->question_number === $slot), $domain ?? []);
            }
            for ($slot = 1; $slot <= $definition['office']; $slot++) {
                $row[] = $label($answers->first(fn ($a) => $a->form === 'office_monitoring' && (int) $a->question_number === $slot), VisitKinds::RATING);
            }
            $row[] = (string) ($v->indicators ?? '');
            if ($definition['comments']) {
                $row[] = (string) ($v->office_comments ?? '');
            }

            return $row;
        });

        return [$columns, $rows];
    }

    /** @return array{0: list<string>, 1: iterable<list<mixed>>} headings and rows of the export, audited before the first byte */
    public function export(WebAccount $account, array $filters): array
    {
        $permitted = $this->permitted($account, true);
        $cities = $this->ids($permitted, $filters);
        $this->audit->record($account->id, 'reports.exported', 'succeeded');
        if ($filters['view'] === 'counts') {
            $rows = [];
            foreach ($this->counts($cities, $filters) as $q) {
                foreach ($q->answers as $a) {
                    $rows[] = [$q->question, $a->name, $a->count];
                }
            }

            return [[__('visits.results.columns.question'), __('visits.results.columns.answer'), __('visits.results.columns.count')], $rows];
        }
        $query = $this->filtered($cities, $filters)->orderBy('m.visit_date')->orderBy('m.id')->select(['m.*', 'p.project_number', 'p.name as project_name']);
        [$columns] = $this->answers(collect(), $filters);

        return [$columns, (function () use ($query, $filters) {
            foreach ($query->lazy(500)->chunk(500) as $chunk) {
                foreach ($this->answers(collect($chunk->all()), $filters)[1] as $row) {
                    yield $row;
                }
            }
        })()];
    }
}
