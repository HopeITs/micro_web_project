<?php

declare(strict_types=1);

namespace App\Services\Kobo;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\People\PeopleService;
use App\Services\ProjectNumberAllocator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The two Kobo modes of the desktop Visit Results (docs/kobo-visit-results.md, M4.14): answer counts per question across every selected
 * form, and one row of answers per visit. Only confirmed submissions count (imported or updated, not deleted in Kobo, linked to a
 * project). Questions of different forms share one column when their wording (normalised) and kind match; generic follow-ups such as
 * "لماذا؟" are told apart by the question they follow. Choice codes are read with each form's own labels, so equal labels count together.
 * Identification, companion and vehicle groups, the visit number and the visit date are left out, as on the desktop.
 */
final class KoboResultsService
{
    /** Follow-up wordings that only mean something next to their parent question. */
    private const GENERIC = ['لماذا', 'اذكر', 'حدد', 'غير ذلك', 'ملاحظات', 'السبب', 'كيف', 'ما هو', 'ما هي'];

    public function __construct(private KoboReadService $kobo) {}

    /**
     * Confirmed submissions under the filters, with their context and decoded answers.
     *
     * @param  array{cities?: list<int>, form?: string, from?: ?string, to?: ?string, q?: string, last?: bool}  $filters
     */
    private function submissions(WebAccount $account, array $filters): Collection
    {
        $cities = $this->kobo->cityIds($account);
        $cities !== [] || abort(403);
        $chosen = array_values(array_intersect(array_map('intval', $filters['cities'] ?? []), $cities)) ?: $cities;
        $records = DB::table('kobo_monitoring_records')->whereNull('deleted_at')
            ->selectRaw('kobo_submission_id, MIN(project_id) as project_id, MIN(person_id) as person_id, MIN(visit_number) as visit_number, MIN(visit_date) as visit_date')->groupBy('kobo_submission_id');
        $query = DB::table('kobo_submissions as s')->join('cities as c', 'c.id', '=', 's.city_id')
            ->leftJoinSub($records, 'mr', 'mr.kobo_submission_id', '=', 's.id')
            ->leftJoin('visits as v', fn ($j) => $j->on('v.id', '=', 's.local_record_id')->where('s.local_table', '=', 'visits'))
            ->join('projects as p', 'p.id', '=', DB::raw('COALESCE(mr.project_id, v.project_id)'))
            ->leftJoin('people as pe', 'pe.id', '=', 'mr.person_id')
            ->leftJoin('project_subcategories as sc', 'sc.id', '=', 'p.project_subcategory_id')->leftJoin('careers as cr', 'cr.id', '=', 'p.career_id')
            ->whereNull('s.deleted_at')->where('s.is_deleted_in_kobo', false)->whereIn('s.sync_status', KoboReadService::STATUSES['imported'])->whereIn('s.city_id', $chosen);
        if (($filters['form'] ?? '') !== '') {
            $query->where('s.form_uid', $filters['form']);
        }
        $date = DB::raw('COALESCE(mr.visit_date, v.visit_date, DATE(s.submitted_at))');
        ! empty($filters['from']) && $query->where($date, '>=', $filters['from']);
        ! empty($filters['to']) && $query->where($date, '<=', $filters['to']);
        $number = ProjectNumberAllocator::parse((string) ($filters['q'] ?? ''));
        if ($number !== null) {
            $query->where('p.project_number', $number[1])->when($number[0], fn ($q) => $q->where('c.code', $number[0]));
        }
        $rows = $query->orderBy('p.id')->orderBy($date)->orderBy('s.id')->get([
            's.id', 's.form_uid', 's.raw_json', 'c.code as city_code', 'p.id as project_id', 'p.project_number', 'pe.id as person_id', 'pe.first_name', 'pe.father_name', 'pe.last_name',
            'sc.name_ar as subcategory', 'cr.name_ar as career', 'mr.visit_number', DB::raw('COALESCE(mr.visit_date, v.visit_date, DATE(s.submitted_at)) as visit_day'),
        ]);
        if ($filters['last'] ?? false) {
            // The latest visit per project and person across every selected form; the source id breaks ties on the same day.
            $rows = $rows->groupBy(fn ($r) => $r->project_id.'|'.($r->person_id ?? ''))->map(fn ($g) => $g->sortBy([['visit_day', 'desc'], ['id', 'desc']])->first())->values();
        }

        return $rows->map(function ($r) {
            $r->raw = json_decode((string) $r->raw_json, true) ?: [];
            unset($r->raw_json);

            return $r;
        });
    }

    /** The shared column a field belongs to: kind + normalised wording (with its parent question for generic follow-ups). */
    private function key(array $field): array
    {
        $kind = KoboCatalog::kind($field);
        $kind = in_array($kind, ['select_one', 'select_multiple'], true) ? $kind : 'value';
        $label = KoboCatalog::normalize((string) $field['label']);
        $generic = mb_strlen($label) <= 6 || in_array($label, self::GENERIC, true);
        $context = KoboCatalog::normalize((string) ($field['context'] ?? $field['section'] ?? ''));
        $title = $generic && $context !== '' ? $context.' — '.$label : $label;
        if ($field['repeat'] ?? false) {
            $title = KoboCatalog::normalize((string) ($field['section'] ?? '')).' — '.$title;
        }

        return [$kind.'|'.$title, $title, $kind];
    }

    /**
     * Answer counts per choice question: each option with its count, the answered and blank totals (blank = the form asks it, no answer).
     *
     * @return array{submissions: int, questions: list<array{title: string, multiple: bool, options: list<array{label: string, count: int}>, answered: int, blank: int}>}
     */
    public function counts(WebAccount $account, array $filters): array
    {
        $rows = $this->submissions($account, $filters);
        $questions = [];
        foreach ($rows as $row) {
            $seen = [];
            foreach (KoboCatalog::fields((string) $row->form_uid) as $path => $field) {
                [$key, $title, $kind] = $this->key($field);
                if ($kind === 'value' || ! KoboCatalog::reported($field) || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $q = &$questions[$key];
                $q ??= ['title' => $title, 'multiple' => $kind === 'select_multiple', 'options' => [], 'answered' => 0, 'blank' => 0];
                foreach ($field['choices'] as $choice) {
                    $q['options'][(string) $choice['label']] ??= 0;
                }
                // Inside a repeat group the answer is the list of every repetition's value.
                $given = array_filter(Arr::flatten((array) KoboCatalog::lookup($row->raw, $path)), fn ($v) => $v !== null && $v !== '');
                if ($given === []) {
                    $q['blank']++;
                    unset($q);

                    continue;
                }
                $q['answered']++;
                $labels = array_column($field['choices'], 'label', 'code');
                // A multiple choice counts each option once per submission; an unknown code stays visible with its form.
                $codes = array_merge(...array_map(fn ($v) => $kind === 'select_multiple' ? preg_split('/\s+/', trim((string) $v)) : [(string) $v], array_values($given)));
                foreach (array_unique($codes) as $code) {
                    $label = $labels[$code] ?? $code.' ('.$row->form_uid.')';
                    $q['options'][$label] = ($q['options'][$label] ?? 0) + 1;
                }
                unset($q);
            }
        }

        return [
            'submissions' => $rows->count(),
            'questions' => array_values(array_map(fn ($q) => ['title' => $q['title'], 'multiple' => $q['multiple'], 'answered' => $q['answered'], 'blank' => $q['blank'],
                'options' => array_map(fn ($label, $count) => ['label' => (string) $label, 'count' => $count], array_keys($q['options']), $q['options'])], array_filter($questions, fn ($q) => $q['answered'] > 0))),
        ];
    }

    /**
     * One row per confirmed submission: context columns, then every reported question that has at least one answer in the selection.
     *
     * @return array{columns: list<string>, rows: list<array{context: array<string, ?string>, answers: array<string, string>, project_id: int, id: int}>}
     */
    public function answers(WebAccount $account, array $filters): array
    {
        $rows = $this->submissions($account, $filters);
        $columns = [];
        $out = [];
        foreach ($rows as $row) {
            $answers = [];
            foreach (KoboCatalog::fields((string) $row->form_uid) as $path => $field) {
                if (! KoboCatalog::reported($field) || KoboCatalog::lookup($row->raw, $path) === null) {
                    continue;
                }
                $value = KoboCatalog::display($field, KoboCatalog::lookup($row->raw, $path));
                if ($value === '') {
                    continue;
                }
                [$key, $title] = $this->key($field);
                $columns[$key] ??= $title;
                $answers[$key] = isset($answers[$key]) ? $answers[$key].' | '.$value : $value;
            }
            $out[] = ['id' => (int) $row->id, 'project_id' => (int) $row->project_id, 'answers' => $answers, 'context' => [
                'number' => ProjectNumberAllocator::display($row->city_code, (int) $row->project_number), 'person' => $row->first_name !== null ? PeopleService::fullName($row) : null,
                'subcategory' => $row->subcategory, 'career' => $row->career, 'stage' => $row->visit_number, 'date' => $row->visit_day ? substr((string) $row->visit_day, 0, 10) : null,
            ]];
        }

        return ['columns' => $columns, 'rows' => $out];
    }

    /**
     * The answers table as export headings and rows. Needs reports.export in every city exported (the chosen cities are narrowed to those).
     *
     * @return array{0: list<string>, 1: list<list<?string>>}
     */
    public function export(WebAccount $account, array $filters, AccountAccess $access, AccountAuditTrail $audit): array
    {
        $allowed = array_values(array_filter($this->kobo->cityIds($account), fn ($id) => $access->check($account, $id, 'reports.export')));
        if ($allowed === []) {
            $audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $chosen = array_values(array_intersect(array_map('intval', $filters['cities'] ?? []), $allowed));
        $filters['cities'] = $chosen ?: $allowed;
        $data = $this->answers($account, $filters);
        $context = ['number', 'person', 'subcategory', 'career', 'stage', 'date'];
        $headings = [...array_map(fn ($c) => __('kobo.context.'.$c), $context), ...array_values($data['columns'])];
        $rows = array_map(fn ($r) => [...array_map(fn ($c) => $r['context'][$c] ?? null, $context), ...array_map(fn ($k) => $r['answers'][$k] ?? null, array_keys($data['columns']))], $data['rows']);
        $audit->record($account->id, 'kobo.exported', 'succeeded');

        return [$headings, $rows];
    }
}
