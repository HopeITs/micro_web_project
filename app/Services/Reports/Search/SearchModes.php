<?php

declare(strict_types=1);

namespace App\Services\Reports\Search;

/** The 22 search types of the desktop's "Search by", by key, in the order of the mode select (grouped by `SearchMode::group()`). */
final class SearchModes
{
    /** @var array<string, class-string<SearchMode>> */
    public const MODES = [
        'applications' => ApplicationSearch::class, 'family' => FamilySearch::class, 'educations' => EducationSearch::class, 'attachments' => AttachmentSearch::class, 'checklists' => ChecklistSearch::class,
        'loans' => LoanSearch::class,
        'visit-dates' => VisitDatesSearch::class, 'me-visits' => MeVisitSearch::class, 'special-cases' => SpecialCaseSearch::class, 'meetings' => MeetingSearch::class,
    ];

    /** Select groups in order. */
    public const GROUPS = ['applications', 'people', 'money', 'field'];

    /** @return list<string> */
    public static function keys(): array
    {
        return [...array_keys(self::MODES), ...array_keys(DetailSearch::DEFINITIONS)];
    }

    public static function make(string $key): SearchMode
    {
        if (isset(DetailSearch::DEFINITIONS[$key])) {
            return app()->makeWith(DetailSearch::class, ['key' => $key]);
        }
        abort_unless(isset(self::MODES[$key]), 404);

        return app(self::MODES[$key]);
    }
}
