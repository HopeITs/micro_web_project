<?php

namespace Tests\Feature;

use App\Livewire\LookupEditor;
use App\Livewire\ProjectSpecialCases;
use App\Livewire\SpecialCaseList;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Field\SpecialCaseService;
use App\Services\ProjectNumberAllocator;
use App\Services\Search\GlobalSearch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M4.13 user scenarios: data entry records a phone call and an interview (recorder defaults to them, reason required, no future date),
 * edits one (only the changed fields audited) and deletes one permanently; monitoring reads only; the administrator deletes; a case on a
 * rejected project; financial, legal, guest and media are refused; another city is not found; a stale page is refused; the list of all
 * cases filters and exports; the case types in the lookup editor; the role sync takes writing from monitoring holders; the palette.
 */
class SpecialCaseScenarioTest extends TestCase
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

    /** A financed project (with a loan) without special cases. */
    private function project(): object
    {
        $project = DB::table('projects as p')->join('loans as l', 'l.project_id', '=', 'p.id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('special_cases as s')->whereColumn('s.project_id', 'p.id'))->orderBy('p.id')->first(['p.*']);

        return $project;
    }

    private function type(string $nameEn): string
    {
        return (string) DB::table('special_case_types')->where('name_en', $nameEn)->value('id');
    }

    private function form(object $project, array $values = [])
    {
        $page = Livewire::test(ProjectSpecialCases::class, ['projectId' => $project->id])->set('reason', 'تأجيل أقساط');
        foreach ($values as $key => $value) {
            $page->set($key, $value);
        }

        return $page;
    }

    public function test_data_entry_records_edits_and_deletes_cases(): void
    {
        $me = $this->as('role.data-entry');
        $project = $this->project();
        $this->get('/ar/projects/'.$project->id)->assertOk()->assertSee(route('special-cases.project', ['locale' => 'ar', 'project' => $project->id]));
        $this->get('/ar/applications/'.$project->id.'/special-cases')->assertOk()->assertSeeLivewire(ProjectSpecialCases::class)
            ->assertSee(__('special_cases.fields.recorder', [], 'ar'));
        $this->get('/en/applications/'.$project->id.'/special-cases')->assertOk()->assertSee('Action taken');
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'special_cases.view')->where('subject_id', $project->id)->exists());

        // Defaults: today, the signed-in account, the phone call.
        $page = Livewire::test(ProjectSpecialCases::class, ['projectId' => $project->id])
            ->assertSet('date', now()->toDateString())->assertSet('recorder', (string) $me->id)->assertSet('type', $this->type('Phone Call'));
        // The reason is required; a future date and an empty date are refused.
        $page->call('save')->assertHasErrors('reason');
        $this->form($project, ['date' => now()->addDay()->toDateString()])->call('save')->assertHasErrors('date');
        $this->form($project, ['date' => ''])->call('save')->assertHasErrors('date');
        $this->form($project, ['date' => '2026-02-30'])->call('save')->assertHasErrors('date');
        $this->form($project, ['recorder' => ''])->call('save')->assertHasErrors('recorder');
        // An account that cannot write special cases is not a recorder.
        $this->form($project, ['recorder' => (string) WebAccount::where('username', 'role.monitoring')->value('id')])->call('save')->assertHasErrors('recorder');
        $this->assertSame(0, DB::table('special_cases')->where('project_id', $project->id)->count());

        $this->form($project, ['date' => '2026-03-01', 'description' => "طلب تأجيل\nقسطين"])->call('save')->assertHasNoErrors()->assertSee(__('special_cases.saved'))
            ->assertSet('reason', '')->assertSet('editing', null);
        $phone = DB::table('special_cases')->where('project_id', $project->id)->first();
        $this->assertSame([(int) $project->city_id, $me->id, (int) $this->type('Phone Call'), '2026-03-01', 'تأجيل أقساط', "طلب تأجيل\nقسطين", null],
            [(int) $phone->city_id, (int) $phone->account_id, (int) $phone->special_case_type_id, substr((string) $phone->case_date, 0, 10), $phone->reason, $phone->description, $phone->action]);
        $created = DB::table('account_audit_events')->where('event_type', 'special_cases.saved')->latest('id')->value('details');
        $this->assertStringContainsString('"field":"recorder","before":null,"after":"role.data-entry"', (string) $created);
        $this->assertStringContainsString('"field":"case_type","before":null,"after":"هاتفية"', (string) $created);

        // An interview recorded by the administrator account.
        $admin = WebAccount::where('username', 'role.administrator')->value('id');
        $this->form($project, ['type' => $this->type('Interview'), 'recorder' => (string) $admin, 'reason' => "سفر\nلمدة شهر", 'action' => 'تم تسجيل الملاحظة'])->call('save')->assertHasNoErrors();
        $interview = DB::table('special_cases')->where('project_id', $project->id)->where('special_case_type_id', $this->type('Interview'))->first();
        $this->assertSame('سفر لمدة شهر', $interview->reason, 'The reason is a single line.');
        $this->assertSame($admin, (int) $interview->account_id);

        // Editing writes and audits only the changed field; the newest case comes first.
        $page = Livewire::test(ProjectSpecialCases::class, ['projectId' => $project->id]);
        $this->assertSame([$interview->id, $phone->id], $page->viewData('page')['cases']->pluck('id')->all());
        $page->call('edit', $phone->id)->assertSet('reason', 'تأجيل أقساط')->set('action', 'تمت الموافقة على تأجيل قسط')->call('save')->assertHasNoErrors();
        $this->assertSame('تمت الموافقة على تأجيل قسط', DB::table('special_cases')->where('id', $phone->id)->value('action'));
        $details = json_decode((string) DB::table('account_audit_events')->where('event_type', 'special_cases.saved')->latest('id')->value('details'), true);
        $this->assertSame(['case', 'action'], array_column($details, 'field'));
        $events = DB::table('account_audit_events')->where('event_type', 'special_cases.saved')->count();
        Livewire::test(ProjectSpecialCases::class, ['projectId' => $project->id])->call('edit', $phone->id)->call('save')->assertHasNoErrors();
        $this->assertSame($events, DB::table('account_audit_events')->where('event_type', 'special_cases.saved')->count(), 'Nothing changed, nothing audited.');

        // Delete is permanent and audited with what the case held.
        Livewire::test(ProjectSpecialCases::class, ['projectId' => $project->id])->assertSee(__('special_cases.delete'))->call('delete', $interview->id)->assertHasNoErrors()
            ->assertSee(__('special_cases.deleted'));
        $this->assertSame(0, DB::table('special_cases')->where('id', $interview->id)->count());
        $deleted = (string) DB::table('account_audit_events')->where('event_type', 'special_cases.deleted')->latest('id')->value('details');
        $this->assertStringContainsString('"field":"reason","before":"سفر لمدة شهر","after":null', $deleted);
        $this->assertStringContainsString('"field":"recorder","before":"role.administrator"', $deleted);
        // A case of another project cannot be deleted from this page.
        $other = DB::table('special_cases')->where('project_id', '<>', $project->id)->value('id');
        Livewire::test(ProjectSpecialCases::class, ['projectId' => $project->id])->call('delete', $other)->assertHasErrors('case');
        $this->assertSame(1, DB::table('special_cases')->where('id', $other)->count());
    }

    public function test_monitoring_reads_only_and_the_administrator_deletes(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $this->form($project)->call('save')->assertHasNoErrors();
        $case = DB::table('special_cases')->where('project_id', $project->id)->value('id');

        $this->as('role.monitoring');
        $this->get('/ar/applications/'.$project->id.'/special-cases')->assertOk()->assertSee('تأجيل أقساط')->assertSee(__('special_cases.read_only', [], 'ar'));
        Livewire::test(ProjectSpecialCases::class, ['projectId' => $project->id])->assertDontSee(__('special_cases.delete'))->set('reason', 'x')->call('save')->assertForbidden();
        Livewire::test(ProjectSpecialCases::class, ['projectId' => $project->id])->call('delete', $case)->assertForbidden();
        Livewire::test(ProjectSpecialCases::class, ['projectId' => $project->id])->call('edit', $case)->assertNotFound();
        $this->assertSame(1, DB::table('special_cases')->where('project_id', $project->id)->count());
        $this->get('/ar/special-cases')->assertOk()->assertSeeLivewire(SpecialCaseList::class);

        $this->as('role.administrator');
        Livewire::test(ProjectSpecialCases::class, ['projectId' => $project->id])->call('delete', $case)->assertHasNoErrors();
        $this->assertSame(0, DB::table('special_cases')->where('project_id', $project->id)->count());
    }

    public function test_a_case_on_a_rejected_project_without_a_loan(): void
    {
        $this->as('role.data-entry');
        $rejected = DB::table('project_state_history as h')->join('project_states as s', 's.id', '=', 'h.project_state_id')->where('s.name_en', 'Rejected')->where('h.is_current', true)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('loans as l')->whereColumn('l.project_id', 'h.project_id'))->orderBy('h.project_id')->value('h.project_id');
        $project = DB::table('projects')->find($rejected);
        $this->get('/ar/applications/'.$project->id.'/special-cases')->assertOk()->assertSee(__('visits.header.no_loan', [], 'ar'));
        $this->form($project, ['reason' => 'اعتراض على الرفض', 'date' => '2024-01-01'])->call('save')->assertHasNoErrors();
        $this->assertSame(1, DB::table('special_cases')->where('project_id', $project->id)->where('reason', 'اعتراض على الرفض')->count());
    }

    public function test_financial_legal_guest_and_media_are_refused_and_another_city_is_not_found(): void
    {
        $project = $this->project();
        foreach (['role.financial', 'role.legal', 'role.guest', 'role.media', 'role.communication'] as $user) {
            $this->as($user);
            $this->get('/ar/applications/'.$project->id.'/special-cases')->assertForbidden();
            $this->get('/ar/special-cases')->assertForbidden();
            $this->get('/ar/special-cases/export?format=csv')->assertForbidden();
            $this->get('/ar/projects/'.$project->id)->assertOk()->assertDontSee(route('special-cases.project', ['locale' => 'ar', 'project' => $project->id]));
        }
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'access.denied')->exists());

        $account = $this->as('role.data-entry');
        DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', $project->city_id)->update(['revoked_at' => now()]);
        $this->get('/ar/applications/'.$project->id.'/special-cases')->assertNotFound();
        Livewire::test(ProjectSpecialCases::class, ['projectId' => $project->id])->assertNotFound();
    }

    public function test_a_stale_page_is_refused_and_a_resubmitted_form_makes_no_second_case(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $stale = $this->form($project, ['reason' => 'طلب أول']);
        $this->form($project)->call('save')->assertHasNoErrors();
        $stale->call('save')->assertHasErrors('version');
        $stale->call('delete', (int) DB::table('special_cases')->where('project_id', $project->id)->value('id'))->assertHasErrors('version');
        $this->assertSame(1, DB::table('special_cases')->where('project_id', $project->id)->count());

        // The same form sent twice (a double click) records one case.
        $service = app(SpecialCaseService::class);
        $account = WebAccount::where('username', 'role.data-entry')->first();
        $version = $service->page($account, $project->id)['version'];
        $input = ['date' => '2026-04-01', 'recorder' => $account->id, 'type' => $this->type('Interview'), 'reason' => 'مرة واحدة'];
        $service->save($account, $project->id, null, $input, $version);
        try {
            $service->save($account, $project->id, null, $input, $version);
            $this->fail('The resubmission was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('version', $e->errors());
        }
        $this->assertSame(1, DB::table('special_cases')->where('project_id', $project->id)->where('reason', 'مرة واحدة')->count());
    }

    public function test_the_list_filters_and_exports(): void
    {
        $this->as('role.data-entry');
        $this->get('/ar/special-cases')->assertOk()->assertSeeLivewire(SpecialCaseList::class)->assertSee(__('special_cases.columns.recorder', [], 'ar'));
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'special_cases.list')->exists());
        $all = DB::table('special_cases')->whereNull('deleted_at')->count();
        $this->assertGreaterThan(10, $all, 'The seeder added special cases.');

        $list = Livewire::test(SpecialCaseList::class);
        $this->assertSame($all, $list->viewData('rows')->total());
        $first = $list->viewData('rows')->first();
        $newest = DB::table('special_cases')->orderByDesc('case_date')->orderByDesc('id')->first();
        $this->assertSame(substr((string) $newest->case_date, 0, 10), $first[7], 'Newest first.');

        $interviews = DB::table('special_cases')->where('special_case_type_id', $this->type('Interview'))->count();
        $this->assertSame($interviews, Livewire::test(SpecialCaseList::class)->set('type', $this->type('Interview'))->viewData('rows')->total());
        $admin = WebAccount::where('username', 'role.administrator')->value('id');
        $this->assertSame(DB::table('special_cases')->where('account_id', $admin)->count(), Livewire::test(SpecialCaseList::class)->set('recorder', (string) $admin)->viewData('rows')->total());
        $rejected = (string) DB::table('project_states')->where('name_en', 'Rejected')->value('id');
        $this->assertSame(DB::table('cities')->count(), Livewire::test(SpecialCaseList::class)->set('state', $rejected)->viewData('rows')->total(), 'One objection per city.');
        $this->assertSame(DB::table('cities')->count(), Livewire::test(SpecialCaseList::class)->set('q', 'اعتراض')->viewData('rows')->total());
        $hms = (int) DB::table('cities')->where('code', 'HMS')->value('id');
        $this->assertSame(DB::table('special_cases')->where('city_id', $hms)->count(), Livewire::test(SpecialCaseList::class)->call('toggleCity', $hms)->viewData('rows')->total());
        $this->assertSame(DB::table('special_cases')->where('case_date', '2025-09-15')->count(), Livewire::test(SpecialCaseList::class)->set('from', '2025-09-15')->set('to', '2025-09-15')->viewData('rows')->total());
        // A start after the end is refused with the desktop wording and shows nothing.
        $wrong = Livewire::test(SpecialCaseList::class)->set('from', '2026-02-01')->set('to', '2026-01-01');
        $wrong->assertHasErrors('from')->assertSee(__('special_cases.errors.range'));
        $this->assertSame(0, $wrong->viewData('rows')->total());
        // Project number search: "HMS-1001" finds only that project's cases.
        $code = DB::table('special_cases as s')->join('projects as p', 'p.id', '=', 's.project_id')->join('cities as c', 'c.id', '=', 's.city_id')->orderBy('s.id')->first(['c.code', 'p.project_number', 's.project_id']);
        $this->assertSame(DB::table('special_cases')->where('project_id', $code->project_id)->count(), Livewire::test(SpecialCaseList::class)->set('q', $code->code.'-'.$code->project_number)->viewData('rows')->total());
        // The beneficiary by name.
        $person = DB::table('project_applicants as a')->join('people as pe', 'pe.id', '=', 'a.person_id')->where('a.project_id', $code->project_id)->first(['pe.first_name', 'pe.last_name']);
        $this->assertGreaterThanOrEqual(1, Livewire::test(SpecialCaseList::class)->set('q', $person->first_name.' '.$person->last_name)->viewData('rows')->total());

        $csv = $this->get('/en/special-cases/export?format=csv&type='.$this->type('Interview'));
        $csv->assertOk();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, ltrim($csv->streamedContent(), "\u{FEFF}"));
        rewind($stream);
        $lines = [];
        while (($line = fgetcsv($stream, escape: '')) !== false) {
            $line !== [null] && $lines[] = $line;
        }
        $this->assertContains('Recorded by', $lines[0]);
        $this->assertCount($interviews + 1, $lines);
        $this->assertCount(13, $lines[1]);
        $this->get('/ar/special-cases/export?format=xlsx')->assertOk();
        $this->get('/ar/special-cases/export?from=2026-02-01&to=2026-01-01')->assertStatus(422);
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'reports.exported')->exists());

        // Monitoring may export too; a reader without reports.export may not.
        $this->as('role.monitoring');
        $this->get('/ar/special-cases/export?format=csv')->assertOk();
        $account = WebAccount::where('username', 'role.monitoring')->first();
        DB::table('account_city_capabilities')->where('account_id', $account->id)->where('capability', 'reports.export')->update(['revoked_at' => now()]);
        $this->get('/ar/special-cases/export?format=csv')->assertForbidden();
        $this->get('/ar/special-cases')->assertOk()->assertDontSee('format=csv');
    }

    public function test_case_types_are_a_shared_list_and_a_hidden_type_stays_on_its_cases(): void
    {
        $this->as('role.administrator');
        $this->get('/ar/lookups')->assertOk()->assertSee(__('lookups.names.special_case_types', [], 'ar'));
        $this->get('/en/lookups/special_case_types')->assertOk()->assertSee('Phone Call');
        Livewire::test(LookupEditor::class, ['list' => 'special_case_types'])->set('draft.name_ar', 'رسالة')->set('draft.name_en', 'Message')->call('create')->assertHasNoErrors();
        $message = (int) DB::table('special_case_types')->where('name_ar', 'رسالة')->value('id');
        $project = $this->project();
        $this->form($project, ['type' => (string) $message])->call('save')->assertHasNoErrors();
        $case = (int) DB::table('special_cases')->where('project_id', $project->id)->value('id');

        // Hidden later: no longer offered for a new case, kept on the stored one.
        DB::table('special_case_types')->where('id', $message)->update(['is_active' => false]);
        $page = Livewire::test(ProjectSpecialCases::class, ['projectId' => $project->id]);
        $page->set('type', (string) $message)->call('save')->assertHasErrors('type');
        $page->call('edit', $case)->assertSet('type', (string) $message)->set('action', 'تم')->call('save')->assertHasNoErrors();
        $this->assertSame($message, (int) DB::table('special_cases')->where('id', $case)->value('special_case_type_id'));

        $this->as('role.monitoring');
        $this->get('/ar/lookups/special_case_types')->assertForbidden();
    }

    public function test_roles_sync_and_the_palette(): void
    {
        foreach (['administrator', 'data-entry', 'monitoring'] as $role) {
            $this->assertContains('special_cases.view', AccessRoles::capabilities($role), $role);
        }
        foreach (['administrator', 'data-entry'] as $role) {
            $this->assertContains('special_cases.manage', AccessRoles::capabilities($role), $role);
            $this->assertContains('special_cases.delete', AccessRoles::capabilities($role), $role);
        }
        $this->assertNotContains('special_cases.manage', AccessRoles::capabilities('monitoring'));
        foreach (['financial', 'legal', 'media', 'guest', 'communication'] as $role) {
            $this->assertNotContains('special_cases.view', AccessRoles::capabilities($role), $role);
        }

        // A monitoring holder assigned before M4.13 loses the writing grant; one that is also data entry keeps it.
        $city = (int) DB::table('cities')->where('code', 'DAM')->value('id');
        $provisioner = app(AccountProvisioner::class);
        $make = function (string $name, array $roles) use ($provisioner, $city) {
            $account = $provisioner->createPlain($name, $name.'@hcsyria.org', Hash::make('a-long-test-password'), 'test:sync');
            foreach ($roles as $role) {
                DB::table('account_city_roles')->insert(['account_id' => $account->id, 'city_id' => $city, 'role' => $role, 'decision_locator' => 'test:sync', 'assigned_at' => now('UTC')]);
            }
            foreach (['special_cases.view', 'special_cases.manage'] as $cap) {
                $provisioner->grant($account->fresh(), $city, $cap, 'test:sync');
            }

            return $account->fresh();
        };
        $held = fn ($account) => DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', $city)->whereNull('revoked_at')->pluck('capability')->all();
        $monitoring = $make('sync.monitoring', ['monitoring']);
        $both = $make('sync.both', ['monitoring', 'data-entry']);
        $this->artisan('staff:sync-export-roles', ['--decision' => 'test:sync'])->assertSuccessful();
        $this->assertNotContains('special_cases.manage', $held($monitoring));
        $this->assertContains('special_cases.view', $held($monitoring));
        $this->assertContains('special_cases.manage', $held($both), 'Data entry still includes it.');

        // Ctrl+K: the project's special cases and the list page.
        $project = $this->project();
        $term = ProjectNumberAllocator::display(DB::table('cities')->where('id', $project->city_id)->value('code'), (int) $project->project_number);
        $urls = fn (string $user) => array_column(app(GlobalSearch::class)->search(WebAccount::where('username', $user)->firstOrFail(), $term)['commands'], 'url');
        $url = route('special-cases.project', ['locale' => app()->getLocale(), 'project' => $project->id]);
        $this->assertContains($url, $urls('role.data-entry'));
        $this->assertNotContains($url, $urls('role.financial'));
        $pages = array_column(app(GlobalSearch::class)->search(WebAccount::where('username', 'role.monitoring')->firstOrFail(), 'الحالات الخاصة')['pages'], 'url');
        $this->assertContains(route('special-cases.index', ['locale' => app()->getLocale()]), $pages);
    }
}
