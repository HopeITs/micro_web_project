<?php

namespace App\Support;

/**
 * UX decision U1 (docs/planning/ux-borrowed-features-2026-11.md): semantic status colours, always with
 * an icon and a word. One place maps a project state (desktop `state`, keyed by its English name, which
 * the seeders and HomeController already use) to its tone and to the stage of the application path.
 *
 * Stages of a micro project: intake → assessment → decision → funding → implementation → closing.
 * Rejected / withdrew / cancelled stop the path at the decision stage.
 */
final class ProjectStatus
{
    public const STAGES = ['intake', 'assessment', 'decision', 'funding', 'implementation', 'closing'];

    /** name_en => [tone, stage, stopped] */
    private const MAP = [
        'On Hold' => [null, 'intake', false],          // تسجيل أولي
        'Under Review' => ['warn', 'assessment', false], // قيد الدراسة
        'Waiting' => ['warn', 'decision', false],       // بالانتظار
        'Delayed' => ['warn', 'decision', false],       // مؤجل
        'Accepted' => ['info', 'funding', false],       // مقبول
        'Financed' => ['ok', 'implementation', false],  // ممول
        'Finished' => ['info', 'closing', false],       // منتهي
        'Closed' => [null, 'closing', false],           // مغلق
        'Rejected' => ['danger', 'decision', true],     // مرفوض
        'Withdrew' => ['danger', 'decision', true],     // منسحب
        'Canceled' => ['danger', 'decision', true],     // ملغى
    ];

    public static function tone(?string $stateEn): ?string
    {
        return self::MAP[$stateEn][0] ?? null;
    }

    public static function stage(?string $stateEn): ?string
    {
        return self::MAP[$stateEn][1] ?? null;
    }

    public static function stopped(?string $stateEn): bool
    {
        return self::MAP[$stateEn][2] ?? false;
    }

    /** @return list<array{key: string, label: string}> */
    public static function stages(): array
    {
        return array_map(fn (string $key) => ['key' => $key, 'label' => __('hc.path_'.$key)], self::STAGES);
    }
}
