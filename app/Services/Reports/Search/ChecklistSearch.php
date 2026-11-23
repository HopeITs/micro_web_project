<?php

declare(strict_types=1);

namespace App\Services\Reports\Search;

use App\Services\Assessments\NeedAssessment;
use App\Services\Reports\ProjectReportFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Check lists" (desktop `bind_CheckList`, `CheckList.BuildChecklistReportQuery`): one row per checklist with its project, the home and project
 * visits, the professional test and trainings, the team and committee dates and the opinions of the key people, the team and the committee,
 * and the total of the need-assessment marks (computed by NeedAssessment, the same figure the checklist page shows).
 * The marks total is the only per-row computation (NeedAssessment reads each beneficiary's household); the rest is one query per page.
 */
final class ChecklistSearch extends SearchMode
{
    public function key(): string
    {
        return 'checklists';
    }

    public function group(): string
    {
        return 'applications';
    }

    public function capabilities(): array
    {
        return ['assessments.view'];
    }

    public function columns(): array
    {
        return ['number', 'beneficiary', 'father', 'career', 'category', 'subcategory', 'subtype', 'state', 'work_years',
            'home_visit', 'home_visit_date', 'home_visit_time', 'home_visitor1', 'home_visitor2', 'home_visit_note',
            'project_visit', 'project_visit_date', 'project_visit_time', 'project_visitor1', 'project_visitor2', 'project_visit_note', 'visits_report',
            'needs_test', 'test_need_note', 'test_done', 'test_date', 'test_note', 'test_result', 'tester',
            'pro_training', 'pro_trainer', 'pro_place', 'pro_start', 'pro_end', 'pro_note', 'pro_result',
            'mgmt_training', 'mgmt_trainer', 'mgmt_place', 'mgmt_date', 'mgmt_result', 'notes', 'rejection', 'rejection_date',
            'key_people_count', 'key_people', 'team_date', 'team_count', 'team_opinion', 'committee_date', 'committee_count', 'committee_opinion', 'total_marks'];
    }

    public function numeric(): array
    {
        return [1, 45, 48, 51, 53];
    }

    public function target(object $row): ?array
    {
        return ['checklist', (int) $row->project_id];
    }

    public function query(array $cityIds, array $filters): Builder
    {
        $q = $this->joinProject(DB::table('checklists as ch')->whereNull('ch.deleted_at'), 'ch.project_id', $cityIds)
            ->join('people as b', fn ($j) => $j->on('b.id', '=', 'ch.person_id')->whereNull('b.deleted_at'))
            ->leftJoin('project_applicants as wa', fn ($j) => $j->on('wa.project_id', '=', 'p.id')->on('wa.person_id', '=', 'ch.person_id')->whereNull('wa.deleted_at'))
            ->addSelect([...$this->baseColumns(), 'ch.id as checklist_id', 'ch.person_id', 'b.first_name', 'b.last_name', 'b.father_name', 'wa.work_years', 'st.name_ar as state_ar', 'st.name_en as state_en',
                'ch.has_home_visit', 'ch.home_visit_date', 'ch.home_visit_time', 'ch.home_visit_note', 'ch.has_project_visit', 'ch.project_visit_date', 'ch.project_visit_time', 'ch.project_visit_note',
                'ch.visits_report', 'ch.needs_professional_test', 'ch.professional_test_need_note', 'ch.has_professional_test', 'ch.professional_test_date', 'ch.professional_test_note',
                'ch.professional_test_result', 'ch.has_professional_training', 'ch.professional_trainer', 'ch.professional_training_place', 'ch.professional_training_start',
                'ch.professional_training_end', 'ch.professional_training_note', 'ch.professional_training_result', 'ch.has_management_training', 'ch.management_trainer',
                'ch.management_training_place', 'ch.management_training_date', 'ch.management_training_result', 'ch.notes', 'ch.rejection_message', 'ch.rejection_message_date',
                'ch.team_date', 'ch.committee_date']);
        $this->lookup($q, 'careers', 'cr', 'p.career_id', 'career');
        $this->lookup($q, 'project_categories', 'pc', 'p.project_category_id', 'category');
        $this->lookup($q, 'project_subcategories', 'ps', 'p.project_subcategory_id', 'subcategory');
        $this->lookup($q, 'project_subtypes', 'pst', 'p.project_subtype_id', 'subtype');
        $this->lookup($q, 'checklist_members', 'hv1', 'ch.home_visitor_1_id', 'hv1');
        $this->lookup($q, 'checklist_members', 'hv2', 'ch.home_visitor_2_id', 'hv2');
        $this->lookup($q, 'checklist_members', 'pv1', 'ch.project_visitor_1_id', 'pv1');
        $this->lookup($q, 'checklist_members', 'pv2', 'ch.project_visitor_2_id', 'pv2');
        $this->lookup($q, 'checklist_members', 'tst', 'ch.professional_tester_id', 'tester');

        return ProjectReportFilters::apply($q, $filters);
    }

    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('p.project_number')->orderBy('p.city_id')->orderBy('ch.id');
    }

    public function lines(Collection $rows): array
    {
        $opinions = DB::table('checklist_member_opinions as o')->leftJoin('checklist_members as m', 'm.id', '=', 'o.checklist_member_id')->leftJoin('checklist_opinions as op', 'op.id', '=', 'o.checklist_opinion_id')
            ->whereIn('o.person_id', $rows->pluck('person_id')->unique()->all() ?: [0])->whereNull('o.deleted_at')->orderBy('o.id')
            ->get(['o.person_id', 'o.location', 'o.notes', 'o.name_notes', 'o.role_notes', 'm.name_ar as member_ar', 'm.name_en as member_en', 'op.name_ar as opinion_ar', 'op.name_en as opinion_en'])
            ->groupBy('person_id');
        $need = app(NeedAssessment::class);
        $flag = fn ($v, string $set) => $v === null ? '' : __('search.flag.'.$set.'.'.((int) $v === 1 ? 'on' : 'off'));

        return $rows->map(function ($r) use ($opinions, $need, $flag) {
            $mine = $opinions->get($r->person_id, collect());
            $at = fn (string $where) => $mine->filter(fn ($o) => strtolower((string) $o->location) === $where)->values();
            $text = fn ($list, bool $key) => $list->map(function ($o) use ($key) {
                $member = $this->name($o, 'member');
                $notes = (string) $o->notes;
                if ($key) {
                    return implode(' - ', array_filter([(string) $o->name_notes, $member, (string) $o->role_notes, $notes], fn ($x) => $x !== ''));
                }

                return $member.' - '.$this->name($o, 'opinion').($notes === '' ? '' : ' - '.$notes);
            })->implode("\n");
            [$key, $team, $committee] = [$at('k'), $at('t'), $at('c')];

            return [
                $this->number($r), $this->personName($r), (string) $r->father_name, $this->name($r, 'career'), $this->name($r, 'category'), $this->name($r, 'subcategory'), $this->name($r, 'subtype'),
                $this->name($r, 'state'), (string) $r->work_years,
                $flag($r->has_home_visit, 'done'), $this->day($r->home_visit_date), (string) $r->home_visit_time, $this->name($r, 'hv1'), $this->name($r, 'hv2'), (string) $r->home_visit_note,
                $flag($r->has_project_visit, 'done'), $this->day($r->project_visit_date), (string) $r->project_visit_time, $this->name($r, 'pv1'), $this->name($r, 'pv2'), (string) $r->project_visit_note,
                (string) $r->visits_report,
                $flag($r->needs_professional_test, 'need'), (string) $r->professional_test_need_note, $flag($r->has_professional_test, 'yes'), $this->day($r->professional_test_date),
                (string) $r->professional_test_note, (string) $r->professional_test_result, $this->name($r, 'tester'),
                $flag($r->has_professional_training, 'yes'), (string) $r->professional_trainer, (string) $r->professional_training_place, $this->day($r->professional_training_start),
                $this->day($r->professional_training_end), (string) $r->professional_training_note, (string) $r->professional_training_result,
                $flag($r->has_management_training, 'yes'), (string) $r->management_trainer, (string) $r->management_training_place, $this->day($r->management_training_date),
                (string) $r->management_training_result, (string) $r->notes, $r->rejection_message === null ? '' : ((int) $r->rejection_message > 0 ? __('search.flag.sent.on') : __('search.flag.sent.off')),
                $this->day($r->rejection_message_date),
                $key->count(), $text($key, true), $this->day($r->team_date), $team->count(), $text($team, false), $this->day($r->committee_date), $committee->count(), $text($committee, false),
                $need->compute((int) $r->project_id, (int) $r->person_id)['total'] ?? '',
            ];
        })->all();
    }
}
