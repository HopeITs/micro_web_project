<?php

declare(strict_types=1);

namespace App\Services\Papers;

/** M4.18: the profession meeting minutes and survey as a Word file, from the same data as the printed page ({@see MeetingPaper::forPrint}). */
final class MeetingWord
{
    /** @param  array<string, mixed>  $d */
    public function build(array $d): string
    {
        $L = fn (string $key) => __('paper_meeting.'.$key);
        $s = $d['state'];
        $en = (bool) $d['en'];
        $w = new DocxWriter(! $en);
        $name = fn ($o) => $en && $o->name_en ? $o->name_en : $o->name_ar;
        $minutes = $d['kind'] === 'minutes';

        $w->title($L($minutes ? 'minutes_title' : 'survey_title').' · '.$d['city']['name']);
        $w->field($L('careers'), implode('، ', $d['careers']))->field($L('date'), $d['date'])->field($L('place'), $d['place']);
        $w->field($L('invited'), (string) $d['invited'])->field($L('attendance'), (string) $d['attendance']);
        if ($minutes) {
            $w->field($L('absence'), (string) $d['absence']);
        }
        $w->field($L('paper_registrar'), $d['paper_registrar'])->field($L('program_registrar'), $d['program_registrar']);

        if ($minutes) {
            $w->bar($L('attendees'))->table([$L('project'), $L('name'), $L('speciality')],
                array_map(fn ($r) => [$r['project'], $r['name'], $r['speciality']], $s['attendees']), 5);
            foreach ($d['sections'] as $section) {
                $w->bar($section->label)->text((string) ($s['minutes'][$section->id] ?? ''));
            }

            return $w->bar($L('notes'))->text((string) $s['notes'])->toString();
        }

        $levels = [1 => __('field.meeting.level.1'), 2 => __('field.meeting.level.2'), 3 => __('field.meeting.level.3')];
        $filled = fn (array $rows) => array_values(array_filter($rows, fn ($r) => implode('', array_map('strval', $r)) !== ''));
        $part = fn ($rows) => array_map(fn ($r) => [$r['project'] ?? '', $r['name'] ?? '', $r['speciality'] ?? ''], $rows);
        foreach ($d['sections'] as $section) {
            $w->bar($section->label);
            foreach ($section->questions as $q) {
                $w->text($q->number.'. '.$q->label, true);
                $type = $q->answer_type;
                if (in_array($type, ['option_number', 'multi_option'], true)) {
                    foreach ($q->options as $o) {
                        $a = $s['opt'][$o->id] ?? [];
                        $mark = $type === 'option_number' ? (string) ($a['n'] ?? '') : (! empty($a['on']) ? '✓' : '');
                        $w->text('['.$mark.'] '.$name($o).(($a['text'] ?? '') !== '' ? ' — '.$a['text'] : ''));
                    }
                } elseif ($type === 'text') {
                    $w->text((string) ($s['tx'][$q->id] ?? ''));
                } elseif ($type === 'competition') {
                    $w->table([$L('competition'), $L('count'), $L('reason')], collect($levels)->map(fn ($label, $k) => [$label, $s['comp'][$k]['n'] ?? '', $s['comp'][$k]['reason'] ?? ''])->values()->all());
                } elseif ($type === 'season_table') {
                    $w->table([$L('count'), $L('from'), $L('to'), $L('reason')], array_map(fn ($r) => [$r['n'] ?? '', $r['from'] ?? '', $r['to'] ?? '', $r['reason'] ?? ''], $filled($s['seasons'])), 2);
                } elseif ($type === 'brand_table') {
                    $w->table([$L('brand')], array_map(fn ($b) => [$b], array_values(array_filter(preg_split('/\R/u', (string) $s['brands'])))), 2);
                } elseif ($type === 'equipment_table') {
                    $w->table([$L('spec'), $L('items')], array_map(fn ($r) => [$r['spec'] ?? '', $r['items'] ?? ''], $filled($s['equipment'])), 2);
                } elseif ($type === 'challenge_table') {
                    $w->table([$L('challenge'), $L('count'), $L('solution')], array_map(fn ($r) => [$r['name'] ?? '', $r['n'] ?? '', $r['solution'] ?? ''],
                        array_values(array_filter($s['challenges'], fn ($r) => ($r['n'] ?? '') !== '' || ($r['solution'] ?? '') !== ''))), 2);
                } elseif ($type === 'participant_table') {
                    $w->table([$L('project'), $L('name'), $L('speciality')], $part($s['part'][$q->id] ?? []), 2);
                }
            }
        }

        return $w->toString();
    }
}
