<?php

namespace Tests\Feature;

use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Papers\PaperManager;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** M4.20 user scenarios: the administrator and media staff keep the papers (add, replace, remove, restore) so the team can use them. */
class PaperManageScenarioTest extends TestCase
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

    private function signIn(string $login): void
    {
        $this->post('/ar/login', ['login' => (array_key_exists($login, RoleAccountsSeeder::accounts()) ? RoleAccountsSeeder::email($login) : null) ?? $login, 'password' => 'password'])->assertRedirect('/ar');
    }

    private function account(string $name, string $city, string $role): string
    {
        $account = app(AccountProvisioner::class)->createPlain($name, $name.'@hcsyria.org', Hash::make('password'), 'test:papers');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city($city), $role, 'test:papers');

        return $name.'@hcsyria.org';
    }

    private function pdf(string $text = 'new blank paper'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('paper.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n% $text\n%%EOF");
    }

    private function docx(string $text = 'x', bool $macro = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<w:document>'.$text.'</w:document>');
        if ($macro) {
            $zip->addFromString('word/vbaProject.bin', 'macro');
        }
        $zip->close();
        $file = UploadedFile::fake()->createWithContent('w.docx', (string) file_get_contents($path));
        @unlink($path);

        return $file;
    }

    private function upload(array $data, UploadedFile $file)
    {
        return $this->post('/ar/papers/files', $data + ['place' => '', 'variant' => 'syrian', 'file' => $file]);
    }

    private function download(string $form, string $city, string $variant, string $format, ?string $place = null)
    {
        return $this->get('/ar/papers/download?'.http_build_query(array_filter(['form' => $form, 'city' => $city, 'place' => $place, 'variant' => $variant, 'format' => $format], fn ($v) => $v !== null)));
    }

    public function test_media_adds_a_new_paper_and_every_colleague_in_the_city_can_download_it(): void
    {
        $media = $this->account('media.alp', 'ALP', 'media');
        $team = $this->account('team.alp', 'ALP', 'data-entry');
        $this->signIn($media);
        $this->get('/ar/papers')->assertOk()->assertSee('إدارة الأوراق');
        $this->upload(['form' => 'new', 'city' => 'ALP', 'name_ar' => 'ورقة اختبار جديدة', 'name_en' => 'New test paper', 'group' => 'legal'], $this->pdf())
            ->assertRedirect()->assertSessionHas('status');
        $this->post('/ar/logout');

        $this->signIn($team);
        $page = $this->get('/ar/papers')->assertOk()->assertSee('ورقة اختبار جديدة')->assertSee('C01');
        $this->assertStringNotContainsString('إدارة الأوراق', $page->getContent());
        $this->download('C01', 'ALP', 'syrian', 'pdf')->assertOk()->assertDownload();
        $this->get('/en/papers')->assertOk()->assertSee('New test paper');
        // The team cannot change anything.
        $this->upload(['form' => 'C01', 'city' => 'ALP'], $this->pdf('team change'))->assertForbidden();
        $this->delete('/ar/papers/files', ['form' => 'C01', 'city' => 'ALP', 'variant' => 'syrian', 'format' => 'pdf'])->assertForbidden();
        $this->assertDatabaseHas('account_audit_events', ['event_type' => 'papers.uploaded', 'city_id' => $this->city('ALP'), 'decision_locator' => 'paper:C01:ALP:syrian:pdf']);
    }

    public function test_the_administrator_replaces_an_original_paper_and_can_bring_the_original_back(): void
    {
        $entry = collect(config('papers.files'))->first(fn ($e) => $e['form'] === 'F10' && $e['city'] === 'DAM' && $e['place'] === null && $e['variant'] === 'syrian' && $e['format'] === 'docx');
        Storage::disk('local')->put('papers/'.$entry['sha256'].'.docx', 'ORIGINAL');
        $this->signIn('super.admin');
        $this->download('F10', 'DAM', 'syrian', 'docx')->assertOk();

        $new = $this->docx('REVISED');
        $this->upload(['form' => 'F10', 'city' => 'DAM'], $new)->assertSessionHas('status');
        $served = $this->download('F10', 'DAM', 'syrian', 'docx')->assertOk()->assertDownload();
        $this->assertSame(hash_file('sha256', $new->getRealPath()), hash('sha256', $served->streamedContent()), 'the uploaded file is what is served');
        $this->get('/ar/papers?city=DAM&q=سند')->assertOk()->assertSee('معدَّل');

        $this->post('/ar/papers/files/restore', ['form' => 'F10', 'city' => 'DAM', 'variant' => 'syrian', 'format' => 'docx'])->assertSessionHas('status');
        $back = $this->download('F10', 'DAM', 'syrian', 'docx')->assertOk();
        $this->assertSame('ORIGINAL', $back->streamedContent());
        // The replacement file is no longer kept once nothing uses it.
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->files('papers'), fn ($f) => str_contains($f, hash_file('sha256', $new->getRealPath())))));
        $this->assertDatabaseHas('account_audit_events', ['event_type' => 'papers.restored', 'decision_locator' => 'paper:F10:DAM:syrian:docx']);
    }

    public function test_removing_an_original_hides_it_and_it_can_be_restored(): void
    {
        $entry = collect(config('papers.files'))->first(fn ($e) => $e['form'] === 'F10' && $e['city'] === 'HMS' && $e['place'] === null && $e['variant'] === 'syrian' && $e['format'] === 'pdf'
            || $e['form'] === 'F10' && $e['city'] === 'HMS' && $e['variant'] === 'syrian' && $e['place'] === null);
        $this->assertNotNull($entry);
        Storage::disk('local')->put('papers/'.$entry['sha256'].'.'.$entry['format'], 'ORIGINAL');
        $media = $this->account('media.hms', 'HMS', 'media');
        $this->signIn($media);
        $this->download('F10', 'HMS', 'syrian', $entry['format'])->assertOk();

        $this->delete('/ar/papers/files', ['form' => 'F10', 'city' => 'HMS', 'variant' => 'syrian', 'format' => $entry['format']])->assertSessionHas('status');
        $this->download('F10', 'HMS', 'syrian', $entry['format'])->assertNotFound();
        $this->get('/ar/papers')->assertOk()->assertSee('ملفات أصلية أُزيلت');
        // The original blob stays on disk: it belongs to the owner's catalogue.
        $this->assertTrue(Storage::disk('local')->exists('papers/'.$entry['sha256'].'.'.$entry['format']));

        $this->post('/ar/papers/files/restore', ['form' => 'F10', 'city' => 'HMS', 'variant' => 'syrian', 'format' => $entry['format']]);
        $this->download('F10', 'HMS', 'syrian', $entry['format'])->assertOk();
        $this->assertDatabaseHas('account_audit_events', ['event_type' => 'papers.removed', 'decision_locator' => 'paper:F10:HMS:syrian:'.$entry['format']]);
    }

    public function test_media_of_one_city_cannot_change_another_citys_papers(): void
    {
        $media = $this->account('media.hms2', 'HMS', 'media');
        $this->signIn($media);
        $this->upload(['form' => 'new', 'city' => 'ALP', 'name_ar' => 'x', 'group' => 'legal'], $this->pdf())->assertForbidden();
        $this->assertDatabaseHas('account_audit_events', ['event_type' => 'access.denied', 'city_id' => $this->city('ALP')]);
        $this->assertFalse(Storage::disk('local')->exists('papers/changes.json'));
        // Only the cities they manage are offered.
        $html = $this->get('/ar/papers')->assertOk()->getContent();
        $this->assertStringContainsString('إدارة الأوراق', $html);
    }

    public function test_files_that_are_not_safe_papers_are_refused(): void
    {
        $this->signIn('super.admin');
        $base = ['form' => 'new', 'city' => 'ALP', 'name_ar' => 'ورقة', 'group' => 'legal'];
        $fake = UploadedFile::fake()->createWithContent('paper.pdf', 'just text pretending to be a pdf');
        $this->upload($base, $fake)->assertSessionHasErrors('file');
        $this->upload($base, UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n/OpenAction << /S /JavaScript /JS (app.alert(1)) >>\n%%EOF"))->assertSessionHasErrors('file');
        $this->upload($base, $this->docx('x', macro: true))->assertSessionHasErrors('file');
        $this->upload($base, UploadedFile::fake()->createWithContent('a.exe', "MZ\x90\x00"))->assertSessionHasErrors('file');
        // A wrong place (Idlib belongs to Aleppo) and an unknown paper id are refused too.
        $this->upload(['form' => 'new', 'city' => 'HMS', 'place' => 'IDL', 'name_ar' => 'ورقة', 'group' => 'legal'], $this->pdf())->assertSessionHasErrors('file');
        $this->upload(['form' => 'F99', 'city' => 'ALP'], $this->pdf())->assertSessionHasErrors('file');
        $this->upload(['form' => 'new', 'city' => 'ALP', 'group' => 'legal'], $this->pdf())->assertSessionHasErrors('name_ar');
        $this->assertFalse(Storage::disk('local')->exists('papers/changes.json'));
        $this->assertSame([], app(PaperManager::class)->cities(WebAccount::where('username', 'super.admin')->first())->isEmpty() ? ['no cities'] : []);
    }

    public function test_an_added_paper_disappears_with_its_last_file_and_a_new_file_replaces_the_same_slot(): void
    {
        $this->signIn('super.admin');
        $this->upload(['form' => 'new', 'city' => 'LAT', 'name_ar' => 'ورقة مؤقتة', 'group' => 'study'], $this->pdf('one'))->assertSessionHas('status');
        $this->upload(['form' => 'C01', 'city' => 'LAT'], $this->pdf('two'))->assertSessionHas('status');
        $this->assertCount(1, json_decode((string) Storage::disk('local')->get('papers/changes.json'), true)['files']);
        $this->get('/ar/papers?city=LAT')->assertOk()->assertSee('ورقة مؤقتة');
        $this->delete('/ar/papers/files', ['form' => 'C01', 'city' => 'LAT', 'variant' => 'syrian', 'format' => 'pdf'])->assertSessionHas('status');
        $this->get('/ar/papers?city=LAT')->assertOk()->assertDontSee('ورقة مؤقتة');
        $this->assertSame([], json_decode((string) Storage::disk('local')->get('papers/changes.json'), true)['forms']);
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->files('papers'), fn ($f) => str_ends_with($f, '.pdf'))));
        // Removing something that is not there is a plain not-found.
        $this->delete('/ar/papers/files', ['form' => 'C01', 'city' => 'LAT', 'variant' => 'syrian', 'format' => 'pdf'])->assertNotFound();
    }

    public function test_roles_that_keep_the_papers_are_administrator_and_media_only(): void
    {
        $this->assertContains('papers.manage', AccessRoles::capabilities('administrator'));
        $this->assertContains('papers.manage', AccessRoles::capabilities('media'));
        foreach (['data-entry', 'financial', 'legal', 'guest', 'monitoring', 'communication'] as $role) {
            $this->assertNotContains('papers.manage', AccessRoles::capabilities($role), $role);
        }
    }
}
