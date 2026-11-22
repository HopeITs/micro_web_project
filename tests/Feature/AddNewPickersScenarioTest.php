<?php

namespace Tests\Feature;

use App\Livewire\ProjectPicker;
use App\Livewire\ProjectSpecialCases;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\ProjectNumberAllocator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request (22 Nov 2026): the general pages of the sidebar (Checklist, special cases) get an "add new" button with a choice of the
 * project by its number or the beneficiary's name, not only from inside a project; a project may hold several special cases.
 */
class AddNewPickersScenarioTest extends TestCase
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

    private function city(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function project(string $city): object
    {
        return DB::table('projects')->where('city_id', $this->city($city))->whereNull('deleted_at')->orderBy('id')->first();
    }

    private function number(object $project): string
    {
        return ProjectNumberAllocator::display((string) DB::table('cities')->where('id', $project->city_id)->value('code'), (int) $project->project_number);
    }

    private function beneficiaryFirstName(object $project): string
    {
        return (string) DB::table('project_applicants as a')->join('people as p', 'p.id', '=', 'a.person_id')->where('a.project_id', $project->id)->orderBy('a.id')->value('p.first_name');
    }

    /** An administrator of Homs only. */
    private function homsAdministrator(): WebAccount
    {
        $account = app(AccountProvisioner::class)->createPlain('homs.admin', null, Hash::make('password'), 'test:pickers');
        // A first sign-in would ask for a new password and the organisation e-mail; this scenario starts after both.
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'email' => 'homs.admin@hcsyria.org']);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('HMS'), 'administrator', 'test:pickers');

        return $account->fresh();
    }

    public function test_special_cases_list_offers_add_new_and_the_picker_finds_by_number_and_name(): void
    {
        $this->as('role.data-entry');
        $project = $this->project('DAM');
        $this->get('/ar/special-cases')->assertOk()->assertSee(__('special_cases.list.add', [], 'ar'));
        $this->get('/en/special-cases')->assertOk()->assertSee('New special case');

        $picker = fn () => Livewire::test(ProjectPicker::class, ['target' => 'special-cases.project', 'capability' => 'special_cases.manage', 'label' => 'x', 'query' => ['new' => 1]]);
        // By the project number (with and without the city code) and by a word of the beneficiary's name.
        $picker()->set('term', $this->number($project))->assertSee($this->number($project))
            ->assertSee(route('special-cases.project', ['locale' => app()->getLocale(), 'project' => $project->id, 'new' => 1]), false);
        $picker()->set('term', (string) $project->project_number)->assertSee($this->number($project));
        $name = $this->beneficiaryFirstName($project);
        $picker()->set('term', $name)->assertSee($this->number($project));
        $picker()->set('term', 'zzzz-no-such-project')->assertSee(__('hc.picker_none'));
    }

    public function test_the_picker_only_offers_projects_of_cities_where_the_account_may_add(): void
    {
        $dam = $this->project('DAM');
        $hms = $this->project('HMS');
        $this->homsAdministrator();
        $this->as('homs.admin');
        $picker = fn (string $term) => Livewire::test(ProjectPicker::class, ['target' => 'checklists.show', 'capability' => 'assessments.manage', 'label' => 'x'])->set('term', $term);
        $picker($this->number($hms))->assertSee($this->number($hms));
        $picker($this->number($dam))->assertDontSee($this->number($dam))->assertSee(__('hc.picker_none'));

        // A reader who may not write sees no button, and a locked property cannot be changed from the browser.
        $this->as('role.guest');
        $this->get('/ar/checklists')->assertForbidden();
        $this->as('role.monitoring');
        $this->get('/ar/special-cases')->assertOk()->assertDontSee(__('special_cases.list.add', [], 'ar'));
        Livewire::test(ProjectPicker::class, ['target' => 'special-cases.project', 'capability' => 'special_cases.manage', 'label' => 'x'])
            ->set('term', $this->number($dam))->assertSee(__('hc.picker_none'));
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(ProjectPicker::class, ['target' => 'special-cases.project', 'capability' => 'special_cases.manage', 'label' => 'x'])->set('capability', 'projects.view');
    }

    public function test_a_project_takes_several_special_cases_and_the_form_is_ready_for_the_next(): void
    {
        $this->as('role.data-entry');
        $project = $this->project('DAM');
        $page = Livewire::test(ProjectSpecialCases::class, ['projectId' => $project->id]);
        foreach (['تأجيل قسط', 'تخفيض قسط', 'إعفاء من الأقساط'] as $reason) {
            $page->set('reason', $reason)->call('save')->assertHasNoErrors()->assertDispatched('case-saved')->assertSet('reason', '')->assertSet('editing', null)
                ->assertSee(__('special_cases.saved'));
        }
        $this->assertSame(3, DB::table('special_cases')->where('project_id', $project->id)->count());
        $this->get('/ar/applications/'.$project->id.'/special-cases')->assertOk()->assertSee(__('special_cases.add_another', [], 'ar'))
            ->assertSee(__('special_cases.recorded', ['count' => 3], 'ar'));
        // Opened from the list page's picker: the form takes the focus.
        $this->get('/ar/applications/'.$project->id.'/special-cases?new=1')->assertOk()->assertSee('sc-reason');
    }

    public function test_the_checklist_page_lists_and_searches_within_the_permitted_cities(): void
    {
        $this->as('role.data-entry');
        $project = $this->project('DAM');
        $personId = (int) DB::table('project_applicants')->where('project_id', $project->id)->orderBy('id')->value('person_id');
        DB::table('checklists')->insert(['city_id' => $project->city_id, 'person_id' => $personId, 'project_id' => $project->id, 'team_date' => '2026-11-10', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/ar')->assertSee(route('checklists.index', ['locale' => 'ar']));
        $this->get('/ar/checklists')->assertOk()->assertSee($this->number($project))->assertSee(route('checklists.show', ['locale' => 'ar', 'project' => $project->id]))
            ->assertSee(__('checklists.list.add', [], 'ar'));
        $this->get('/en/checklists?q='.urlencode($this->number($project)))->assertOk()->assertSee($this->number($project))->assertSee('New checklist');
        $this->get('/ar/checklists?q=zzzz-no-such')->assertOk()->assertSee(__('checklists.list.none', [], 'ar'));
        $this->get('/ar/checklists?cities[]=abc')->assertSessionHasErrors('cities.0');

        // Another city's checklist is not listed for a one-city administrator.
        $this->homsAdministrator();
        $this->as('homs.admin');
        // (The row's link is checked, not the number: the search placeholders carry example numbers.)
        $link = route('checklists.show', ['locale' => 'ar', 'project' => $project->id]);
        $this->assertNotSame($this->city('HMS'), (int) $project->city_id);
        $this->get('/ar/checklists')->assertOk()->assertDontSee($link)->assertSee(__('checklists.list.none', [], 'ar'));
        // Asking for another city's checklists falls back to the permitted cities, never to that city.
        $this->get('/ar/checklists?cities[]='.$project->city_id)->assertOk()->assertDontSee($link);
        // Only the administrator and data entry hold assessments.view; the financial role is refused and the refusal is audited.
        $financial = $this->as('role.financial');
        $this->get('/ar/checklists')->assertForbidden();
        $this->assertDatabaseHas('account_audit_events', ['actor_account_id' => $financial->id, 'event_type' => 'access.denied']);
        $this->assertDatabaseHas('account_audit_events', ['event_type' => 'applications.list']);
    }
}
