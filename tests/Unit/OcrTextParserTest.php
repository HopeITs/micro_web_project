<?php

namespace Tests\Unit;

use App\Services\Identity\IdCardReader;
use App\Services\Identity\OcrTextParser;
use PHPUnit\Framework\TestCase;

/** The fields taken from Tesseract's text of an identity card. */
class OcrTextParserTest extends TestCase
{
    /** @param list<array{0: string, 1?: float}|string> $lines each line: its words (a string split on spaces) or [words, confidence] */
    private function tsv(array $lines, float $conf = 95.0): string
    {
        $rows = ["level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext", "4\t1\t1\t1\t1\t0\t0\t0\t10\t10\t-1\t"];
        foreach ($lines as $n => $line) {
            [$text, $c] = is_array($line) ? [$line[0], $line[1] ?? $conf] : [$line, $conf];
            foreach (explode(' ', $text) as $w => $word) {
                $rows[] = implode("\t", [5, 1, 1, 1, $n + 1, $w + 1, 0, 0, 10, 10, $c, $word]);
            }
        }

        return implode("\n", $rows);
    }

    private function card(array $override = []): array
    {
        return OcrTextParser::toInput(OcrTextParser::lines($this->tsv($override + [
            'الجمهورية العربية السورية', 'البطاقة الشخصية', 'الاسم : نبيل', 'الكنية : زيدان', 'اسم الأب : سليمان', 'اسم الأم : ريما',
            'تاريخ الولادة : 12/04/1986', 'الرقم الوطني : ٠١٠٢٠٣٠٤٠٥٠', 'القيد : حلب 1234/5', 'المنح : حلب',
        ])));
    }

    public function test_the_labels_of_a_syrian_card_give_the_fields(): void
    {
        $input = $this->card();
        $this->assertSame('national_id', $input['document_type']);
        $this->assertSame('نبيل', $input['first_name']['value']);
        $this->assertSame('زيدان', $input['last_name']['value']);
        $this->assertSame('سليمان', $input['father_name']['value'], 'اسم الأب is not taken for the first name');
        $this->assertSame('ريما', $input['mother_name']['value']);
        $this->assertSame('حلب 1234/5', $input['registration_place']['value']);
        $this->assertSame('حلب', $input['id_issue_text']['value']);
        $this->assertSame('12/04/1986', $input['birth_date']['value']);
        $this->assertSame('01020304050', $input['national_number']['value'], 'Arabic-Indic digits become ASCII');
    }

    public function test_the_whole_pipeline_gives_clean_values_and_names_are_never_sure(): void
    {
        $read = IdCardReader::normalize($this->card());
        $this->assertSame('1986-04-12', $read['fields']['birth_date']['value']);
        $this->assertSame('01020304050', $read['fields']['national_number']['value']);
        $this->assertSame('high', $read['fields']['national_number']['confidence'], 'clear digits can be sure');
        foreach (['first_name', 'last_name', 'father_name', 'mother_name', 'registration_place'] as $name) {
            $this->assertSame('medium', $read['fields'][$name]['confidence'], "$name read by OCR needs review");
        }
    }

    public function test_the_national_number_is_found_by_its_shape_even_when_split_or_unlabelled(): void
    {
        $split = $this->card([7 => 'الرقم الوطني : 0102 0304 050']);
        $this->assertSame('01020304050', $split['national_number']['value']);
        $unlabelled = OcrTextParser::toInput(OcrTextParser::lines($this->tsv(['البطاقة الشخصية', 'الاسم : نبيل', '01020304050'])));
        $this->assertSame('01020304050', $unlabelled['national_number']['value']);
        // Not 11 digits: not taken.
        $this->assertNull($this->card([7 => 'الرقم الوطني : 0102030405'])['national_number']['value']);
    }

    public function test_a_value_on_the_next_line_is_taken_and_a_label_is_never_taken_as_a_value(): void
    {
        $input = OcrTextParser::toInput(OcrTextParser::lines($this->tsv(['البطاقة الشخصية', 'الاسم', 'نبيل', 'الكنية', 'اسم الأب : سليمان'])));
        $this->assertSame('نبيل', $input['first_name']['value']);
        $this->assertNull($input['last_name']['value'], 'the next line is another label, not the surname');
        $this->assertSame('سليمان', $input['father_name']['value']);
    }

    public function test_low_confidence_words_make_the_field_doubtful(): void
    {
        $input = OcrTextParser::toInput(OcrTextParser::lines($this->tsv(['البطاقة الشخصية', ['الاسم : نبيل', 40.0], 'الكنية : زيدان'])));
        $this->assertSame('low', $input['first_name']['confidence']);
        $this->assertSame('medium', $input['last_name']['confidence']);
        $this->assertSame('low', IdCardReader::normalize($input)['fields']['first_name']['confidence']);
    }

    public function test_diacritics_and_letter_variants_do_not_hide_a_label(): void
    {
        $input = OcrTextParser::toInput(OcrTextParser::lines($this->tsv(['البِطَاقَة الشخصيّة', 'اسـم الأَب : سليمان', 'الجنس : أنثى'])));
        $this->assertSame('سليمان', $input['father_name']['value']);
        $this->assertSame('أنثى', $input['sex']['value']);
    }

    public function test_plain_text_output_is_read_too_with_a_fair_confidence(): void
    {
        $plain = 'البطاقة الشخصية
الاسم : نبيل
الكنية : زيدان
الرقم الوطني : 01020304050
';
        $input = OcrTextParser::toInput(OcrTextParser::lines($plain));
        $this->assertSame('نبيل', $input['first_name']['value']);
        $this->assertSame('medium', $input['first_name']['confidence']);
        $this->assertSame('01020304050', $input['national_number']['value']);
        $this->assertSame('medium', $input['national_number']['confidence'], 'unknown word confidence is never "high"');
    }

    public function test_text_that_is_not_a_document_is_reported_as_such(): void
    {
        $this->assertSame('not_an_identity_document', OcrTextParser::toInput(OcrTextParser::lines($this->tsv(['ab'])))['document_type']);
        $this->assertSame('not_an_identity_document', OcrTextParser::toInput([])['document_type']);
        $this->assertSame('passport', OcrTextParser::toInput(OcrTextParser::lines($this->tsv(['PASSPORT', 'SYRIAN ARAB REPUBLIC', 'P<SYRZAIDAN<<NABIL'])))['document_type']);
    }
}
