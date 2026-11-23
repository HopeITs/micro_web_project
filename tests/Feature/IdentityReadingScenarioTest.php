<?php

namespace Tests\Feature;

use App\Livewire\IdentityReview;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** User scenarios: staff read an identity document from an attachment, compare it with the image, and approve it onto the person. */
class IdentityReadingScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER = '01020304050';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        config(['identity_reader.api_key' => 'test-key', 'identity_reader.passes' => 2, 'identity_reader.daily_limit' => 60]);
    }

    private function city(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function admin(): WebAccount
    {
        return WebAccount::where('username', 'super.admin')->firstOrFail();
    }

    /** A person of Damascus with an identity scan attached (a tiny valid PNG, or a PDF). */
    private function personWithScan(string $extension = 'png'): array
    {
        $person = DB::table('people')->where('city_id', $this->city('DAM'))->whereNotNull('first_name')->orderBy('id')->first();
        $type = (int) DB::table('attachment_types')->where('name_en', 'like', 'IDs%')->value('id');
        $path = 'attachments/DAM/person-'.$person->id.'/scan-'.$extension.'.'.$extension;
        Storage::disk('local')->put($path, $extension === 'pdf' ? "%PDF-1.4\n%fake" : base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $attachment = DB::table('attachments')->insertGetId([
            'city_id' => $this->city('DAM'), 'person_id' => $person->id, 'attachment_type_id' => $type, 'original_name' => 'id.'.$extension, 'stored_path' => $path, 'extension' => $extension,
            'uploaded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$person, $attachment];
    }

    /** @param  array<string, mixed>  $fields */
    private function toolResponse(array $fields = [], string $type = 'national_id'): array
    {
        $base = ['first_name' => 'نبيل', 'last_name' => 'زيدان', 'father_name' => 'سليمان', 'mother_name' => 'ريما', 'birth_date' => '1986-04-12', 'registration_place' => 'حلب 1234/5',
            'national_number' => self::NUMBER, 'id_issue_text' => 'حلب', 'sex' => 'male', 'id_document_number' => null];
        $input = ['document_type' => $type, 'notes' => ''];
        foreach ($base as $field => $value) {
            $value = array_key_exists($field, $fields) ? $fields[$field] : $value;
            $input[$field] = is_array($value) ? $value : ['value' => $value, 'confidence' => 'high'];
        }

        return ['content' => [['type' => 'tool_use', 'id' => 'toolu_test', 'name' => 'record_identity', 'input' => $input]], 'usage' => ['input_tokens' => 1200, 'output_tokens' => 180]];
    }

    private function review(WebAccount $account, int $personId, int $attachmentId)
    {
        return Livewire::actingAs($account)->test(IdentityReview::class, ['personId' => $personId, 'attachmentId' => $attachmentId]);
    }

    public function test_without_a_key_the_page_says_so_and_nothing_is_sent(): void
    {
        config(['identity_reader.api_key' => null]);
        Http::fake();
        [$person, $attachment] = $this->personWithScan();

        $this->review($this->admin(), $person->id, $attachment)->assertSee('القراءة غير مفعّلة')->call('read')->assertSet('error', 'disabled');
        Http::assertNothingSent();
    }

    public function test_reading_a_scan_proposes_values_beside_the_image_and_logs_without_the_content(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->toolResponse())]);
        [$person, $attachment] = $this->personWithScan();

        $this->review($this->admin(), $person->id, $attachment)->call('read')
            ->assertSet('values.first_name', 'نبيل')->assertSet('values.national_number', self::NUMBER)->assertSet('values.birth_date', '1986-04-12')
            ->assertSet('values.nationality', 'syrian')->assertSet('values.id_document_type', 'national_id')
            ->assertSet('use.national_number', true)->assertSee('مؤكدة');

        // Two independent readings of the image, through the fixed tool, with the key in the header and nothing else about the person.
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->hasHeader('x-api-key', 'test-key') && $body['tool_choice']['name'] === 'record_identity'
                && $body['messages'][0]['content'][0]['type'] === 'image' && $body['messages'][0]['content'][0]['source']['media_type'] === 'image/png'
                && ! str_contains(json_encode($body, JSON_UNESCAPED_UNICODE), self::NUMBER);
        });
        $log = DB::table('ai_queries')->where('dataset', 'identity')->first();
        $this->assertSame('ok', $log->status);
        $this->assertSame('[identity document scan]', $log->prompt);
        $this->assertSame(2400, (int) $log->input_tokens);
        $this->assertDatabaseHas('account_audit_events', ['event_type' => 'identity.read', 'outcome' => 'succeeded', 'subject_id' => $person->id, 'decision_locator' => 'attachment:'.$attachment, 'city_id' => $this->city('DAM')]);
        $this->assertStringNotContainsString(self::NUMBER, json_encode(DB::table('account_audit_events')->get()));
        $this->assertStringNotContainsString(self::NUMBER, json_encode(DB::table('ai_queries')->get()));
    }

    public function test_a_pdf_scan_is_sent_as_a_document(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->toolResponse())]);
        [$person, $attachment] = $this->personWithScan('pdf');

        $this->review($this->admin(), $person->id, $attachment)->call('read')->assertSet('values.last_name', 'زيدان');
        Http::assertSent(fn (Request $request) => $request->data()['messages'][0]['content'][0]['type'] === 'document'
            && $request->data()['messages'][0]['content'][0]['source']['media_type'] === 'application/pdf');
    }

    public function test_readings_that_disagree_are_doubtful_unticked_and_offer_the_other_candidate(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push($this->toolResponse())->push($this->toolResponse(['national_number' => '01020304058']))]);
        [$person, $attachment] = $this->personWithScan();

        $page = $this->review($this->admin(), $person->id, $attachment)->call('read')
            ->assertSet('use.national_number', false)->assertSet('use.first_name', true)->assertSee('القراءتان مختلفتان')->assertSee('01020304058');
        $page->call('pick', 'national_number', '01020304058')->assertSet('values.national_number', '01020304058')->assertSet('use.national_number', true);
    }

    public function test_the_approved_values_are_saved_through_the_person_rules_and_the_audit_keeps_no_number(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->toolResponse())]);
        [$person, $attachment] = $this->personWithScan();

        $this->review($this->admin(), $person->id, $attachment)->call('read')->set('values.father_name', 'سليمان الأحمد')->set('use.mother_name', false)->call('save')->assertRedirect();

        $saved = DB::table('people')->where('id', $person->id)->first();
        $this->assertSame('نبيل', $saved->first_name);
        $this->assertSame(self::NUMBER, $saved->national_number);
        $this->assertSame('سليمان الأحمد', $saved->father_name, 'the edited value is what is saved');
        $this->assertSame('حلب 1234/5', $saved->registration_place);
        $this->assertSame('1986-04-12', substr((string) $saved->birth_date, 0, 10));
        $this->assertSame('syrian', $saved->nationality);
        $this->assertSame('national_id', $saved->id_document_type);
        $this->assertSame($person->mother_name, $saved->mother_name, 'an unticked value is not applied');
        $event = DB::table('account_audit_events')->where('event_type', 'people.updated')->latest('id')->first();
        $this->assertStringContainsString('"national_number","hidden":true', (string) $event->details);
        $this->assertStringNotContainsString(self::NUMBER, (string) $event->details);
    }

    public function test_a_national_number_that_another_person_has_needs_confirmation(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->toolResponse())]);
        [$person, $attachment] = $this->personWithScan();
        $other = DB::table('people')->where('city_id', $this->city('DAM'))->where('id', '<>', $person->id)->orderBy('id')->first();
        DB::table('people')->where('id', $other->id)->update(['national_number' => self::NUMBER]);

        $page = $this->review($this->admin(), $person->id, $attachment)->call('read')->call('save')->assertSet('needsConfirm', true)->assertNoRedirect();
        $this->assertNotSame(self::NUMBER, DB::table('people')->where('id', $person->id)->value('national_number'));
        $page->call('save', true)->assertRedirect();
        $this->assertSame(self::NUMBER, DB::table('people')->where('id', $person->id)->value('national_number'));
    }

    public function test_a_failing_service_changes_nothing_and_is_logged_as_an_error(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'x'], 500)]);
        [$person, $attachment] = $this->personWithScan();
        $before = DB::table('people')->where('id', $person->id)->first();

        $this->review($this->admin(), $person->id, $attachment)->call('read')->assertSet('error', 'http_500')->assertSet('reading', [])->assertSee('رفضت خدمة الرؤية');
        $this->assertEquals($before, DB::table('people')->where('id', $person->id)->first());
        $this->assertSame('error', DB::table('ai_queries')->where('dataset', 'identity')->value('status'));
        $this->assertDatabaseHas('account_audit_events', ['event_type' => 'identity.read', 'outcome' => 'failed']);
    }

    public function test_the_daily_limit_stops_further_readings(): void
    {
        config(['identity_reader.daily_limit' => 1, 'identity_reader.passes' => 1]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->toolResponse())]);
        [$person, $attachment] = $this->personWithScan();

        $page = $this->review($this->admin(), $person->id, $attachment)->call('read')->assertSet('error', null);
        $page->call('read')->assertSet('error', 'limit');
        Http::assertSentCount(1);
        $this->assertDatabaseHas('account_audit_events', ['event_type' => 'identity.read', 'outcome' => 'denied']);
    }

    public function test_only_staff_who_manage_people_in_the_city_can_open_it(): void
    {
        [$person] = $this->personWithScan();
        $this->get('/ar/people/'.$person->id.'/identity')->assertRedirect();

        $this->post('/ar/login', ['login' => \Database\Seeders\RoleAccountsSeeder::email('super.admin') ?? 'super.admin', 'password' => 'password'])->assertRedirect('/ar');
        $this->get('/ar/people/'.$person->id.'/identity')->assertOk()->assertSee('قراءة الهوية من المرفق');
        $this->get('/en/people/'.$person->id)->assertOk()->assertSee('Read identity document');
        $this->post('/ar/logout');

        // A Homs-only account cannot even see a Damascus person; a reader-only role cannot change one.
        $email = 'hms.clerk@hcsyria.org';
        $account = app(AccountProvisioner::class)->createPlain('hms.clerk', $email, Hash::make('password'), 'test:identity');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('HMS'), 'data-entry', 'test:identity');
        $this->post('/ar/login', ['login' => $email, 'password' => 'password'])->assertRedirect('/ar');
        $this->get('/ar/people/'.$person->id.'/identity')->assertNotFound();
        $guest = WebAccount::where('username', 'role.guest')->first();
        $this->actingAs($guest);
        $this->get('/ar/people/'.$person->id.'/identity')->assertStatus(403);
    }

    public function test_the_attachments_panel_offers_the_reader_only_for_readable_files(): void
    {
        [$person, $attachment] = $this->personWithScan();
        DB::table('attachments')->insert(['city_id' => $this->city('DAM'), 'person_id' => $person->id, 'original_name' => 'note.txt', 'stored_path' => 'x/note.txt', 'extension' => 'txt', 'uploaded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('local')->put('x/note.txt', 'hello');

        $html = Livewire::actingAs($this->admin())->test(\App\Livewire\AttachmentsPanel::class, ['owner' => 'person', 'ownerId' => $person->id])->html();
        $link = route('people.identity', ['locale' => 'ar', 'person' => $person->id, 'attachment' => $attachment]);
        $this->assertStringContainsString(htmlspecialchars($link, ENT_QUOTES), $html);
        $this->assertSame(1, substr_count($html, 'people/'.$person->id.'/identity'));
    }
}
