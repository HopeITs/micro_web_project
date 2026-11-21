<?php

namespace Tests\Feature;

use App\Livewire\ExecutiveFileEditor;
use App\Models\WebAccount;
use App\Services\Finance\ExecutiveFileService;
use App\Services\Search\GlobalSearch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M4.10 user scenarios: legal creates, edits and deletes an executive file and the legal accounts receive, follow and lose the notice;
 * the administrator may do the same; financial reads only; data entry and guest are refused; a second file and another city are refused.
 */
class ExecutiveFileScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function as(string $username): WebAccount
    {
        $account = WebAccount::where('username', $username)->firstOrFail();
        $this->actingAs($account);

        return $account;
    }

    /** A project with a beneficiary and no executive file or notices. */
    private function project(): object
    {
        $project = DB::table('projects')->whereExists(fn ($q) => $q->selectRaw('1')->from('project_applicants as a')->whereColumn('a.project_id', 'projects.id'))->orderBy('id')->first();
        DB::table('executive_files')->where('project_id', $project->id)->delete();
        DB::table('web_notices')->where('project_id', $project->id)->delete();

        return $project;
    }

    private function create(object $project, array $overrides = []): void
    {
        $page = Livewire::test(ExecutiveFileEditor::class, ['projectId' => $project->id]);
        foreach (($overrides + ['form.number' => 'EX-77', 'form.begin_date' => '2026-01-31', 'form.months' => '1', 'form.impound_date' => '2026-02-01', 'form.impound_type' => 'عقار']) as $key => $value) {
            $page->set($key, $value);
        }
        $page->call('save')->assertHasNoErrors();
    }

    private function file(int $projectId): ?object
    {
        return DB::table('executive_files')->where('project_id', $projectId)->whereNull('deleted_at')->first();
    }

    private function notices(int $projectId)
    {
        return DB::table('web_notices')->where('project_id', $projectId)->where('kind', 'executive_file');
    }

    public function test_legal_creates_edits_and_deletes_the_file_and_the_legal_accounts_follow_the_notice(): void
    {
        $this->as('role.legal');
        $project = $this->project();
        $this->get('/ar/projects/'.$project->id)->assertOk()->assertSee(route('executive.show', ['locale' => 'ar', 'project' => $project->id]));
        $this->get('/ar/applications/'.$project->id.'/executive-file')->assertOk()->assertSeeLivewire(ExecutiveFileEditor::class)->assertSee(__('executive.create', [], 'ar'));
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'executive_files.view')->where('subject_id', $project->id)->exists());

        // A new file's current date follows the begin date; the expiry is current date + months with the day clamped to the month end.
        $page = Livewire::test(ExecutiveFileEditor::class, ['projectId' => $project->id])->set('form.begin_date', '2026-01-31')->set('form.months', '1');
        $this->assertSame('2026-01-31', $page->get('form.current_date'));
        $page->assertSee('2026-02-28')->set('form.number', 'EX-77')->set('form.impound_date', '2026-02-01')->set('form.impound_type', 'عقار')->call('save')->assertHasNoErrors()
            ->assertSee(__('executive.created', [], 'ar'))->assertDontSee(__('executive.no_legal', [], 'ar'));
        $file = $this->file($project->id);
        $this->assertSame('EX-77', $file->file_number);
        $this->assertSame('2026-01-31', substr((string) $file->current_on, 0, 10));
        $this->assertSame(1, (int) $file->months);

        $legal = DB::table('account_city_roles')->where('city_id', $project->city_id)->where('role', 'legal')->whereNull('revoked_at')->count();
        $this->assertGreaterThan(0, $legal);
        $this->assertSame($legal, $this->notices($project->id)->count(), 'One notice per legal account.');
        $this->assertSame(['2026-02-28'], $this->notices($project->id)->pluck('notice_date')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values()->all());
        $this->assertSame([false], $this->notices($project->id)->pluck('seen')->map(fn ($v) => (bool) $v)->unique()->values()->all());
        $event = DB::table('account_audit_events')->where('event_type', 'executive_files.saved')->latest('id')->first();
        $this->assertStringContainsString('"field":"file_number"', (string) $event->details);
        $this->assertStringContainsString('"field":"expiry_date"', (string) $event->details);

        // Editing moves the notices to the new expiry date and keeps their number.
        Livewire::test(ExecutiveFileEditor::class, ['projectId' => $project->id])->assertSet('form.number', 'EX-77')->set('form.months', '6')->set('form.current_date', '2026-03-01')
            ->call('save')->assertHasNoErrors()->assertSee(__('executive.saved', [], 'ar'));
        $this->assertSame(['2026-09-01'], $this->notices($project->id)->pluck('notice_date')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values()->all());
        $this->assertSame($legal, $this->notices($project->id)->count());
        $this->assertSame(1, DB::table('executive_files')->where('project_id', $project->id)->count());

        // Deleting is soft, confirmed in the page, audited with details, and removes the notices.
        Livewire::test(ExecutiveFileEditor::class, ['projectId' => $project->id])->call('delete')->assertHasNoErrors()->assertSee(__('executive.deleted', [], 'ar'));
        $this->assertNull($this->file($project->id));
        $this->assertNotNull(DB::table('executive_files')->where('project_id', $project->id)->value('deleted_at'));
        $this->assertSame(0, $this->notices($project->id)->count());
        $event = DB::table('account_audit_events')->where('event_type', 'executive_files.deleted')->latest('id')->first();
        $this->assertStringContainsString('"field":"notices_removed"', (string) $event->details);

        // After a deletion the project may get a new file.
        $this->create($project, ['form.number' => 'EX-78']);
        $this->assertSame('EX-78', $this->file($project->id)->file_number);
    }

    public function test_administrator_may_create_and_delete_too(): void
    {
        $this->as('role.administrator');
        $project = $this->project();
        $this->create($project);
        $this->assertNotNull($this->file($project->id));
        Livewire::test(ExecutiveFileEditor::class, ['projectId' => $project->id])->call('delete')->assertHasNoErrors();
        $this->assertNull($this->file($project->id));
    }

    public function test_financial_reads_only_and_data_entry_and_guest_are_refused(): void
    {
        $this->as('role.legal');
        $project = $this->project();
        $this->create($project);

        $this->as('role.financial');
        $this->get('/en/applications/'.$project->id.'/executive-file')->assertOk()->assertSee(__('executive.read_only', [], 'en'))->assertSee('EX-77')->assertDontSee(__('executive.save', [], 'en'));
        $this->get('/ar/projects/'.$project->id)->assertOk()->assertSee(route('executive.show', ['locale' => 'ar', 'project' => $project->id]));
        Livewire::test(ExecutiveFileEditor::class, ['projectId' => $project->id])->set('form.number', 'HACK')->call('save')->assertForbidden();
        Livewire::test(ExecutiveFileEditor::class, ['projectId' => $project->id])->call('delete')->assertForbidden();
        $this->assertSame('EX-77', $this->file($project->id)->file_number);

        foreach (['role.data-entry', 'role.guest'] as $user) {
            $this->as($user);
            $this->get('/ar/applications/'.$project->id.'/executive-file')->assertForbidden();
            $this->get('/ar/projects/'.$project->id)->assertOk()->assertDontSee(route('executive.show', ['locale' => 'ar', 'project' => $project->id]));
        }
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'access.denied')->exists());
    }

    public function test_a_second_file_and_a_stale_page_are_refused(): void
    {
        $this->as('role.legal');
        $project = $this->project();
        $stale = Livewire::test(ExecutiveFileEditor::class, ['projectId' => $project->id]);
        $this->create($project);
        $stale->set('form.number', 'EX-99')->set('form.begin_date', '2026-05-01')->call('save')->assertHasErrors('file');
        $this->assertSame(1, DB::table('executive_files')->where('project_id', $project->id)->count());
        $this->assertSame('EX-77', $this->file($project->id)->file_number);

        // A page opened before an edit cannot overwrite it.
        $first = Livewire::test(ExecutiveFileEditor::class, ['projectId' => $project->id]);
        Livewire::test(ExecutiveFileEditor::class, ['projectId' => $project->id])->set('form.months', '3')->call('save')->assertHasNoErrors();
        $first->set('form.months', '9')->call('save')->assertHasErrors('file');
        $this->assertSame(3, (int) $this->file($project->id)->months);

        Livewire::test(ExecutiveFileEditor::class, ['projectId' => $project->id])->set('form.number', '')->call('save')->assertHasErrors('form.number');
        Livewire::test(ExecutiveFileEditor::class, ['projectId' => $project->id])->set('form.months', '101')->call('save')->assertHasErrors('form.months');
    }

    public function test_a_city_without_a_legal_account_saves_with_a_warning(): void
    {
        $this->as('role.legal');
        $project = $this->project();
        DB::table('account_city_roles')->where('city_id', $project->city_id)->where('role', 'legal')->update(['revoked_at' => now('UTC')]);
        Livewire::test(ExecutiveFileEditor::class, ['projectId' => $project->id])->set('form.number', 'EX-1')->set('form.begin_date', '2026-04-01')->call('save')
            ->assertHasNoErrors()->assertSee(__('executive.no_legal', [], 'ar'));
        $this->assertNotNull($this->file($project->id));
        $this->assertSame(0, $this->notices($project->id)->count());
    }

    public function test_another_city_is_refused(): void
    {
        $account = $this->as('role.legal');
        $project = $this->project();
        DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', $project->city_id)->update(['revoked_at' => now()]);
        $this->get('/ar/applications/'.$project->id.'/executive-file')->assertNotFound();
        Livewire::test(ExecutiveFileEditor::class, ['projectId' => $project->id])->assertNotFound();
    }

    public function test_the_palette_offers_the_page_only_to_those_who_may_open_it(): void
    {
        $project = $this->project();
        $term = \App\Services\ProjectNumberAllocator::display(DB::table('cities')->where('id', $project->city_id)->value('code'), (int) $project->project_number);
        $titles = fn (string $user) => array_column(app(GlobalSearch::class)->search(WebAccount::where('username', $user)->firstOrFail(), $term)['commands'], 'url');
        $url = route('executive.show', ['locale' => app()->getLocale(), 'project' => $project->id]);
        $this->assertContains($url, $titles('role.legal'));
        $this->assertContains($url, $titles('role.financial'));
        $this->assertNotContains($url, $titles('role.data-entry'));
        $this->assertNotContains($url, $titles('role.guest'));
    }

    public function test_the_expiry_clamps_to_the_end_of_the_month(): void
    {
        $this->assertSame('2026-02-28', ExecutiveFileService::expiry('2026-01-31', 1));
        $this->assertSame('2026-09-01', ExecutiveFileService::expiry('2026-03-01', '6'));
        $this->assertNull(ExecutiveFileService::expiry('', 3));
        $this->assertNull(ExecutiveFileService::expiry('2026-01-31', 'x'));
    }
}
