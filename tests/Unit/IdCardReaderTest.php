<?php

namespace Tests\Unit;

use App\Services\Identity\IdCardReader;
use PHPUnit\Framework\TestCase;

/** The local checks of an identity reading: digits, dates, names, and the comparison of independent readings. */
class IdCardReaderTest extends TestCase
{
    private function input(array $fields = [], string $type = 'national_id'): array
    {
        $base = ['first_name' => 'نبيل', 'last_name' => 'زيدان', 'father_name' => 'سليمان', 'mother_name' => 'ريما', 'birth_date' => '1986-04-12', 'registration_place' => 'حلب 1234/5',
            'national_number' => '01020304050', 'id_issue_text' => 'حلب', 'sex' => 'male', 'id_document_number' => null];
        $out = ['document_type' => $type, 'notes' => ''];
        foreach (IdCardReader::FIELDS as $field) {
            $value = array_key_exists($field, $fields) ? $fields[$field] : $base[$field];
            $out[$field] = is_array($value) ? $value : ['value' => $value, 'confidence' => 'high'];
        }

        return $out;
    }

    public function test_arabic_indic_digits_become_ascii_and_a_good_number_stays_confident(): void
    {
        $read = IdCardReader::normalize($this->input(['national_number' => '٠١٠٢٠٣٠٤٠٥٠', 'birth_date' => '١٩٨٦-٠٤-١٢']));
        $this->assertSame('01020304050', $read['fields']['national_number']['value']);
        $this->assertSame('high', $read['fields']['national_number']['confidence']);
        $this->assertSame('1986-04-12', $read['fields']['birth_date']['value']);
    }

    public function test_a_national_number_that_is_not_eleven_digits_is_doubtful(): void
    {
        $read = IdCardReader::normalize($this->input(['national_number' => '0102030405']));
        $this->assertSame('low', $read['fields']['national_number']['confidence']);
        $this->assertSame('national_number_length', $read['fields']['national_number']['warning']);
        // A passport number is not a national number: no length rule there.
        $passport = IdCardReader::normalize($this->input(['national_number' => null, 'id_document_number' => 'n 0123456'], 'passport'));
        $this->assertSame('N0123456', $passport['fields']['id_document_number']['value']);
        $this->assertNull($passport['fields']['national_number']['value']);
    }

    public function test_dates_are_checked_and_day_first_dates_are_understood(): void
    {
        $this->assertSame('1986-04-12', IdCardReader::normalize($this->input(['birth_date' => '12/04/1986']))['fields']['birth_date']['value']);
        $bad = IdCardReader::normalize($this->input(['birth_date' => '1986-13-45']))['fields']['birth_date'];
        $this->assertNull($bad['value']);
        $this->assertSame('low', $bad['confidence']);
        $this->assertNull(IdCardReader::normalize($this->input(['birth_date' => '1986']))['fields']['birth_date']['value']);
        $this->assertNull(IdCardReader::normalize($this->input(['birth_date' => '1850-01-01']))['fields']['birth_date']['value']);
    }

    public function test_names_lose_stray_symbols_and_digits_and_become_doubtful(): void
    {
        $name = IdCardReader::normalize($this->input(['first_name' => 'نبيل7*']))['fields']['first_name'];
        $this->assertSame('نبيل', $name['value']);
        $this->assertSame('low', $name['confidence']);
        $this->assertSame('نبيل', IdCardReader::normalize($this->input())['fields']['first_name']['value']);
        $this->assertSame('female', IdCardReader::normalize($this->input(['sex' => 'أنثى']))['fields']['sex']['value']);
        $this->assertNull(IdCardReader::normalize($this->input(['sex' => 'x']))['fields']['sex']['value']);
    }

    public function test_a_missing_or_malformed_field_is_empty_and_doubtful(): void
    {
        $input = $this->input();
        unset($input['mother_name']);
        $input['father_name'] = 'text-not-an-object';
        $read = IdCardReader::normalize($input);
        $this->assertNull($read['fields']['mother_name']['value']);
        $this->assertSame('low', $read['fields']['mother_name']['confidence']);
        $this->assertNull($read['fields']['father_name']['value']);
        $this->assertSame('other', IdCardReader::normalize(['document_type' => 'something else'])['type']);
    }

    public function test_equal_readings_keep_the_lowest_confidence(): void
    {
        $a = IdCardReader::normalize($this->input());
        $b = IdCardReader::normalize($this->input(['last_name' => ['value' => 'زيدان', 'confidence' => 'medium']]));
        $merged = IdCardReader::merge([$a, $b]);
        $this->assertSame('زيدان', $merged['fields']['last_name']['value']);
        $this->assertSame('medium', $merged['fields']['last_name']['confidence']);
        $this->assertSame('high', $merged['fields']['first_name']['confidence']);
        $this->assertSame([], $merged['fields']['first_name']['alternatives']);
    }

    public function test_readings_that_differ_are_doubtful_with_the_other_candidates_kept(): void
    {
        $merged = IdCardReader::merge([
            IdCardReader::normalize($this->input(['national_number' => '01020304050'])),
            IdCardReader::normalize($this->input(['national_number' => '01020304058'])),
        ]);
        $number = $merged['fields']['national_number'];
        $this->assertSame('low', $number['confidence']);
        $this->assertSame('readings_differ', $number['warning']);
        $this->assertSame('01020304050', $number['value']);
        $this->assertSame(['01020304058'], $number['alternatives']);
        // With three readings the majority wins.
        $three = IdCardReader::merge([
            IdCardReader::normalize($this->input(['national_number' => '01020304058'])),
            IdCardReader::normalize($this->input(['national_number' => '01020304050'])),
            IdCardReader::normalize($this->input(['national_number' => '01020304050'])),
        ]);
        $this->assertSame('01020304050', $three['fields']['national_number']['value']);
        $this->assertSame('low', $three['fields']['national_number']['confidence']);
        // One reading empty and the other not is also a difference.
        $oneEmpty = IdCardReader::merge([IdCardReader::normalize($this->input(['mother_name' => null])), IdCardReader::normalize($this->input())]);
        $this->assertSame('low', $oneEmpty['fields']['mother_name']['confidence']);
        $this->assertSame('ريما', $oneEmpty['fields']['mother_name']['value']);
    }

    public function test_a_document_that_is_not_an_identity_document_is_never_confident(): void
    {
        $read = IdCardReader::normalize($this->input([], 'not_an_identity_document'));
        foreach ($read['fields'] as $field) {
            $this->assertSame('low', $field['confidence']);
        }
    }
}
