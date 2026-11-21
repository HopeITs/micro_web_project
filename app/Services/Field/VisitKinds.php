<?php

declare(strict_types=1);

namespace App\Services\Field;

/**
 * The visit questionnaires of the desktop (`VOpening_Other_Form`, `VOpening_Taxi_Form`, `VClosing_Form`, `VClosing_Rehab_Form`) as stored in
 * the unified schema: `visits.kind` keeps the desktop codes, `visit_answers` holds one row per answered slot (form `visit` for AnsN,
 * `office_monitoring` for the office ratings, `rehab` for the rehabilitation answers), each a note and a stored choice value.
 *
 * Choice values are the desktop's: opening yes 1 / no 0 and the loan share 25–100 % stored 1–4; closing "yes" 0 / "no" 1 (radio digit);
 * office ratings bad 0 / acceptable 1 / excellent 2; rehabilitation "not applicable" -1.
 *
 * Closing slots, verified against the 233 Aleppo O-cl rows and `VClosing_Form`: slots 1–5 are questions 1–5 (4 is text only), question 7
 * "ما الأمل الذي أضافه لك المشروع" is stored in slot 6 (151 filled, slot 7 never used), question 8 "ملاحظات على فريق Hope Center" is the
 * `indicators` column (the form has no separate indicators box), question 6 is the four office ratings.
 */
final class VisitKinds
{
    public const UNSET = -1;

    public const REHAB = 'R-cl';

    /** Choice domains: stored value => language key under `visits.choice`. */
    public const YES_NO = [1 => 'yes', 0 => 'no'];

    public const PERCENT = [1 => 'p25', 2 => 'p50', 3 => 'p75', 4 => 'p100'];

    public const CLOSE_1 = [0 => 'yes', 1 => 'no'];

    public const CLOSE_2 = [0 => 'debts', 1 => 'no_change', 2 => 'good', 3 => 'excellent'];

    public const CLOSE_3 = [0 => 'declined', 1 => 'same', 2 => 'improved'];

    public const CLOSE_5 = [0 => 'sell', 1 => 'no', 2 => 'yes', 3 => 'expand'];

    public const RATING = [0 => 'bad', 1 => 'acceptable', 2 => 'excellent'];

    public const RATING_NA = [0 => 'bad', 1 => 'acceptable', 2 => 'excellent', -1 => 'na'];

    /** Task definitions ticked by each visit (desktop `task_microproject` ids 25–31, matched by name in the unified catalogue). */
    public const OPENING_TASK = 'Opening Visit';

    public const CLOSING_TASK = 'Clearance';

    public const ME_TASKS = [1 => 'M1', 2 => 'M2', 3 => 'M3', 4 => 'MFinal', 5 => 'M5'];

    /** All visit task names (NextStep links them to the visits page). */
    public const TASKS = [self::OPENING_TASK, 'M1', 'M2', 'M3', 'MFinal', 'M5', self::CLOSING_TASK];

    /** Up to four visitors on an opening, closing or rehabilitation visit; two (each with a mark) on an M&E visit. */
    public const MAX_VISITORS = 4;

    public const MAX_ME_VISITORS = 2;

    /** Marks of an M&E visitor (`mevisit_user.Mark`); older stored values (ممتاز, سيئ, empty) are shown as stored. */
    public const MARKS = ['ضعيف', 'وسط', 'جيد'];

    /**
     * Per kind: phase, vehicle (plate number), place (street + detailed address), questions (slot => domain, null = text only),
     * office (number of office ratings), comments (beneficiary comments box), wording (`visit_questions.type` and slot => question number).
     */
    public const KINDS = [
        'O-op' => [
            'phase' => 'opening', 'vehicle' => false, 'place' => true, 'office' => 6, 'comments' => true, 'office_labels' => 'other', 'wording' => 'O-op',
            'questions' => [1 => self::YES_NO, 2 => self::YES_NO, 3 => self::YES_NO, 4 => self::PERCENT, 5 => self::YES_NO, 6 => self::YES_NO, 7 => self::YES_NO],
        ],
        'V-op' => [
            'phase' => 'opening', 'vehicle' => true, 'place' => false, 'office' => 6, 'comments' => true, 'office_labels' => 'vehicle', 'wording' => 'V-op',
            'questions' => [1 => self::YES_NO, 2 => self::YES_NO, 3 => self::PERCENT, 4 => self::YES_NO, 5 => self::YES_NO, 6 => self::YES_NO],
        ],
        'O-cl' => [
            'phase' => 'closing', 'vehicle' => false, 'place' => false, 'office' => 4, 'comments' => false, 'office_labels' => 'closing', 'wording' => 'O-cl',
            'questions' => [1 => self::CLOSE_1, 2 => self::CLOSE_2, 3 => self::CLOSE_3, 4 => null, 5 => self::CLOSE_5, 6 => null],
        ],
        'V-cl' => [
            'phase' => 'closing', 'vehicle' => false, 'place' => false, 'office' => 4, 'comments' => false, 'office_labels' => 'closing', 'wording' => 'O-cl',
            'questions' => [1 => self::CLOSE_1, 2 => self::CLOSE_2, 3 => self::CLOSE_3, 4 => null, 5 => self::CLOSE_5, 6 => null],
        ],
    ];

    /** Rehabilitation closing (`VClosing_Rehab_Form`): 4 office ratings, 8 workshop ratings (or N/A), 3 yes/no questions. */
    public const REHAB_QUESTIONS = [
        1 => self::RATING, 2 => self::RATING, 3 => self::RATING, 4 => self::RATING,
        5 => self::RATING_NA, 6 => self::RATING_NA, 7 => self::RATING_NA, 8 => self::RATING_NA,
        9 => self::RATING_NA, 10 => self::RATING_NA, 11 => self::RATING_NA, 12 => self::RATING_NA,
        13 => self::CLOSE_1, 14 => self::CLOSE_1, 15 => self::CLOSE_1,
    ];

    /** The question number shown for a closing slot (slot 6 is question 7). */
    public static function questionNumber(string $kind, int $slot): int
    {
        return self::phase($kind) === 'closing' && $slot === 6 ? 7 : $slot;
    }

    public static function has(string $kind): bool
    {
        return isset(self::KINDS[$kind]) || $kind === self::REHAB;
    }

    public static function definition(string $kind): array
    {
        return self::KINDS[$kind] ?? abort(404);
    }

    public static function phase(string $kind): string
    {
        return match (true) {
            str_ends_with($kind, 'op') => 'opening',
            str_ends_with($kind, 'cl') => 'closing',
            default => '',
        };
    }

    /** The kind a project records in a phase: rehabilitation grant projects only R-cl; vehicle projects the V forms. */
    public static function kindFor(string $phase, bool $vehicle, bool $rehab): ?string
    {
        if ($rehab) {
            return $phase === 'closing' ? self::REHAB : null;
        }

        return ($vehicle ? 'V' : 'O').'-'.($phase === 'opening' ? 'op' : 'cl');
    }

    /** The letter of the visit notices (V vehicle, O other, R rehabilitation). */
    public static function letter(string $kind): string
    {
        return substr($kind, 0, 1);
    }
}
