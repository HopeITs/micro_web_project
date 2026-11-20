<?php

namespace Tests\Feature;

use App\Livewire\CommandPalette;
use App\Livewire\ProjectsIndex;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Search\GlobalSearch;
use App\Support\CityContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** F3 Ctrl+K search and the path count tiles, per role (docs/planning/ux-borrowed-features-2026-11.md). */
class QuickSearchScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('ar');
    }

    private function account(string $username): WebAccount
    {
        return WebAccount::where('username', $username)->firstOrFail();
    }

    private function damascusDataEntry(): WebAccount
    {
        $account = app(AccountProvisioner::class)->createPlain('test.dam.search', null, Hash::make('a-long-test-password'), 'test:quick-search');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now()]);
        $damascus = (int) DB::table('cities')->where('code', 'DAM')->value('id');
        app(AccessRoles::class)->assign($account->fresh(), $damascus, 'data-entry', 'test:quick-search');

        return $account->fresh();
    }

    public function test_data_entry_finds_a_project_while_typing_its_number_and_can_start_an_application(): void
    {
        $results = app(GlobalSearch::class)->search($this->account('role.data-entry'), 'DAM-100');

        $this->assertStringStartsWith('DAM-1001 · ', $results['projects'][0]['title'], 'The exact number comes first.');
        $this->assertTrue(collect($results['projects'])->every(fn ($p) => str_starts_with($p['title'], 'DAM-100')));
        $this->assertContains(__('hc.cmd_new_application'), array_column(app(GlobalSearch::class)->search($this->account('role.data-entry'), '')['commands'], 'title'));
    }

    public function test_a_damascus_user_only_ever_finds_damascus_people_and_projects(): void
    {
        $account = $this->damascusDataEntry();
        $search = app(GlobalSearch::class);

        $projects = $search->search($account, '1001')['projects'];
        $this->assertNotEmpty($projects);
        $this->assertTrue(collect($projects)->every(fn ($p) => str_starts_with($p['title'], 'DAM-')));

        $aleppoPerson = DB::table('people')->join('cities as c', 'c.id', '=', 'people.city_id')->where('c.code', 'ALP')->whereNotNull('national_number')->value('national_number');
        $this->assertSame([], $search->search($account, $aleppoPerson)['people']);
    }

    public function test_communication_cannot_find_people_or_start_records(): void
    {
        $account = $this->account('role.communication');
        $number = DB::table('people')->whereNotNull('national_number')->value('national_number');
        $results = app(GlobalSearch::class)->search($account, $number);

        $this->assertSame([], $results['people']);
        $commands = array_column(app(GlobalSearch::class)->search($account, '')['commands'], 'title');
        $this->assertNotContains(__('hc.cmd_new_application'), $commands);
        $this->assertNotContains(__('hc.cmd_new_person'), $commands);
        $this->assertContains(__('hc.cmd_change_password'), $commands);
    }

    public function test_the_palette_answers_a_name_without_putting_it_in_a_url(): void
    {
        $this->actingAs($this->account('role.data-entry'));
        $person = DB::table('people')->whereNotNull('national_number')->first();

        Livewire::test(CommandPalette::class)->set('term', $person->national_number)
            ->assertSee($person->first_name)->assertSee(route('people.show', ['locale' => 'ar', 'person' => $person->id]), false);
        Livewire::test(CommandPalette::class)->set('term', 'zzzz-no-such')->assertSee(__('hc.cmdk_none_title'));

        // The header carries the palette; the projects list keeps its search term out of the address.
        $this->get('/ar/projects')->assertOk()->assertSeeLivewire(CommandPalette::class);
        Livewire::withQueryParams(['q' => 'leak'])->test(ProjectsIndex::class)->assertSet('search', '');
    }

    public function test_the_path_tiles_count_every_project_once_and_filter_the_list(): void
    {
        $this->actingAs($this->account('role.data-entry'));
        app(CityContext::class)->restrictTo(DB::table('cities')->pluck('id'));

        $component = Livewire::test(ProjectsIndex::class);
        $counts = $component->viewData('pathCounts');
        $this->assertSame(DB::table('projects')->whereNull('deleted_at')->count(), array_sum($counts));

        $component->call('togglePath', 'followup')->assertSet('path', 'followup');
        $this->assertSame($counts['followup'], $component->viewData('projects')->total());
        $component->call('togglePath', 'followup')->assertSet('path', '');
        $component->call('togglePath', 'nonsense')->assertSet('path', '');
    }
}
