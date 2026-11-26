<?php

declare(strict_types=1);

namespace App\Services\Reports\Search;

use App\Services\Reports\ProjectReportFilters;
use App\Support\ArabicSearch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Profession meetings" (desktop `bind_ProfessionMeetings`): meetings with career, category, place and attendance, newest first. Filters: category,
 * sub category, career (the meeting's own, or any of its extra careers) and the meeting date range; the text box searches the place and the names of
 * the career, the sub category and the category. There is no project, so the project filters are not offered.
 */
final class MeetingSearch extends SearchMode
{
    public function key(): string
    {
        return 'meetings';
    }

    public function group(): string
    {
        return 'field';
    }

    public function capabilities(): array
    {
        return ['profession_meetings.view'];
    }

    public function filterKind(): string
    {
        return 'meeting';
    }

    public function hasProject(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return ['meeting_id', 'meeting_date', 'career', 'category', 'subcategory', 'place', 'invited', 'attended', 'absent'];
    }

    public function numeric(): array
    {
        return [1, 7, 8, 9];
    }

    public function target(object $row): ?array
    {
        return ['meeting', (int) $row->meeting_id];
    }

    public function query(array $cityIds, array $filters): Builder
    {
        $f = ProjectReportFilters::clean($filters);
        $q = DB::table('profession_meetings as m')->join('cities as ci', 'ci.id', '=', 'm.city_id')->whereNull('m.deleted_at')->whereIn('m.city_id', $cityIds === [] ? [0] : $cityIds)
            ->addSelect(['m.id as meeting_id', 'm.city_id', DB::raw('NULL as project_id'), 'ci.code', 'ci.name_ar as city_ar', 'ci.name_en as city_en', 'm.meeting_date', 'm.place', 'm.invited_count', 'm.attendance_count', 'm.absence_count']);
        $this->lookup($q, 'careers', 'cr', 'm.career_id', 'career');
        $this->lookup($q, 'project_subcategories', 'ps', 'm.project_subcategory_id', 'subcategory');
        $this->lookup($q, 'project_categories', 'pc', 'ps.project_category_id', 'category');
        if ($f['career'] !== []) {
            $extra = fn ($e) => $e->selectRaw('1')->from('profession_meeting_careers as mc')->whereColumn('mc.profession_meeting_id', 'm.id')->whereNull('mc.deleted_at')->whereIn('mc.career_id', $f['career']);
            if (in_array('career', $f['not'], true)) {
                // Excluded: neither the meeting's own career nor any extra one is among the chosen.
                $q->where(fn ($w) => $w->whereNotIn('m.career_id', $f['career'])->orWhereNull('m.career_id'))->whereNotExists($extra);
            } else {
                $q->where(fn ($w) => $w->whereIn('m.career_id', $f['career'])->orWhereExists($extra));
            }
        }
        foreach (['subcategory' => 'm.project_subcategory_id', 'category' => 'ps.project_category_id'] as $key => $column) {
            if ($f[$key] === []) {
                continue;
            }
            if (in_array($key, $f['not'], true)) {
                $q->where(fn ($w) => $w->whereNotIn($column, $f[$key])->orWhereNull($column));
            } else {
                $q->whereIn($column, $f[$key]);
            }
        }
        $f['meeting_from'] !== '' && $q->where('m.meeting_date', '>=', $f['meeting_from']);
        $f['meeting_to'] !== '' && $q->where('m.meeting_date', '<=', $f['meeting_to']);
        if ($f['q'] !== '') {
            foreach (array_slice(preg_split('/\s+/u', $f['q'], -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $word) {
                $q->where(function ($w) use ($word) {
                    foreach (['m.place', 'cr.name_ar', 'cr.name_en', 'ps.name_ar', 'ps.name_en', 'pc.name_ar', 'pc.name_en'] as $column) {
                        ArabicSearch::orWhereLike($w, $column, $word);
                    }
                });
            }
        }

        return $q;
    }

    public function sorts(): array
    {
        return [
            'city' => [$this->byName('ci')], 'meeting_id' => ['m.id'], 'meeting_date' => ['m.meeting_date'], 'career' => [$this->byName('cr')], 'category' => [$this->byName('pc')],
            'subcategory' => [$this->byName('ps')], 'place' => ['m.place'], 'invited' => ['m.invited_count'], 'attended' => ['m.attendance_count'], 'absent' => ['m.absence_count'],
        ];
    }

    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('m.meeting_date')->orderByDesc('m.id');
    }

    public function lines(Collection $rows): array
    {
        return $rows->map(fn ($r) => [$r->meeting_id, $this->day($r->meeting_date), $this->name($r, 'career'), $this->name($r, 'category'), $this->name($r, 'subcategory'),
            (string) $r->place, (int) ($r->invited_count ?? 0), (int) ($r->attendance_count ?? 0), (int) ($r->absence_count ?? 0)])->all();
    }
}
