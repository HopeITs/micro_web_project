<?php

namespace Tests\Feature;

use App\Livewire\IdentityReview;
use App\Models\WebAccount;
use App\Services\Identity\IdCardReader;
use App\Services\Identity\IdentityReadException;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** The two engines that run on this side (Ollama and Tesseract): the same readings, checks and review, and the scan never leaves the server. */
class IdentityEnginesTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        config(['identity_reader.passes' => 2, 'identity_reader.daily_limit' => 1, 'identity_reader.api_key' => null]);
    }

    /** @param list<string> $lines */
    private function tsv(array $lines, float $conf = 95.0): string
    {
        $rows = ["level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext"];
        foreach ($lines as $n => $line) {
            foreach (explode(' ', $line) as $w => $word) {
                $rows[] = implode("\t", [5, 1, 1, 1, $n + 1, $w + 1, 0, 0, 10, 10, $conf, $word]);
            }
        }

        return implode("\n", $rows);
    }

    private function cardTsv(): string
    {
        return $this->tsv(['البطاقة الشخصية', 'الاسم : نبيل', 'الكنية : زيدان', 'اسم الأب : سليمان', 'اسم الأم : ريما', 'تاريخ الولادة : 12/04/1986', 'الرقم الوطني : 01020304050', 'القيد : حلب 1234/5', 'المنح : حلب']);
    }

    /** @return array<string, mixed> */
    private function ollamaAnswer(array $override = []): array
    {
        $field = fn (?string $v) => ['value' => $v, 'confidence' => 'high'];
        $input = ['document_type' => 'national_id', 'notes' => '', 'first_name' => $field('نبيل'), 'last_name' => $field('زيدان'), 'father_name' => $field('سليمان'), 'mother_name' => $field('ريما'),
            'birth_date' => $field('1986-04-12'), 'registration_place' => $field('حلب 1234/5'), 'national_number' => $field('01020304050'), 'id_issue_text' => $field('حلب'), 'sex' => $field('male'), 'id_document_number' => $field(null)];

        return ['message' => ['content' => json_encode(array_merge($input, $override), JSON_UNESCAPED_UNICODE)], 'prompt_eval_count' => 900, 'eval_count' => 150];
    }

    private function fakeTesseract(string $tsv): void
    {
        Process::fake(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), '--version') ? Process::result('tesseract 5.3.0') : Process::result($tsv));
    }

    private function personWithScan(): array
    {
        $person = DB::table('people')->where('city_id', DB::table('cities')->where('code', 'DAM')->value('id'))->orderBy('id')->first();
        $path = 'attachments/DAM/person-'.$person->id.'/scan.png';
        Storage::disk('local')->put($path, base64_decode(self::PNG));
        $attachment = DB::table('attachments')->insertGetId([
            'city_id' => $person->city_id, 'person_id' => $person->id, 'attachment_type_id' => (int) DB::table('attachment_types')->where('name_en', 'like', 'IDs%')->value('id'),
            'original_name' => 'id.png', 'stored_path' => $path, 'extension' => 'png', 'legacy_source' => 'image', 'uploaded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$person, $attachment];
    }

    // ---------------------------------------------------------------- ollama

    public function test_ollama_reads_through_the_fixed_schema_and_the_scan_goes_only_to_the_local_address(): void
    {
        config(['identity_reader.driver' => 'ollama']);
        Http::fake(['127.0.0.1:11434/*' => Http::response($this->ollamaAnswer())]);
        $reader = app(IdCardReader::class);

        $this->assertTrue($reader->isLocal());
        $result = $reader->read(base64_decode(self::PNG), 'image/png');
        $this->assertSame('01020304050', $result['fields']['national_number']['value']);
        $this->assertSame('high', $result['fields']['national_number']['confidence']);
        $this->assertSame(1800, $result['input_tokens']);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_starts_with($request->url(), 'http://127.0.0.1:11434/api/chat') && $body['model'] === 'qwen2.5vl:7b' && $body['stream'] === false
                && isset($body['format']['properties']['national_number']) && count($body['messages'][1]['images']) === 1 && $body['messages'][0]['role'] === 'system';
        });
    }

    public function test_ollama_disagreeing_readings_are_doubtful_like_the_other_engines(): void
    {
        config(['identity_reader.driver' => 'ollama']);
        Http::fake(['127.0.0.1:11434/*' => Http::sequence()->push($this->ollamaAnswer())->push($this->ollamaAnswer(['national_number' => ['value' => '01020304058', 'confidence' => 'high']]))]);

        $number = app(IdCardReader::class)->read(base64_decode(self::PNG), 'image/png')['fields']['national_number'];
        $this->assertSame('low', $number['confidence']);
        $this->assertSame(['01020304058'], $number['alternatives']);
    }

    public function test_ollama_reports_a_missing_model_an_unreachable_server_and_an_unusable_answer(): void
    {
        config(['identity_reader.driver' => 'ollama']);
        $read = fn () => app(IdCardReader::class)->read(base64_decode(self::PNG), 'image/png');

        // One stand-in answering by call number (a second Http::fake on the same address would not replace the first).
        $calls = 0;
        Http::fake(['127.0.0.1:11434/*' => function () use (&$calls) {
            return match (++$calls) {
                1 => Http::response(['error' => 'model not found'], 404),
                2 => throw new ConnectionException('refused'),
                default => Http::response(['message' => ['content' => 'not json']]),
            };
        }]);
        $this->assertSame('model_missing', $this->failure($read));
        $this->assertSame('unreachable', $this->failure($read));
        $this->assertSame('no_result', $this->failure($read));
    }

    private function failure(\Closure $read): string
    {
        try {
            $read();
        } catch (IdentityReadException $e) {
            return $e->getMessage();
        }

        return 'no failure';
    }

    // ---------------------------------------------------------------- tesseract

    public function test_tesseract_text_becomes_fields_through_two_different_readings(): void
    {
        config(['identity_reader.driver' => 'tesseract']);
        $this->fakeTesseract($this->cardTsv());

        $reader = app(IdCardReader::class);
        $this->assertTrue($reader->enabled());
        $this->assertTrue($reader->isLocal());
        $result = $reader->read(base64_decode(self::PNG), 'image/png');

        $this->assertSame('01020304050', $result['fields']['national_number']['value']);
        $this->assertSame('1986-04-12', $result['fields']['birth_date']['value']);
        $this->assertSame('سليمان', $result['fields']['father_name']['value']);
        $this->assertSame('medium', $result['fields']['first_name']['confidence'], 'a name read by OCR always needs review');
        $this->assertSame('national_id', $result['type']);
        // Two independent readings: different page segmentation, Arabic and English data.
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), '--psm 6') && str_contains(implode(' ', (array) $p->command), '-l ara+eng'));
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), '--psm 11'));
        Process::assertRan(fn (PendingProcess $p) => ! str_contains(implode(' ', (array) $p->command), '--tessdata-dir'));
    }

    public function test_tesseract_can_read_its_language_data_from_a_folder_without_administrator_rights(): void
    {
        config(['identity_reader.driver' => 'tesseract', 'identity_reader.passes' => 1, 'identity_reader.tesseract.tessdata' => 'D:/ocr-data/tessdata']);
        $this->fakeTesseract($this->cardTsv());

        app(IdCardReader::class)->read(base64_decode(self::PNG), 'image/png');
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), '--tessdata-dir D:/ocr-data/tessdata'));
    }

    public function test_tesseract_is_off_when_the_program_is_missing_and_reports_missing_arabic_or_a_failure(): void
    {
        config(['identity_reader.driver' => 'tesseract']);
        Process::fake(['*' => Process::result('', 'not found', 127)]);
        $this->assertFalse(app(IdCardReader::class)->enabled());
        $this->assertSame('disabled', $this->failure(fn () => app(IdCardReader::class)->read(base64_decode(self::PNG), 'image/png')));

        Process::fake(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), '--version') ? Process::result('tesseract 5') : Process::result('', 'Failed loading language \'ara\'', 1));
        $this->assertSame('language_missing', $this->failure(fn () => app(IdCardReader::class)->read(base64_decode(self::PNG), 'image/png')));
        Process::fake(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), '--version') ? Process::result('tesseract 5') : Process::result('', 'crash', 2));
        $this->assertSame('engine_failed', $this->failure(fn () => app(IdCardReader::class)->read(base64_decode(self::PNG), 'image/png')));
    }

    public function test_a_pdf_needs_poppler_for_the_local_engines(): void
    {
        config(['identity_reader.driver' => 'tesseract']);
        $this->fakeTesseract($this->cardTsv());
        // pdftoppm "runs" but produces no pages: the engine says so instead of reading nothing.
        $this->assertSame('pdf_unsupported', $this->failure(fn () => app(IdCardReader::class)->read("%PDF-1.4\n%fake", 'application/pdf')));
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'pdftoppm'));
    }

    // ---------------------------------------------------------------- the review page

    public function test_the_ensemble_flags_what_the_model_got_wrong_with_full_confidence(): void
    {
        // The real trial: the model mixed scripts in the surname and reversed the digits of the registry, both "high", and repeated nothing a second reading could catch.
        config(['identity_reader.driver' => 'ensemble']);
        $this->fakeTesseract($this->cardTsv());
        Http::fake(['127.0.0.1:11434/*' => Http::response($this->ollamaAnswer([
            'last_name' => ['value' => 'زيidan', 'confidence' => 'high'], 'registration_place' => ['value' => 'حلب 5/1234', 'confidence' => 'high'],
        ]))]);

        $reader = app(IdCardReader::class);
        $this->assertTrue($reader->enabled());
        $result = $reader->read(base64_decode(self::PNG), 'image/png');

        $this->assertSame(3, $result['passes']);
        Http::assertSentCount(1);
        $lastName = $result['fields']['last_name'];
        $this->assertSame('low', $lastName['confidence']);
        $this->assertSame('زيدان', $lastName['value'], 'the value both Tesseract readings found is proposed');
        $this->assertSame(['زيidan'], $lastName['alternatives']);
        $place = $result['fields']['registration_place'];
        $this->assertSame('low', $place['confidence']);
        $this->assertSame('حلب 1234/5', $place['value']);
        $this->assertSame(['حلب 5/1234'], $place['alternatives']);
        // What the engines agree on stays as sure as the weakest reading: names are "medium" for Tesseract, the number "high".
        $this->assertSame('01020304050', $result['fields']['national_number']['value']);
        $this->assertSame('high', $result['fields']['national_number']['confidence']);
        $this->assertSame('medium', $result['fields']['father_name']['confidence']);
    }

    public function test_the_ensemble_needs_both_engines(): void
    {
        config(['identity_reader.driver' => 'ensemble']);
        Process::fake(['*' => Process::result('', 'not found', 127)]);
        $this->assertFalse(app(IdCardReader::class)->enabled());
        $this->fakeTesseract($this->cardTsv());
        config(['identity_reader.ollama.url' => '']);
        $this->assertFalse(app(IdCardReader::class)->enabled());
    }

    public function test_a_local_engine_has_no_daily_limit_logs_its_own_model_and_says_the_image_stays(): void
    {
        config(['identity_reader.driver' => 'ollama', 'identity_reader.passes' => 1]);
        Http::fake(['127.0.0.1:11434/*' => Http::response($this->ollamaAnswer())]);
        [$person, $attachment] = $this->personWithScan();
        $admin = WebAccount::where('username', 'super.admin')->firstOrFail();

        $page = Livewire::actingAs($admin)->test(IdentityReview::class, ['personId' => $person->id, 'attachmentId' => $attachment])
            ->assertSee('تُقرأ الصورة على هذا الخادم نفسه ولا تغادره')->assertSee('المحرك: نموذج رؤية محلي (Ollama)')->assertDontSee('القراءات المتبقية');
        $page->call('read')->assertSet('error', null)->assertSet('values.national_number', '01020304050');
        $page->call('read')->assertSet('error', null);   // the limit is 1 a day: it does not apply to a local engine
        $this->assertSame(2, DB::table('ai_queries')->where('dataset', 'identity')->where('model', 'qwen2.5vl:7b')->where('status', 'ok')->count());
    }

    public function test_each_engine_says_what_is_missing_when_it_is_not_set_up(): void
    {
        [$person, $attachment] = $this->personWithScan();
        $admin = WebAccount::where('username', 'super.admin')->firstOrFail();
        Process::fake(['*' => Process::result('', '', 127)]);

        foreach (['claude' => 'ANTHROPIC_API_KEY', 'tesseract' => 'برنامج Tesseract غير موجود', 'ensemble' => 'Tesseract وOllama'] as $driver => $text) {
            config(['identity_reader.driver' => $driver]);
            Livewire::actingAs($admin)->test(IdentityReview::class, ['personId' => $person->id, 'attachmentId' => $attachment])->assertSee('القراءة غير مفعّلة')->assertSee($text);
        }
        // Ollama is "on" once configured: whether the server answers is only known when reading.
        config(['identity_reader.driver' => 'ollama']);
        Http::fake(['127.0.0.1:11434/*' => fn () => throw new ConnectionException('refused')]);
        Livewire::actingAs($admin)->test(IdentityReview::class, ['personId' => $person->id, 'attachmentId' => $attachment])->call('read')->assertSet('error', 'unreachable');
    }
}
