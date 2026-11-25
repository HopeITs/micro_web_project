<?php

namespace Tests\Feature;

use App\Livewire\LookupEditor;
use App\Livewire\ProjectPurchases;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Finance\PurchasingService;
use App\Services\Lifecycle\LifecycleService;
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
 * M4.10 user scenarios: data entry and legal record purchases (add, edit, delete a row, totals per currency) for funded projects; financial,
 * guest and media are refused; a project outside the funded states and another city are refused; the categories are a shared list.
 */
class PurchasingScenarioTest extends TestCase
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

    /** A project in the given state (default Accepted: one of the desktop's "Yes" states) with no purchases. */
    private function project(string $state = 'Accepted'): object
    {
        $project = DB::table('projects')->whereExists(fn ($q) => $q->selectRaw('1')->from('project_applicants as a')->whereColumn('a.project_id', 'projects.id'))->orderBy('id')->first();
        DB::table('purchases')->where('project_id', $project->id)->delete();
        DB::table('project_state_history')->where('project_id', $project->id)->delete();
        app(LifecycleService::class)->recordInitial($project, $state);

        return $project;
    }

    private function category(): int
    {
        return (int) DB::table('purchasing_categories')->insertGetId(['name_ar' => 'أدوات', 'name_en' => 'Tools', 'sort_order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function rows(int $projectId)
    {
        return DB::table('purchases')->where('project_id', $projectId)->whereNull('deleted_at')->orderBy('id')->get();
    }

    private function fill($page, int $index, array $values)
    {
        foreach ($values as $key => $value) {
            $page->set("rows.$index.$key", $value);
        }

        return $page;
    }

    public function test_data_entry_records_edits_and_deletes_purchases_with_totals_per_currency(): void
    {
        $category = $this->category();
        $this->as('role.data-entry');
        $project = $this->project('Financed');
        $this->get('/ar/projects/'.$project->id)->assertOk()->assertSee(route('purchasing.show', ['locale' => 'ar', 'project' => $project->id]));
        $this->get('/ar/applications/'.$project->id.'/purchases')->assertOk()->assertSeeLivewire(ProjectPurchases::class)->assertSee(__('purchasing.no_loan', [], 'ar'));
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'purchasing.view')->where('subject_id', $project->id)->exists());

        $page = Livewire::test(ProjectPurchases::class, ['projectId' => $project->id])->assertSet('rows', [])->call('addRow')->call('addRow')->call('addRow');
        $this->fill($page, 0, ['item_name' => 'مثقاب', 'brand' => 'Bosch', 'category_id' => (string) $category, 'quantity' => '2', 'price' => '10.50', 'currency' => 'USD', 'invoice_date' => '2026-03-01', 'invoice_number' => 'F-1', 'trader_name' => 'متجر'])
            ->set('rows.1.item_name', 'مولدة')->set('rows.1.quantity', '1')->set('rows.1.price', '1,500,000')->set('rows.1.currency', 'SYP');
        // Row 3 stays empty and is not saved; the totals follow the grid, per currency.
        $this->assertSame(['USD' => '21', 'SYP' => '1500000'], $page->viewData('totals'));
        $page->call('save')->assertHasNoErrors()->assertSee(__('purchasing.saved', [], 'ar'));
        $rows = $this->rows($project->id);
        $this->assertCount(2, $rows);
        $this->assertSame('21', rtrim(rtrim((string) bcmul((string) $rows[0]->quantity, (string) $rows[0]->price, 2), '0'), '.'));
        $this->assertSame($category, (int) $rows[0]->purchasing_category_id);
        $this->assertSame('SYP', $rows[1]->currency);
        $event = DB::table('account_audit_events')->where('event_type', 'purchasing.saved')->latest('id')->first();
        $this->assertStringContainsString('"field":"item_added"', (string) $event->details);

        // Edit one row: only that row changes, and the audit keeps before/after.
        Livewire::test(ProjectPurchases::class, ['projectId' => $project->id])->set('rows.0.quantity', '3')->call('save')->assertHasNoErrors();
        $this->assertSame(3, (int) $this->rows($project->id)[0]->quantity);
        $event = DB::table('account_audit_events')->where('event_type', 'purchasing.saved')->latest('id')->first();
        $this->assertStringContainsString('_quantity"', (string) $event->details);
        $this->assertSame(2, $this->rows($project->id)->count());

        // Deleting a saved row is soft and audited; the other row survives.
        $page = Livewire::test(ProjectPurchases::class, ['projectId' => $project->id])->call('deleteItem', 1)->assertHasNoErrors()->assertSee(__('purchasing.deleted', [], 'ar'));
        $this->assertCount(1, $this->rows($project->id));
        $this->assertSame(1, DB::table('purchases')->where('project_id', $project->id)->whereNotNull('deleted_at')->count());
        $this->assertSame(['USD' => '31.5'], $page->viewData('totals'));
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'purchasing.deleted')->exists());
        $page->set('rows.0.price', '12')->call('save')->assertHasNoErrors();
        $this->assertSame('12', rtrim(rtrim((string) $this->rows($project->id)[0]->price, '0'), '.'));
    }

    public function test_legal_records_purchases_and_the_english_page_renders(): void
    {
        $this->as('role.legal');
        $project = $this->project();
        $this->get('/en/applications/'.$project->id.'/purchases')->assertOk()->assertSee(__('purchasing.title', [], 'en'));
        $page = Livewire::test(ProjectPurchases::class, ['projectId' => $project->id])->call('addRow');
        $this->fill($page, 0, ['item_name' => 'Pump', 'quantity' => '4', 'price' => '25'])->call('save')->assertHasNoErrors();
        $this->assertCount(1, $this->rows($project->id));
    }

    public function test_validation_a_row_needs_a_name_and_numbers_must_be_valid(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $page = Livewire::test(ProjectPurchases::class, ['projectId' => $project->id])->call('addRow');
        $this->fill($page, 0, ['price' => '5'])->call('save')->assertHasErrors('rows.0.item_name');
        $this->fill($page, 0, ['item_name' => 'x', 'quantity' => '-1'])->call('save')->assertHasErrors('rows.0.quantity');
        $this->fill($page, 0, ['quantity' => '1', 'price' => '-2'])->call('save')->assertHasErrors('rows.0.price');
        $this->fill($page, 0, ['price' => '1.234'])->call('save')->assertHasErrors('rows.0.price');
        $this->fill($page, 0, ['price' => '1', 'currency' => 'EUR'])->call('save')->assertHasErrors('rows.0.currency');
        $this->fill($page, 0, ['currency' => 'USD', 'category_id' => '99999'])->call('save')->assertHasErrors('rows.0.category_id');
        $this->assertCount(0, $this->rows($project->id));
    }

    public function test_financial_guest_and_media_are_refused_and_another_city_is_not_found(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $page = Livewire::test(ProjectPurchases::class, ['projectId' => $project->id])->call('addRow');
        $this->fill($page, 0, ['item_name' => 'سقالة', 'price' => '9'])->call('save')->assertHasNoErrors();

        foreach (['role.financial', 'role.guest', 'role.media', 'role.monitoring', 'role.communication'] as $user) {
            $this->as($user);
            $this->get('/ar/applications/'.$project->id.'/purchases')->assertForbidden();
            Livewire::test(ProjectPurchases::class, ['projectId' => $project->id])->assertForbidden();
            $this->get('/ar/projects/'.$project->id)->assertOk()->assertDontSee(route('purchasing.show', ['locale' => 'ar', 'project' => $project->id]));
        }
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'access.denied')->exists());
        $this->assertCount(1, $this->rows($project->id));

        $account = $this->as('role.data-entry');
        DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', $project->city_id)->update(['revoked_at' => now()]);
        $this->get('/ar/applications/'.$project->id.'/purchases')->assertNotFound();
    }

    public function test_a_project_outside_the_funded_states_is_refused_with_a_message(): void
    {
        $this->as('role.data-entry');
        foreach (['Under Review', 'Rejected', 'Waiting', 'Withdrew'] as $state) {
            $project = $this->project($state);
            $this->get('/ar/applications/'.$project->id.'/purchases')->assertOk()->assertSee(__('purchasing.not_funded', [], 'ar'))->assertDontSee(__('purchasing.add', [], 'ar'));
            $page = Livewire::test(ProjectPurchases::class, ['projectId' => $project->id])->call('addRow');
            $this->fill($page, 0, ['item_name' => 'x', 'price' => '1'])->call('save')->assertHasErrors('rows');
            $this->assertCount(0, $this->rows($project->id), $state);
            $this->assertFalse(app(PurchasingService::class)->eligible($project->id));
        }
        foreach (['Accepted', 'Financed', 'Finished', 'Canceled', 'Closed'] as $state) {
            $this->assertTrue(app(PurchasingService::class)->eligible($this->project($state)->id), $state);
        }
    }

    public function test_a_stale_page_is_refused_and_rows_of_another_project_cannot_be_edited(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $stale = Livewire::test(ProjectPurchases::class, ['projectId' => $project->id])->call('addRow');
        $other = Livewire::test(ProjectPurchases::class, ['projectId' => $project->id])->call('addRow');
        $this->fill($other, 0, ['item_name' => 'A', 'price' => '1'])->call('save')->assertHasNoErrors();
        $this->fill($stale, 0, ['item_name' => 'B', 'price' => '2'])->call('save')->assertHasErrors('version');
        $this->assertSame(['A'], $this->rows($project->id)->pluck('item_name')->all());

        // A purchase of another project cannot be edited from this project's page.
        $foreign = DB::table('purchases')->insertGetId(['city_id' => $project->city_id, 'project_id' => $project->id + 1, 'item_name' => 'foreign', 'quantity' => 1, 'price' => '1', 'currency' => 'USD']);
        $rows = [['id' => $foreign, 'item_name' => 'hijack', 'quantity' => '1', 'price' => '9', 'currency' => 'USD']];
        $version = app(PurchasingService::class)->version(app(PurchasingService::class)->items($project->id));
        try {
            app(PurchasingService::class)->save(WebAccount::where('username', 'role.data-entry')->firstOrFail(), $project->id, $rows, $version);
            $this->fail('A foreign row was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rows', $e->errors());
        }
        $this->assertSame('foreign', DB::table('purchases')->where('id', $foreign)->value('item_name'));
    }

    public function test_purchasing_categories_are_a_shared_list_for_lookup_managers(): void
    {
        $this->as('role.administrator');
        $this->get('/ar/lookups')->assertOk()->assertSee(__('lookups.names.purchasing_categories', [], 'ar'));
        $this->get('/ar/lookups/purchasing_categories')->assertOk();
        Livewire::test(LookupEditor::class, ['list' => 'purchasing_categories'])->set('draft.name_ar', 'معدات')->set('draft.name_en', 'Equipment')->call('create')->assertHasNoErrors();
        $this->assertTrue(DB::table('purchasing_categories')->where('name_ar', 'معدات')->exists());

        $this->as('role.data-entry');
        $this->get('/ar/lookups/purchasing_categories')->assertOk();
        $this->as('role.financial');
        $this->get('/ar/lookups/purchasing_categories')->assertForbidden();
    }

    public function test_the_role_sync_moves_the_capabilities_of_existing_holders(): void
    {
        $city = (int) DB::table('cities')->where('code', 'DAM')->value('id');
        $provisioner = app(AccountProvisioner::class);
        $make = function (string $name, array $roles, array $caps) use ($provisioner, $city) {
            $account = $provisioner->createPlain($name, $name.'@hcsyria.org', Hash::make('a-long-test-password'), 'test:sync');
            foreach ($roles as $role) {
                DB::table('account_city_roles')->insert(['account_id' => $account->id, 'city_id' => $city, 'role' => $role, 'decision_locator' => 'test:sync', 'assigned_at' => now('UTC')]);
            }
            foreach ($caps as $cap) {
                $provisioner->grant($account->fresh(), $city, $cap, 'test:sync');
            }

            return $account->fresh();
        };
        $held = fn ($account) => DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', $city)->whereNull('revoked_at')->pluck('capability')->all();
        $financial = $make('sync.financial', ['financial'], ['finance.view', 'executive_files.view', 'executive_files.manage', 'purchasing.view', 'purchasing.manage']);
        $both = $make('sync.both', ['financial', 'legal'], ['executive_files.view', 'executive_files.manage', 'purchasing.view', 'purchasing.manage']);
        $clerk = $make('sync.clerk', ['data-entry'], ['applications.view']);

        $this->artisan('staff:sync-export-roles', ['--decision' => 'test:sync'])->assertSuccessful();

        foreach (['executive_files.manage', 'purchasing.view', 'purchasing.manage'] as $gone) {
            $this->assertNotContains($gone, $held($financial));
            $this->assertContains($gone, $held($both), 'Legal still includes it.');
        }
        $this->assertContains('executive_files.view', $held($financial));
        $this->assertContains('purchasing.manage', $held($clerk));
        $this->assertNotContains('executive_files.manage', $held($clerk));
    }

    public function test_roles_and_the_palette(): void
    {
        foreach (['administrator', 'data-entry', 'legal'] as $role) {
            $this->assertContains('purchasing.manage', AccessRoles::capabilities($role), $role);
        }
        foreach (['financial', 'guest', 'media', 'monitoring', 'communication'] as $role) {
            $this->assertNotContains('purchasing.view', AccessRoles::capabilities($role), $role);
        }
        $this->assertContains('executive_files.view', AccessRoles::capabilities('financial'));
        $this->assertNotContains('executive_files.manage', AccessRoles::capabilities('financial'));
        $this->assertNotContains('executive_files.manage', AccessRoles::capabilities('data-entry'));

        $project = $this->project();
        $term = ProjectNumberAllocator::display(DB::table('cities')->where('id', $project->city_id)->value('code'), (int) $project->project_number);
        $urls = fn (string $user) => array_column(app(GlobalSearch::class)->search(WebAccount::where('username', $user)->firstOrFail(), $term)['commands'], 'url');
        $url = route('purchasing.show', ['locale' => app()->getLocale(), 'project' => $project->id]);
        $this->assertContains($url, $urls('role.data-entry'));
        $this->assertContains($url, $urls('role.legal'));
        $this->assertNotContains($url, $urls('role.financial'));
        $this->assertNotContains($url, $urls('role.guest'));
    }
}
