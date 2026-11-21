<?php

namespace Tests\Feature;

use App\Livewire\AttachmentsIndex;
use App\Livewire\AttachmentsPanel;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Support\CityContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** M4.11 user scenarios: each role adds, opens and deletes only the attachment categories the desktop gave it; person photos; imported files. */
class AttachmentScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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

    private function as(string $username): WebAccount
    {
        $account = WebAccount::where('username', $username)->firstOrFail();
        $this->actingAs($account);
        app(CityContext::class)->restrictTo(DB::table('account_city_capabilities')->where('account_id', $account->id)->where('capability', 'projects.view')->whereNull('revoked_at')->pluck('city_id'));

        return $account;
    }

    private function damascusOnly(string $role): WebAccount
    {
        $account = app(AccountProvisioner::class)->createPlain('dam.'.$role, 'dam.'.$role.'@hcsyria.org', Hash::make('a-long-test-password'), 'test:dam');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now()]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('DAM'), $role, 'test:dam');

        return $this->as('dam.'.$role);
    }

    private function project(string $code = 'ALP'): object
    {
        return DB::table('projects')->where('city_id', $this->city($code))->whereNull('deleted_at')->orderBy('id')->first();
    }

    private function type(string $nameEn): int
    {
        return (int) DB::table('attachment_types')->where('name_en', $nameEn)->value('id');
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function pdf(string $name = 'contract.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    /** Uploads through the panel as the current account and returns the new attachment ids. */
    private function upload(string $owner, int $id, int $type, array $files): array
    {
        $before = (int) DB::table('attachments')->max('id');
        Livewire::test(AttachmentsPanel::class, ['owner' => $owner, 'ownerId' => $id])
            ->set('uploadType', (string) $type)->set('files', $files)->call('saveFiles')->assertHasNoErrors();

        return DB::table('attachments')->where('id', '>', $before)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function test_data_entry_uploads_ids_to_a_project_and_opens_them(): void
    {
        $account = $this->as('role.data-entry');
        $project = $this->project();
        $this->get('/ar/projects/'.$project->id)->assertOk()->assertSeeLivewire(AttachmentsPanel::class);

        $ids = $this->upload('project', $project->id, $this->type('IDs (Personal - Family - Military)'), [$this->png('هوية.png'), $this->pdf('دفتر عائلة.pdf')]);
        $this->assertCount(2, $ids);
        $row = DB::table('attachments')->find($ids[0]);
        $this->assertSame('هوية.png', $row->original_name);
        $this->assertSame($project->id, (int) $row->project_id);
        $this->assertSame($account->id, (int) $row->uploaded_by_account_id);
        $this->assertStringStartsWith('attachments/ALP/project-'.$project->id.'/', $row->stored_path);
        Storage::disk('local')->assertExists($row->stored_path);

        // The list shows both files with their type, and the counts per type.
        Livewire::test(AttachmentsPanel::class, ['owner' => 'project', 'ownerId' => $project->id])
            ->assertSee('هوية.png')->assertSee('دفتر عائلة.pdf')->assertSee('هوية شخصية - دفتر عائلة - دفتر عسكرية');

        // Opening shows the image in the browser with safe headers; the download is recorded.
        $open = $this->get('/ar/attachments/'.$ids[0])->assertOk();
        $this->assertSame('image/png', $open->headers->get('Content-Type'));
        $this->assertSame('nosniff', $open->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('inline', (string) $open->headers->get('Content-Disposition'));
        $download = $this->get('/ar/attachments/'.$ids[1].'?download=1')->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'attachments.view')->where('decision_locator', 'attachment:'.$ids[1])->exists());

        // The audit log keeps who uploaded what, with the file name and type (never the content).
        $event = DB::table('account_audit_events')->where('event_type', 'attachments.uploaded')->where('decision_locator', 'attachment:'.$ids[0])->first();
        $this->assertSame('application_entity', $event->subject_type);
        $this->assertSame($project->id, (int) $event->subject_id);
        $this->assertStringContainsString('هوية.png', $event->details);
    }

    public function test_files_that_are_not_allowed_are_refused(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $type = (string) $this->type('Certificates');
        $panel = fn () => Livewire::test(AttachmentsPanel::class, ['owner' => 'project', 'ownerId' => $project->id])->set('uploadType', $type);

        $panel()->set('files', [UploadedFile::fake()->createWithContent('setup.exe', 'MZ...')])->call('saveFiles')->assertHasErrors('files');
        // A program renamed to .jpg: the content is checked, not only the name.
        $panel()->set('files', [UploadedFile::fake()->createWithContent('photo.jpg', "MZ\x90\x00 not an image")])->call('saveFiles')->assertHasErrors('files');
        $panel()->set('files', [UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf')])->call('saveFiles')->assertHasErrors('files');
        // No type chosen, or the personal photo type (set only from the person page, as in the desktop).
        Livewire::test(AttachmentsPanel::class, ['owner' => 'project', 'ownerId' => $project->id])->set('files', [$this->png()])->call('saveFiles')->assertHasErrors('uploadType');
        Livewire::test(AttachmentsPanel::class, ['owner' => 'project', 'ownerId' => $project->id])->set('uploadType', (string) $this->type('Profile'))->set('files', [$this->png()])->call('saveFiles')->assertHasErrors('uploadType');
        $this->assertSame(0, DB::table('attachments')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_each_role_sees_only_its_categories(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        [$id] = $this->upload('project', $project->id, $this->type('Trust Deed'), [$this->pdf('سند.pdf')]);

        // Financial: only financial papers; the legal paper is hidden and its link does not exist for this account.
        $this->as('role.financial');
        Livewire::test(AttachmentsPanel::class, ['owner' => 'project', 'ownerId' => $project->id])
            ->assertDontSee('سند.pdf')->assertSee('إيصال')->assertDontSee('عقد آجار');
        $this->get('/ar/attachments/'.$id)->assertNotFound();
        [$receipt] = $this->upload('project', $project->id, $this->type('Reciept'), [$this->png('إيصال 1.png')]);
        Livewire::test(AttachmentsPanel::class, ['owner' => 'project', 'ownerId' => $project->id])->call('delete', $id)->assertNotFound();
        $this->assertNull(DB::table('attachments')->find($id)->deleted_at);

        // Legal: the legal paper, not the receipt.
        $this->as('role.legal');
        Livewire::test(AttachmentsPanel::class, ['owner' => 'project', 'ownerId' => $project->id])->assertSee('سند.pdf')->assertDontSee('إيصال 1.png');
        $this->get('/ar/attachments/'.$receipt)->assertNotFound();

        // Guest and media: no attachments at all (the desktop refused them), not even the page.
        foreach (['role.guest', 'role.media'] as $who) {
            $this->as($who);
            Livewire::test(AttachmentsPanel::class, ['owner' => 'project', 'ownerId' => $project->id])->assertDontSee('سند.pdf')->assertDontSee(__('attachments.panel'));
            $this->get('/ar/attachments/'.$id)->assertNotFound();
            $this->get('/ar/attachments')->assertForbidden();
        }

        // Owner decision (21 Nov 2026): the administrator sees every category, financial included.
        $this->as('role.administrator');
        Livewire::test(AttachmentsPanel::class, ['owner' => 'project', 'ownerId' => $project->id])->assertSee('سند.pdf')->assertSee('إيصال 1.png');
        $this->get('/ar/attachments/'.$receipt)->assertOk();
        $this->get('/ar/attachments/'.$id)->assertOk();
    }

    public function test_another_city_cannot_open_or_upload(): void
    {
        $this->as('role.data-entry');
        $project = $this->project('ALP');
        [$id] = $this->upload('project', $project->id, $this->type('Certificates'), [$this->pdf()]);

        $this->damascusOnly('data-entry');
        $this->get('/ar/attachments/'.$id)->assertNotFound();
        Livewire::test(AttachmentsPanel::class, ['owner' => 'project', 'ownerId' => $project->id])->assertNotFound();
        Livewire::test(AttachmentsIndex::class)->assertDontSee('contract.pdf');
    }

    public function test_delete_is_permanent_and_kept_in_the_log(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        [$id] = $this->upload('project', $project->id, $this->type('Contracts and Approvals'), [$this->pdf('عقد.pdf')]);
        $stored = DB::table('attachments')->where('id', $id)->value('stored_path');

        // The button asks first, since the delete cannot be undone (owner decision, 21 Nov 2026).
        Livewire::test(AttachmentsPanel::class, ['owner' => 'project', 'ownerId' => $project->id])->assertSeeHtml('wire:confirm="'.e(__('attachments.confirm_delete')).'"')
            ->call('delete', $id)->assertSee(__('attachments.deleted'))->assertDontSee('عقد.pdf');
        $this->assertNull(DB::table('attachments')->find($id), 'the row is gone, as in the desktop');
        Storage::disk('local')->assertMissing($stored);
        $this->get('/ar/attachments/'.$id)->assertNotFound();
        $event = DB::table('account_audit_events')->where('event_type', 'attachments.deleted')->first();
        $this->assertSame('attachment:'.$id, $event->decision_locator);
        $this->assertStringContainsString('عقد.pdf', $event->details);
        $this->assertStringContainsString('أوراق قانونية - Legal', $event->details);
        // The audit page lists the action in words, linked to the project.
        $this->as('role.administrator');
        $this->get('/ar/admin/audit?area=attachments')->assertOk()->assertSee('حذف مرفق')->assertSee('رفع مرفق');
    }

    public function test_person_photo_is_set_replaced_and_kept_out_of_the_list(): void
    {
        $this->as('role.data-entry');
        $person = DB::table('people')->where('city_id', $this->city('HMS'))->orderBy('id')->first();
        $this->get('/ar/people/'.$person->id)->assertOk()->assertSeeLivewire(AttachmentsPanel::class)->assertSee(__('attachments.photo'));

        Livewire::test(AttachmentsPanel::class, ['owner' => 'person', 'ownerId' => $person->id])->set('photo', $this->png('me.png'))->assertHasNoErrors()->assertSee(__('attachments.photo_saved'));
        $first = (int) DB::table('attachments')->where('person_id', $person->id)->value('id');
        Livewire::test(AttachmentsPanel::class, ['owner' => 'person', 'ownerId' => $person->id])->set('photo', $this->png('me-2024.png'))->assertHasNoErrors();
        $photos = DB::table('attachments')->where('person_id', $person->id)->where('attachment_type_id', $this->type('Profile'))->orderBy('id')->get();
        $this->assertCount(2, $photos);
        $this->assertNotNull($photos[0]->deleted_at, 'the earlier photo is hidden');
        $this->assertNull($photos[1]->deleted_at);
        // The photo is shown on its own, not in the attachments list; a PDF is refused as a photo.
        Livewire::test(AttachmentsPanel::class, ['owner' => 'person', 'ownerId' => $person->id])
            ->assertSee(route('attachments.file', ['locale' => 'ar', 'attachment' => $photos[1]->id]))->assertDontSee('me-2024.png')
            ->set('photo', $this->pdf())->assertHasErrors('photo');
        $this->get('/ar/attachments/'.$first)->assertNotFound();

        // Legal may see the person, but not personal documents or the photo.
        $this->as('role.legal');
        Livewire::test(AttachmentsPanel::class, ['owner' => 'person', 'ownerId' => $person->id])->assertDontSee(__('attachments.photo'))
            ->set('photo', $this->png())->assertForbidden();
    }

    public function test_imported_desktop_files_are_listed_until_moved(): void
    {
        $project = $this->project('DAM');
        $now = now();
        $base = ['city_id' => $project->city_id, 'project_id' => $project->id, 'attachment_type_id' => $this->type('Invoice'), 'legacy_source' => 'image', 'created_at' => $now, 'updated_at' => $now];
        $ftp = DB::table('attachments')->insertGetId($base + ['legacy_id' => 501, 'original_name' => '12_img20190101_فاتورة.jpg', 'stored_path' => 'ftp/damascus/12_img20190101_فاتورة.jpg', 'extension' => 'jpg']);
        $escape = DB::table('attachments')->insertGetId($base + ['legacy_id' => 502, 'original_name' => 'x.jpg', 'stored_path' => '../../.env', 'extension' => 'jpg']);

        $this->as('role.financial');
        Livewire::test(AttachmentsPanel::class, ['owner' => 'project', 'ownerId' => $project->id])->assertSee('12_img20190101_فاتورة.jpg')->assertSee(__('attachments.not_moved'));
        $this->get('/ar/attachments/'.$ftp)->assertNotFound();
        $this->get('/ar/attachments/'.$escape)->assertNotFound();

        // Once the migration copies the file under the same path, it opens.
        Storage::disk('local')->put('ftp/damascus/12_img20190101_فاتورة.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01");
        $this->assertSame('image/jpeg', $this->get('/ar/attachments/'.$ftp)->assertOk()->headers->get('Content-Type'));
    }

    public function test_attachments_page_searches_across_permitted_cities(): void
    {
        $this->as('role.data-entry');
        $alp = $this->project('ALP');
        $lat = $this->project('LAT');
        $this->upload('project', $alp->id, $this->type('Certificates'), [$this->pdf('شهادة حلب.pdf')]);
        $this->upload('project', $lat->id, $this->type('Rent'), [$this->pdf('آجار اللاذقية.pdf')]);

        $this->get('/ar/attachments')->assertOk()->assertSeeLivewire(AttachmentsIndex::class);
        Livewire::test(AttachmentsIndex::class)->assertSee('شهادة حلب.pdf')->assertSee('آجار اللاذقية.pdf')
            ->set('search', 'حلب')->assertSee('شهادة حلب.pdf')->assertDontSee('آجار اللاذقية.pdf')
            ->set('search', '')->set('category', 'أوراق قانونية - Legal')->assertSee('آجار اللاذقية.pdf')->assertDontSee('شهادة حلب.pdf')
            ->set('category', '')->call('toggleCity', $this->city('ALP'))->assertSee('شهادة حلب.pdf')->assertDontSee('آجار اللاذقية.pdf');
        Livewire::test(AttachmentsIndex::class)->set('search', (string) $lat->project_number)->assertSee('آجار اللاذقية.pdf');

        $this->as('role.financial');
        Livewire::test(AttachmentsIndex::class)->assertDontSee('شهادة حلب.pdf')->assertDontSee('آجار اللاذقية.pdf');
    }
}
