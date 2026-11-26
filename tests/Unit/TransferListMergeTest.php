<?php

namespace Tests\Unit;

use App\Services\Transfer\Transforms;
use PHPUnit\Framework\TestCase;

/** M5: desktop list values merge only when nothing about them differs. */
class TransferListMergeTest extends TestCase
{
    private const ANSWER_COLUMNS = [
        ['name' => 'id', 'type' => 'id'],
        ['name' => 'name_ar', 'type' => 'str:255'],
        ['name' => 'name_en', 'type' => 'str:255'],
        ['name' => 'me_question_id', 'type' => 'fk', 'source' => 'Question_ID', 'references' => 'me_questions'],
        ['name' => 'mark', 'type' => 'int', 'source' => 'Mark'],
        ['name' => 'sort_order', 'type' => 'int'],
        ['name' => 'is_active', 'type' => 'bool'],
    ];

    /** v1.8: the desktop writes Yes, No and NO; the website reads yes and no. */
    public function test_the_current_job_flag_is_spelled_one_way(): void
    {
        $this->assertSame(['yes', null], Transforms::yesNo('Yes'));
        $this->assertSame(['no', null], Transforms::yesNo(' NO '));
        $this->assertSame([null, null], Transforms::yesNo(''));
        $this->assertSame(['maybe', 'enum_unrecognised'], Transforms::yesNo('maybe'));
    }

    public function test_the_key_columns_are_the_parents_and_transferred_attributes(): void
    {
        $this->assertSame(['me_question_id', 'mark'], Transforms::listKeyColumns(self::ANSWER_COLUMNS));
        $this->assertSame([], Transforms::listKeyColumns([['name' => 'city_id', 'type' => 'fk', 'references' => 'cities'], ['name' => 'name_ar', 'type' => 'str:255']]));
    }

    public function test_the_same_answer_with_another_mark_stays_separate(): void
    {
        $columns = ['me_question_id', 'mark'];

        $this->assertNotSame(
            Transforms::listKey(['name_ar' => 'نعم', 'me_question_id' => 7, 'mark' => 5], $columns),
            Transforms::listKey(['name_ar' => 'نعم', 'me_question_id' => 7, 'mark' => 10], $columns),
        );
        $this->assertNotSame(
            Transforms::listKey(['name_ar' => 'نعم', 'me_question_id' => 7, 'mark' => 0], $columns),
            Transforms::listKey(['name_ar' => 'نعم', 'me_question_id' => 7, 'mark' => null], $columns),
        );
    }

    public function test_the_same_question_for_vehicles_and_other_projects_stays_separate(): void
    {
        $this->assertNotSame(
            Transforms::listKey(['name_ar' => 'الخبرة', 'type' => '3v'], ['type']),
            Transforms::listKey(['name_ar' => 'الخبرة', 'type' => '3o'], ['type']),
        );
    }

    public function test_values_that_differ_only_in_writing_still_merge(): void
    {
        $columns = ['me_question_id', 'mark', 'type'];

        // Value read back from the database (string) against a freshly mapped one (integer), Arabic letter forms, empty text.
        $this->assertSame(
            Transforms::listKey(['name_ar' => 'إجابة أولى', 'me_question_id' => '7', 'mark' => '5.00', 'type' => ''], $columns),
            Transforms::listKey(['name_ar' => 'اجابه اولى', 'me_question_id' => 7, 'mark' => 5, 'type' => null], $columns),
        );
    }
}
