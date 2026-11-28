<?php

namespace Tests\Feature;

use App\Livewire\ProjectsIndex;
use App\Models\Project;
use App\Models\WebAccount;
use App\Services\Reports\ReachCounter;
use App\Support\ProjectStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * X4 counting units (owner decision, 28 Nov 2026): projects, beneficiaries (owners and partners, each person once) and people reached
 * (beneficiaries plus their families' members, each person once). The numbers equal direct queries, follow the list's filters, leave out
 * cities the account does not hold and soft-deleted links, and show on the home page and the projects list in Arabic and English.
 */
class ReachCountScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 'شريك';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function as(string $role): WebAccount
    {
        $account = WebAccount::where('username', 'role.'.$role)->firstOrFail();
        $this->actingAs($account);

        return $account;
    }

    /** @return list<int> */
    private function allCities(): array
    {
        return DB::table('cities')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param list<int> $cities */
    private function direct(array $cities, ?array $projectIds = null): array
    {
        $projects = $projectIds !== null ? collect($projectIds) : DB::table('projects')->whereNull('deleted_at')->whereIn('city_id', $cities)->pluck('id');
        $people = DB::table('project_applicants')->whereNull('deleted_at')->whereIn('project_id', $projects)->pluck('person_id')->unique();
        $families = DB::table('family_members as fm')->join('families as f', 'f.id', '=', 'fm.family_id')->whereNull('fm.deleted_at')->whereNull('f.deleted_at')
            ->whereIn('fm.person_id', $people)->pluck('fm.family_id')->unique();
        $members = DB::table('family_members')->whereNull('deleted_at')->whereIn('family_id', $families)->pluck('person_id');

        return ['projects' => $projects->count(), 'beneficiaries' => $people->count(), 'reached' => $people->merge($members)->unique()->count()];
    }

    /** @return array{projects: int, beneficiaries: int, reached: int} */
    private function three(array $reach): array
    {
        return ['projects' => $reach['projects'], 'beneficiaries' => $reach['beneficiaries'], 'reached' => $reach['reached']];
    }

    public function test_partners_make_more_beneficiaries_than_projects_and_families_more_people_reached(): void
    {
        $this->as('administrator');
        $reach = app(ReachCounter::class)->count(Project::query());

        $this->assertSame($this->direct($this->allCities()), $this->three($reach));
        $this->assertGreaterThan($reach['projects'], $reach['beneficiaries'], 'the synthetic data has partners');
        $this->assertGreaterThan($reach['beneficiaries'], $reach['reached']);
        $this->assertSame(DB::table('project_applicants')->where('role', self::PARTNER)->whereNull('deleted_at')->distinct()->count('person_id'), $reach['partners']);
        $this->assertGreaterThan(0, $reach['partners']);
    }

    public function test_a_person_in_two_projects_counts_once_and_deleted_links_are_left_out(): void
    {
        $this->as('administrator');
        $before = app(ReachCounter::class)->count(Project::query());

        [$a, $b] = DB::table('projects')->orderBy('id')->limit(2)->pluck('id')->all();
        $city = (int) DB::table('projects')->where('id', $b)->value('city_id');
        $owner = DB::table('project_applicants')->where('project_id', $a)->value('person_id');
        DB::table('project_applicants')->insert(['city_id' => $city, 'project_id' => $b, 'person_id' => $owner, 'role' => self::PARTNER, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame($before['beneficiaries'], app(ReachCounter::class)->count(Project::query())['beneficiaries'], 'the same person in two projects is one beneficiary');

        DB::table('project_applicants')->where('project_id', $a)->update(['deleted_at' => now()]);
        $after = app(ReachCounter::class)->count(Project::query());
        $this->assertSame($before['projects'], $after['projects']);
        $this->assertSame(1, $after['without_person']);
    }

    public function test_the_list_counts_follow_its_filters_and_a_one_city_account_sees_its_city_only(): void
    {
        $account = $this->as('data-entry');
        $damascus = (int) DB::table('cities')->where('code', 'DAM')->value('id');
        DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', '<>', $damascus)->update(['revoked_at' => now()]);
        $this->actingAs($account->fresh());
        // What the RestrictToPermittedCities middleware does on a real request.
        app(\App\Support\CityContext::class)->restrictTo(DB::table('account_city_capabilities')->where('account_id', $account->id)->where('capability', 'projects.view')->whereNull('revoked_at')->pluck('city_id'));

        $page = Livewire::test(ProjectsIndex::class);
        $this->assertSame($this->direct([$damascus]), $this->three($page->viewData('reach')));

        $page->set('path', 'followup');
        $followup = DB::table('projects as p')
            ->join('project_state_history as h', fn ($j) => $j->on('h.project_id', '=', 'p.id')->where('h.is_current', true)->whereNull('h.deleted_at'))
            ->join('project_states as s', 's.id', '=', 'h.project_state_id')
            ->where('p.city_id', $damascus)->whereNull('p.deleted_at')->whereIn('s.name_en', ProjectStatus::groups()['followup'])->pluck('p.id')->all();
        $this->assertNotEmpty($followup);
        $this->assertSame($this->direct([], $followup), $this->three($page->viewData('reach')));
    }

    public function test_home_and_projects_list_show_the_three_units_in_both_languages(): void
    {
        $this->as('administrator');
        $reach = app(ReachCounter::class)->count(Project::query());
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->get(route('home', ['locale' => $locale]))->assertOk()
                ->assertSee('data-reach="beneficiaries"', false)
                ->assertSee(number_format($reach['beneficiaries']))
                ->assertSee(number_format($reach['reached']))
                ->assertSee(__('hc.reach_how'));
            $this->get(route('projects.index', ['locale' => $locale]))->assertOk()->assertSee(__('hc.reach_reached'));
        }
    }
}
