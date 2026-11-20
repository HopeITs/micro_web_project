<?php

namespace App\Support;

/**
 * Project states on the application path, as the owner described them on 20 Nov 2026
 * (docs/planning/ux-borrowed-features-2026-11.md, decision U3). States are keyed by their English
 * name (desktop `state`), which the seeders and HomeController already use.
 *
 *   intake      تسجيل أولي، بالانتظار
 *   assessment  قيد الدراسة
 *   decision    مؤجل (paused; it can go back to بالانتظار or on to مرفوض) · مرفوض (the file is closed)
 *   funding     مقبول: financial procedures and purchasing
 *   followup    ممول: the project runs and pays its instalments · ملغى (funded, then the funding was withdrawn)
 *   closing     منتهي (instalments paid, the project goes on) · مغلق (closed for a natural, not a bad, reason)
 *
 * منسحب: the beneficiary withdrew, usually before the decision; the path stops, in grey, at the stage the
 * application had reached (read from the state history).
 *
 * Colour is never alone (U1): every state also has an icon and its word.
 */
final class ProjectStatus
{
    public const STAGES = ['intake', 'assessment', 'decision', 'funding', 'followup', 'closing'];

    /** Modes of the current step on the stage bar. */
    public const NOW = 'now';

    public const PAUSED = 'paused';

    public const STOPPED = 'stopped';     // red cross: rejected, cancelled

    public const WITHDRAWN = 'withdrawn'; // grey cross: the beneficiary's own choice

    public const COMPLETE = 'complete';   // every stage done (finished, closed)

    /** name_en => [tone, icon, stage, mode] */
    private const MAP = [
        'On Hold' => [null, null, 'intake', self::NOW],
        'Waiting' => ['warn', 'clock', 'intake', self::NOW],
        'Under Review' => ['warn', 'clock', 'assessment', self::NOW],
        'Delayed' => ['warn', 'pause', 'decision', self::PAUSED],
        'Rejected' => ['danger', 'x', 'decision', self::STOPPED],
        'Accepted' => ['info', 'check', 'funding', self::NOW],
        'Financed' => ['ok', 'wallet', 'followup', self::NOW],
        'Canceled' => ['danger', 'x', 'followup', self::STOPPED],
        'Finished' => ['ok', 'check', 'closing', self::COMPLETE],
        'Closed' => [null, 'flag', 'closing', self::COMPLETE],
        'Withdrew' => [null, 'x', null, self::WITHDRAWN],
    ];

    public static function tone(?string $stateEn): ?string
    {
        return self::MAP[$stateEn][0] ?? null;
    }

    public static function icon(?string $stateEn): ?string
    {
        return self::MAP[$stateEn][1] ?? null;
    }

    public static function stage(?string $stateEn): ?string
    {
        return self::MAP[$stateEn][2] ?? null;
    }

    public static function mode(?string $stateEn): string
    {
        return self::MAP[$stateEn][3] ?? self::NOW;
    }

    /**
     * Where the stage bar stands for a project.
     *
     * @param  list<string|null>  $earlier  English names of the earlier states, oldest first
     * @return array{current: ?string, mode: string, tone: ?string}
     */
    public static function path(?string $stateEn, array $earlier = []): array
    {
        $stage = self::stage($stateEn);
        if ($stateEn === 'Withdrew') {
            // Stops where the application had got to; a withdrawal normally comes before the decision.
            foreach (array_reverse($earlier) as $previous) {
                if (($stage = self::stage($previous)) !== null) {
                    break;
                }
            }
            $stage ??= 'intake';
            if (array_search($stage, self::STAGES, true) > array_search('decision', self::STAGES, true)) {
                $stage = 'decision';
            }
        }

        return ['current' => $stage, 'mode' => self::mode($stateEn), 'tone' => self::tone($stateEn)];
    }

    /**
     * Groups for the count tiles over the projects list (F3): the six stages hold the states still on the path,
     * and "stopped" holds rejected, withdrew and cancelled, so each state is counted exactly once.
     *
     * @return array<string, list<string>> group => English state names
     */
    public static function groups(): array
    {
        $groups = array_fill_keys([...self::STAGES, 'stopped'], []);
        foreach (self::MAP as $state => [, , $stage, $mode]) {
            $groups[in_array($mode, [self::STOPPED, self::WITHDRAWN], true) ? 'stopped' : $stage][] = $state;
        }

        return $groups;
    }

    /** The tone of a count tile: the tone its states share, red for stopped. */
    public static function groupTone(string $group): ?string
    {
        return ['assessment' => 'warn', 'decision' => 'warn', 'funding' => 'info', 'followup' => 'ok', 'closing' => 'ok', 'stopped' => 'danger'][$group] ?? null;
    }

    /** @return list<array{key: string, label: string}> */
    public static function stages(): array
    {
        return array_map(fn (string $key) => ['key' => $key, 'label' => __('hc.path_'.$key)], self::STAGES);
    }
}
