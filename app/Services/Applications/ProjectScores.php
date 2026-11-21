<?php

declare(strict_types=1);

namespace App\Services\Applications;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The 10 manual project scores of the full application form ("تحليل المشروع", desktop Application_Form scoring block and table
 * microproject_score), on score_criteria / score_options / project_scores. Moved from the checklist (owner decision, 21 Nov 2026);
 * the stored rows behave as before: a save replaces the project's answered criteria (the desktop deleted and re-inserted them).
 * The desktop's "المجموع" counts the criteria answered with the favourable option (value 1).
 */
final class ProjectScores
{
    /** The criteria with their options (value 1, 0 or -1 = N/A). */
    public function criteria(): Collection
    {
        $en = app()->getLocale() === 'en';
        $options = DB::table('score_options')->where('is_active', true)->orderBy('sort_order')->orderByDesc('value')->get()->groupBy('score_criterion_id');

        return DB::table('score_criteria')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->map(fn ($c) => (object) [
            'id' => (int) $c->id, 'label' => $en && $c->name_en ? $c->name_en : $c->name_ar,
            'options' => ($options->get($c->id) ?? collect())->map(fn ($o) => (object) ['value' => (int) $o->value, 'label' => $en && $o->name_en ? $o->name_en : $o->name_ar])->values(),
        ]);
    }

    /** @return array<int, array{value: string, notes: string}> form state, one entry per criterion */
    public function load(?int $projectId): array
    {
        $saved = $projectId ? DB::table('project_scores')->where('project_id', $projectId)->whereNull('deleted_at')->get()->keyBy('score_criterion_id') : collect();
        $scores = [];
        foreach ($this->criteria() as $c) {
            $row = $saved->get($c->id);
            $scores[$c->id] = ['value' => $row && $row->value !== null ? (string) $row->value : '', 'notes' => (string) ($row->notes ?? '')];
        }

        return $scores;
    }

    /** Sum of the favourable answers (desktop Sum_label). */
    public static function total(array $scores): int
    {
        return count(array_filter($scores, fn ($s) => (string) ($s['value'] ?? '') === '1'));
    }

    /** @return array<int, array{value: ?int, notes: ?string}> answered criteria only; errors under "scores.<id>.value" */
    public function validate(array $scores, array &$errors): array
    {
        $out = [];
        foreach ($this->criteria() as $c) {
            $value = trim((string) ($scores[$c->id]['value'] ?? $scores[(string) $c->id]['value'] ?? ''));
            $notes = trim((string) ($scores[$c->id]['notes'] ?? $scores[(string) $c->id]['notes'] ?? ''));
            if ($value === '' && $notes === '') {
                continue;
            }
            if ($value !== '' && ! $c->options->contains('value', (int) $value)) {
                $errors["scores.{$c->id}.value"] = [__('people.errors.choice')];
            }
            $out[$c->id] = ['value' => $value === '' ? null : (int) $value, 'notes' => $notes === '' ? null : mb_substr($notes, 0, 65535)];
        }

        return $out;
    }

    /**
     * Replaces the project's scores when they changed; returns the v1.3 change details (field "score.c<id>", before/after as
     * option label · notes).
     *
     * @param  array<int, array{value: ?int, notes: ?string}>  $scores  validated
     * @return list<array{field: string, before: ?string, after: ?string}>
     */
    public function save(int $city, int $projectId, array $scores): array
    {
        $now = now();
        $details = [];
        $old = DB::table('project_scores')->where('project_id', $projectId)->whereNull('deleted_at')->get()->keyBy('score_criterion_id');
        $criteria = $this->criteria()->keyBy('id');
        $text = fn (int $criterion, ?int $value, ?string $notes) => trim(($value === null ? '' : ($criteria[$criterion]->options->firstWhere('value', $value)->label ?? (string) $value)).($notes ? ' · '.$notes : '')) ?: null;
        foreach ($criteria as $id => $c) {
            $row = $old->get($id);
            $before = $row ? $text($id, $row->value === null ? null : (int) $row->value, $row->notes) : null;
            $after = isset($scores[$id]) ? $text($id, $scores[$id]['value'], $scores[$id]['notes']) : null;
            if ($before !== $after) {
                $details[] = ['field' => 'score.c'.$id, 'before' => $before, 'after' => $after];
            }
        }
        if ($details === []) {
            return [];
        }
        DB::table('project_scores')->where('project_id', $projectId)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
        foreach ($scores as $criterion => $score) {
            DB::table('project_scores')->insert(['city_id' => $city, 'project_id' => $projectId, 'score_criterion_id' => $criterion, 'value' => $score['value'], 'notes' => $score['notes'], 'created_at' => $now, 'updated_at' => $now]);
        }

        return $details;
    }
}
