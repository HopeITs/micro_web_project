<?php

namespace Tests\Feature;

use App\Livewire\AttachmentsPanel;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountAccess;
use App\Services\Applications\ApplicationService;
use App\Services\ProjectNumberAllocator;
use App\Support\ProjectWorkspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The restructured project page (25 Nov 2026) as each role meets it: only the modules and papers it may open, every fact once
 * (above all the status), what needs attention computed from the saved data, and the upload area in the reader's language.
 */
class ProjectPageScenarioTest extends TestCase
{
    use RefreshDatabase;

    private int $city;

    private int $project;

    private int $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $this->city = (int) DB::table('cities')->where('code', 'DAM')->value('id');
        $this->project = (int) DB::table('projects')->where('city_id', $this->city)->where('application_stage', 'full')->whereNull('deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('project_applicants as a')->whereColumn('a.project_id', 'projects.id'))->orderByDesc('id')->value('id');
        $this->person = (int) DB::table('project_applicants')->where('project_id', $this->project)->whereNull('deleted_at')
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [ApplicationService::BENEFICIARY])->orderBy('id')->value('person_id');
    }

    private function signIn(string $name): WebAccount
    {
        Auth::guard()->logout();
        $this->flushSession();
        $account = WebAccount::where('username', $name)->firstOrFail();
        $this->actingAs($account);

        return $account;
    }

    private function page(string $locale = 'ar'): string
    {
        return $this->get('/'.$locale.'/projects/'.$this->project)->assertOk()->getContent();
    }

    /** The words a reader sees inside the page's own content (no attributes, no shell). */
    private function words(string $html): string
    {
        $from = strpos($html, '<div class="hc-pj"');
        $this->assertNotFalse($from);

        return strip_tags(substr($html, $from, strpos($html, '</main>', $from) - $from));
    }

    private function caritas(): void
    {
        $donor = DB::table('donors')->insertGetId(['name_ar' => 'Caritas Poland', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('projects')->where('id', $this->project)->update(['donor_id' => $donor]);
    }

    /** @return array<string, bool> link => should this account see it (the rules the page had before the restructure) */
    private function expected(WebAccount $account): array
    {
        $can = fn (string $capability) => app(AccountAccess::class)->check($account, $this->city, $capability);
        $views = $can('applications.view');
        $base = '/ar/applications/'.$this->project;
        $finance = $can('finance.view') && $views;
        $purchasing = $views && $can('purchasing.view');
        $links = [
            $base.'/edit' => app(ApplicationService::class)->canWrite($account, $this->city),
            $base.'/checklist' => $can('assessments.view'),
            $base.'/finance' => $finance,
            $base.'/visits' => $views && ($can('visits.view') || $can('me_visits.view')),
            $base.'/special-cases' => $views && $can('special_cases.view'),
            $base.'/executive-file' => $views && $can('executive_files.view'),
            $base.'/purchases' => $purchasing,
            $base.'/paper-data' => $finance,
            $base.'/deeds' => $finance,
            $base.'/purchase-data' => $purchasing,
            $base.'/sale-contracts' => $purchasing,
            $base.'/print' => $views,
            $base.'/file/print' => $views,
            $base.'/papers/special-case' => $views && $can('special_cases.view'),
        ];
        foreach (['audio-consent', 'experience-test', 'photo-informed', 'photo-adult', 'photo-child'] as $paper) {
            $links[$base.'/papers/'.$paper] = $views;
        }
        foreach (['promissory-note', 'clearance', 'disbursement-request', 'partnership-contract', 'beneficiary-card'] as $paper) {
            $links[$base.'/finance-papers/'.$paper] = $finance;
        }
        foreach (['purchase-request', 'price-quote', 'equipment-receipt', 'equipment-return'] as $paper) {
            $links[$base.'/purchase-papers/'.$paper] = $purchasing;
        }

        return $links;
    }

    private function assertLinks(WebAccount $account, string $who): void
    {
        $html = $this->page();
        foreach ($this->expected($account) as $link => $shown) {
            $this->assertSame($shown, str_contains($html, $link.'"'), $who.' · '.$link);
        }
        $tracks = app(AccountAccess::class)->check($account, $this->city, 'applications.view');
        $this->assertSame($tracks, str_contains($html, 'id="pj-tab-progress"'), $who.' · state tab');
        $this->assertSame($tracks, str_contains($html, 'id="pj-tab-papers"'), $who.' · papers tab');
    }

    public function test_every_role_sees_exactly_the_modules_and_papers_it_may_open(): void
    {
        $this->caritas();
        foreach (array_keys(AccessRoles::ROLES) as $role) {
            $this->assertLinks($this->signIn('role.'.$role), $role);
        }
    }

    public function test_each_capability_switches_off_only_its_own_links(): void
    {
        $this->caritas();
        foreach (['applications.view', 'finance.view', 'special_cases.view', 'purchasing.view', 'executive_files.view', 'visits.view', 'me_visits.view', 'assessments.view', 'applications.manage'] as $capability) {
            $account = $this->signIn('super.admin');
            $grant = DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', $this->city)->where('capability', $capability);
            (clone $grant)->update(['revoked_at' => now()]);
            $this->assertLinks($account, 'without '.$capability);
            $grant->update(['revoked_at' => null]);
        }
    }

    public function test_the_caritas_consents_show_only_for_a_caritas_project(): void
    {
        $this->signIn('super.admin');
        $donor = DB::table('donors')->insertGetId(['name_ar' => 'مانح آخر', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('projects')->where('id', $this->project)->update(['donor_id' => $donor]);
        $base = '/ar/applications/'.$this->project.'/papers/';
        $html = $this->page();
        $this->assertStringContainsString($base.'photo-informed"', $html);
        $this->assertStringNotContainsString($base.'photo-adult"', $html);
        $this->assertStringNotContainsString($base.'photo-child"', $html);
    }

    public function test_the_status_the_number_and_the_applicant_are_written_once(): void
    {
        $state = (int) DB::table('project_states')->where('name_en', 'Delayed')->value('id');
        DB::table('project_states')->where('id', $state)->update(['name_ar' => 'حالةفريدة']);
        DB::table('project_state_history')->where('project_id', $this->project)->delete();
        DB::table('project_state_history')->insert(['city_id' => $this->city, 'project_id' => $this->project, 'project_state_id' => $state, 'is_current' => true, 'changed_on' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('people')->where('id', $this->person)->update(['first_name' => 'اسمفريد', 'national_number' => '09990001112', 'mobile' => '0944000111']);
        DB::table('projects')->where('id', $this->project)->update(['name' => 'مشروع التجربة', 'legacy_id' => null]);
        $number = ProjectNumberAllocator::display('DAM', (int) DB::table('projects')->where('id', $this->project)->value('project_number'));

        foreach (['super.admin', 'role.administrator', 'role.data-entry', 'role.financial', 'role.guest'] as $who) {
            $this->signIn($who);
            $html = $this->page();
            $words = $this->words($html);
            foreach (['حالةفريدة', 'اسمفريد', '09990001112', '0944000111', $number, 'مشروع التجربة'] as $fact) {
                $this->assertSame(1, substr_count($words, $fact), $who.' · '.$fact);
            }
            $this->assertSame(1, substr_count($html, 'data-project-status='), $who);
            $this->assertStringContainsString('hc-stages is-paused', $html);
            $this->assertStringNotContainsString('hc-stage-tag', $html);
            $this->assertStringContainsString('href="tel:0944000111"', $html);
        }
    }

    public function test_the_applicant_links_to_the_person_page_for_those_who_read_people(): void
    {
        $account = $this->signIn('super.admin');
        $this->assertStringContainsString('/ar/people/'.$this->person.'"', $this->page());

        DB::table('account_city_capabilities')->where('account_id', $account->id)->where('capability', 'people.view')->update(['revoked_at' => now()]);
        $this->assertStringNotContainsString('/ar/people/'.$this->person.'"', $this->page());
    }

    public function test_needs_attention_lists_only_what_the_saved_data_shows(): void
    {
        DB::table('people')->where('id', $this->person)->update(['national_number' => null, 'mobile' => null]);
        DB::table('project_scores')->where('project_id', $this->project)->delete();
        DB::table('attachments')->where('project_id', $this->project)->delete();
        DB::table('loans')->where('project_id', $this->project)->update(['deleted_at' => now()]);
        $loan = DB::table('loans')->insertGetId(['city_id' => $this->city, 'project_id' => $this->project, 'currency' => 'SYP', 'amount' => 1000000, 'taken_on' => now()->subYear(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[now()->subMonths(2), false], [now()->subMonth(), true], [now(), false], [now()->addMonth(), false]] as [$due, $paid]) {
            DB::table('loan_installments')->insert(['city_id' => $this->city, 'loan_id' => $loan, 'amount' => 250000, 'due_on' => $due->toDateString(), 'is_paid' => $paid, 'created_at' => now(), 'updated_at' => now()]);
        }

        // The administrator is offered the way to fix each item.
        $this->signIn('super.admin');
        $html = $this->page();
        foreach (['overdue', 'national', 'mobile', 'scores', 'attachments'] as $key) {
            $this->assertStringContainsString('data-attention="'.$key.'"', $html, $key);
        }
        // Only the instalment due before today counts (the finance module's rule), not the one due today or the paid one.
        $this->assertStringContainsString(trans_choice('project_page.attention.overdue', 1, ['count' => 1, 'money' => ProjectWorkspace::money(250000, 'SYP')], 'ar'), $html);
        $this->assertStringContainsString('/ar/people/'.$this->person.'/edit"', $html);
        // With the finance module the loan is in its tile and page, not repeated as a table here.
        $this->assertStringContainsString('data-module="finance"', $html);
        $this->assertStringNotContainsString('id="installments"', $html);
        $this->assertStringContainsString(__('project_page.tile.paid', ['percent' => 25], 'ar'), $html);

        // A reader without those rights sees the facts, with no link to change them; the loan has its one place on this page.
        $account = $this->signIn('role.guest');
        $html = $this->page();
        $this->assertStringContainsString('data-attention="overdue"', $html);
        $this->assertStringContainsString('data-attention="national"', $html);
        $this->assertStringNotContainsString('/edit"', $html);
        $this->assertFalse(app(AccountAccess::class)->check($account, $this->city, 'finance.view'));
        $this->assertStringContainsString('id="installments"', $html);
        $this->assertStringContainsString('data-smart="off"', $html);
        $this->assertStringNotContainsString('data-module="finance"', $html);
        // No attachment grants: nothing is said about attachments.
        $this->assertStringNotContainsString('data-attention="attachments"', $html);
        $this->assertStringNotContainsString('id="pj-tab-attachments"', $html);

        // Complete data: the list is gone.
        DB::table('people')->where('id', $this->person)->update(['national_number' => '09990001112', 'mobile' => '0944000111']);
        DB::table('loan_installments')->where('loan_id', $loan)->update(['is_paid' => true]);
        $criterion = (int) DB::table('score_criteria')->orderBy('sort_order')->value('id');
        DB::table('project_scores')->insert(['city_id' => $this->city, 'project_id' => $this->project, 'score_criterion_id' => $criterion, 'value' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('attachments')->insert(['city_id' => $this->city, 'project_id' => $this->project, 'original_name' => 'a.pdf', 'stored_path' => 'ftp/a.pdf', 'extension' => 'pdf', 'legacy_source' => 'file', 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn('super.admin');
        $this->assertStringNotContainsString('hc-pj-attention', $this->page());
    }

    public function test_the_tiles_carry_the_live_summary_of_each_module(): void
    {
        foreach (['visits', 'me_visits', 'special_cases', 'purchases', 'executive_files'] as $table) {
            DB::table($table)->where('project_id', $this->project)->update(['deleted_at' => now()]);
        }
        DB::table('loans')->where('project_id', $this->project)->update(['deleted_at' => now()]);
        DB::table('visits')->insert(['city_id' => $this->city, 'project_id' => $this->project, 'kind' => 'O-op', 'visit_date' => '2026-03-04', 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn('super.admin');
        $this->get('/ar/projects/'.$this->project)->assertOk()
            ->assertSee(__('hc.no_loan', [], 'ar'))
            ->assertSee(trans_choice('project_page.tile.visits', 1, ['count' => 1], 'ar'))
            ->assertSee(__('project_page.tile.last', ['date' => '2026-03-04'], 'ar'))
            ->assertSee(trans_choice('project_page.tile.cases', 0, ['count' => 0], 'ar'))
            ->assertSee(__('project_page.tile.executive_none', [], 'ar'));
        $this->get('/en/projects/'.$this->project)->assertOk()->assertSee('One visit')->assertSee('Papers and printing')->assertSee('Choose files');
    }

    public function test_the_upload_area_speaks_the_page_language_and_reports_beside_the_field(): void
    {
        $this->signIn('role.data-entry');
        $this->get('/ar/projects/'.$this->project)->assertOk()->assertSee('اختر ملفات')->assertSee(__('attachments.drop', [], 'ar'))->assertSee('hc-drop-input', false);

        app()->setLocale('ar');
        $type = (string) DB::table('attachment_types')->where('name_en', 'Certificates')->value('id');
        $panel = Livewire::test(AttachmentsPanel::class, ['owner' => 'project', 'ownerId' => $this->project, 'counted' => false])
            ->set('uploadType', $type)
            ->set('files', [UploadedFile::fake()->createWithContent('وثيقة.pdf', "%PDF-1.4\n%test\n")])
            ->assertSee('وثيقة.pdf')->assertSee(__('attachments.remove', ['name' => 'وثيقة.pdf']));
        $panel->call('saveFiles')->assertHasNoErrors()->assertDispatched('attachments-changed');
        $panel->set('files', [UploadedFile::fake()->createWithContent('أخرى.pdf', "%PDF-1.4\n")])->call('removeFile', 0)->assertDontSee('أخرى.pdf');
        // A failed temporary upload is told in Arabic under the files field.
        $panel->call('_uploadErrored', 'files', null, true)->assertHasErrors('files')->assertSee(__('attachments.errors.upload', ['max' => 10]));
    }

    public function test_a_project_with_only_the_list_right_shows_no_action_it_cannot_use(): void
    {
        $account = $this->signIn('super.admin');
        DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', $this->city)->where('capability', '<>', 'projects.view')->update(['revoked_at' => now()]);
        $html = $this->page();
        $this->assertStringContainsString('id="pj-tab-overview"', $html);
        foreach (['pj-tab-progress', 'pj-tab-papers', 'pj-tab-attachments', 'data-module="finance"', 'data-module="checklist"', 'data-paper='] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        $this->assertStringNotContainsString('/ar/applications/'.$this->project.'/edit', $html);
    }
}
