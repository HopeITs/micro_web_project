<?php

namespace Tests\Feature;

use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** M4.20 user scenarios: staff download the blank official papers of their cities. */
class PaperLibraryScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    private function city(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function signIn(string $username, string $password = 'password'): void
    {
        $this->post('/ar/login', ['login' => (array_key_exists($username, RoleAccountsSeeder::accounts()) ? RoleAccountsSeeder::email($username) : null) ?? $username, 'password' => $password])->assertRedirect('/ar');
    }

    /** Installs one real catalogue entry with a stand-in file of the right hash. */
    private function install(string $form, string $city, ?string $place, string $variant, string $format): array
    {
        $entry = collect(config('papers.files'))->first(fn ($e) => $e['form'] === $form && $e['city'] === $city && $e['place'] === $place && $e['variant'] === $variant && $e['format'] === $format);
        $this->assertNotNull($entry, "catalogue has $form $city $variant $format");
        Storage::disk('local')->put('papers/'.$entry['sha256'].'.'.$format, 'blank '.$form);

        return $entry;
    }

    private function oneCityAccount(string $code, string $role = 'data-entry'): void
    {
        $account = app(AccountProvisioner::class)->createPlain('only.'.strtolower($code), 'only.'.strtolower($code).'@hcsyria.org', Hash::make('password'), 'test:papers');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city($code), $role, 'test:papers');
    }

    public function test_the_catalogue_covers_all_33_papers_and_is_clean(): void
    {
        $this->assertCount(33, config('papers.forms'));
        $this->assertCount(241, config('papers.files'));
        foreach (config('papers.files') as $entry) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $entry['sha256']);
            $this->assertContains($entry['city'], ['ALP', 'HMS', 'DAM', 'LAT', 'WAD']);
            $this->assertContains($entry['place'], [null, 'IDL']);
        }
        // Idlib is part of Aleppo; the misnamed LAT-folder contract is an Aleppo copy.
        $this->assertTrue(collect(config('papers.files'))->contains(fn ($e) => $e['city'] === 'ALP' && $e['place'] === 'IDL'));
        $this->assertFalse(collect(config('papers.files'))->contains(fn ($e) => $e['city'] === 'LAT' && str_contains($e['source'], 'ِLAT')));
    }

    public function test_administrator_sees_the_library_and_downloads_a_blank_paper(): void
    {
        $entry = $this->install('F10', 'DAM', null, 'syrian', 'docx');
        $this->signIn('super.admin');
        $this->get('/ar')->assertSee(route('papers.index', ['locale' => 'ar']));
        $page = $this->get('/ar/papers')->assertOk();
        $page->assertSee('الأوراق الرسمية');
        $page->assertSee('سند أمانة');
        $page->assertSee('دمشق');
        $this->get('/en/papers?variant=non_syrian')->assertOk()->assertSee('Promissory note')->assertSee('Non-Syrian');
        $this->get('/ar/papers?q=ZZZZ')->assertOk()->assertSee('لا توجد أوراق مطابقة');

        $response = $this->get('/ar/papers/download?'.http_build_query(['form' => 'F10', 'city' => 'DAM', 'variant' => 'syrian', 'format' => 'docx']));
        $response->assertOk()->assertDownload();
        $this->assertStringContainsString('F10', (string) $response->headers->get('content-disposition'));
        $this->assertDatabaseHas('account_audit_events', ['actor_account_id' => WebAccount::where('username', 'super.admin')->value('id'), 'event_type' => 'papers.downloaded', 'city_id' => $this->city('DAM')]);
        $this->assertSame('paper:F10:DAM:syrian:docx', DB::table('account_audit_events')->where('event_type', 'papers.downloaded')->value('decision_locator'));
    }

    public function test_staff_download_only_the_papers_of_their_cities(): void
    {
        $this->install('F10', 'DAM', null, 'syrian', 'docx');
        $this->install('F10', 'HMS', null, 'syrian', 'docx');
        $this->oneCityAccount('HMS');

        $this->signIn('only.hms@hcsyria.org');
        $html = $this->get('/ar/papers')->assertOk()->assertSee('حمص')->getContent();
        $this->assertStringNotContainsString('دمشق', strip_tags(explode('</form>', $html)[1] ?? $html));
        $this->get('/ar/papers/download?'.http_build_query(['form' => 'F10', 'city' => 'HMS', 'variant' => 'syrian', 'format' => 'docx']))->assertOk();
        $this->get('/ar/papers/download?'.http_build_query(['form' => 'F10', 'city' => 'DAM', 'variant' => 'syrian', 'format' => 'docx']))->assertForbidden();
        $this->assertDatabaseHas('account_audit_events', ['event_type' => 'access.denied', 'city_id' => $this->city('DAM')]);
        $this->get('/ar/papers?city=DAM')->assertOk();
    }

    public function test_idlib_papers_belong_to_aleppo_and_wadi_is_its_own_city(): void
    {
        $this->install('F03', 'ALP', 'IDL', 'syrian', 'pdf');
        $this->install('F03', 'ALP', null, 'syrian', 'pdf');
        $this->signIn('super.admin');
        $this->get('/en/papers?city=ALP&q=application')->assertOk()->assertSee('Idlib')->assertSee('Aleppo');
        $this->get('/ar/papers/download?'.http_build_query(['form' => 'F03', 'city' => 'ALP', 'place' => 'IDL', 'variant' => 'syrian', 'format' => 'pdf']))->assertOk();
        $this->get('/ar/papers/download?'.http_build_query(['form' => 'F03', 'city' => 'ALP', 'place' => 'IDL', 'variant' => 'syrian', 'format' => 'xls']))->assertSessionHasErrors('format');
    }

    public function test_a_paper_that_is_not_installed_is_not_served(): void
    {
        $this->signIn('super.admin');
        $this->get('/ar/papers')->assertOk()->assertSee('غير مثبتة');
        $this->get('/ar/papers/download?'.http_build_query(['form' => 'F10', 'city' => 'DAM', 'variant' => 'syrian', 'format' => 'docx']))->assertNotFound();
        $this->get('/ar/papers/download?'.http_build_query(['form' => 'F99', 'city' => 'DAM', 'variant' => 'syrian', 'format' => 'docx']))->assertNotFound();
        $this->get('/ar/papers/download?'.http_build_query(['form' => 'X1', 'city' => 'DAM', 'variant' => 'syrian', 'format' => 'docx']))->assertSessionHasErrors('form');
    }

    public function test_the_review_page_shows_the_original_beside_the_redraw_and_serves_the_original_inline(): void
    {
        $this->install('F03', 'DAM', null, 'syrian', 'pdf');
        $this->install('F10', 'DAM', null, 'syrian', 'docx');
        $this->signIn('super.admin');

        $this->get('/ar/papers')->assertOk()->assertSee(route('papers.review', ['locale' => 'ar', 'form' => 'F03']));
        $project = (int) DB::table('projects')->where('city_id', $this->city('DAM'))->where('application_stage', 'full')->whereNull('deleted_at')->orderByDesc('id')->value('id');
        $page = $this->get('/ar/papers/review/F03?city=DAM')->assertOk()->assertSee('مراجعة الرسم')->assertSee('الورقة الأصلية')->assertSee(route('applications.print', ['locale' => 'ar', 'project' => $project]));
        $page->assertSee('papers/original?', false);
        $this->get('/ar/papers/original?'.http_build_query(['form' => 'F03', 'city' => 'DAM', 'variant' => 'syrian', 'format' => 'pdf']))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        // A paper that is not redrawn has no review page; the original is also limited to the account's cities.
        $this->get('/ar/papers/review/F10')->assertNotFound();
    }

    public function test_the_review_page_and_original_respect_the_account_cities(): void
    {
        $this->install('F03', 'DAM', null, 'syrian', 'pdf');
        $this->install('F03', 'HMS', null, 'syrian', 'pdf');
        $this->oneCityAccount('HMS');

        $this->signIn('only.hms@hcsyria.org');
        $this->get('/ar/papers/review/F03')->assertOk()->assertSee('حمص')->assertDontSee('value="DAM"', false);
        $this->get('/ar/papers/original?'.http_build_query(['form' => 'F03', 'city' => 'DAM', 'variant' => 'syrian', 'format' => 'pdf']))->assertForbidden();
        $this->get('/ar/papers/original?'.http_build_query(['form' => 'F03', 'city' => 'HMS', 'variant' => 'syrian', 'format' => 'pdf']))->assertOk();
    }

    public function test_the_import_command_refuses_a_file_with_a_different_hash(): void
    {
        $dir = sys_get_temp_dir().'/papers-test-'.bin2hex(random_bytes(4));
        mkdir($dir);
        foreach (collect(config('papers.files'))->unique(fn ($e) => $e['sha256'].$e['format']) as $e) {
            file_put_contents($dir.'/'.$e['sha256'].'.'.$e['format'], 'not the right content');
        }
        $this->artisan('papers:import', ['folder' => $dir])->assertFailed();
        $this->assertSame([], Storage::disk('local')->files('papers'));
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }
}
