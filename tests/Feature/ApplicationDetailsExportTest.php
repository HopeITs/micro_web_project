<?php

namespace Tests\Feature;

use App\Livewire\SearchPage;
use App\Models\WebAccount;
use App\Services\Reports\ApplicationDetailsExport;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M4.18 user scenarios: the "all application details" export has one row per project of the applications search with every form field and
 * table as names (no ids), follows the filters and cities, is token based and audited; accounts without reports.export, and every other
 * search type, do not get it.
 */
class ApplicationDetailsExportTest extends TestCase
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

    public function test_the_export_has_one_row_per_project_with_names_and_is_audited(): void
    {
        $admin = $this->as('role.administrator');
        $dam = (int) DB::table('cities')->where('code', 'DAM')->value('id');
        $redirect = Livewire::test(SearchPage::class)->set('mode', 'applications')->set('cities', [$dam])->call('exportDetails', 'csv')->effects['redirect'];
        $this->assertMatchesRegularExpression('#/ar/search/export/[A-Za-z0-9]{40}$#', $redirect);
        $before = DB::table('account_audit_events')->where('event_type', 'reports.exported')->count();
        $csv = $this->get($redirect)->assertOk()->streamedContent();
        $projects = DB::table('projects')->where('city_id', $dam)->whereNull('deleted_at')->count();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $records = [];
        while (($r = fgetcsv($stream)) !== false) {
            $records[] = $r;
        }
        $this->assertGreaterThan(40, count($records[0]), 'Every form field is a column.');
        $this->assertContains(__('applications.grids.family'), $records[0]);
        $this->assertCount($projects + 1, $records, 'Header plus one row per project of the city.');
        $this->assertSame(array_unique(array_column(array_slice($records, 1), 0)), [DB::table('cities')->where('id', $dam)->value('name_ar')], 'Only the chosen city.');
        $this->assertSame(count($records[0]), count($records[1]));
        $this->assertDoesNotMatchRegularExpression('/\b(project_|_id)\b/', implode(' ', $records[0]), 'Headings are labels, not column names.');
        $this->assertSame($before + 1, DB::table('account_audit_events')->where('event_type', 'reports.exported')->count());
        $event = DB::table('account_audit_events')->where('event_type', 'reports.exported')->latest('id')->first();
        $this->assertStringStartsWith('search:applications-details/rows:', $event->decision_locator);
        $this->assertSame($admin->id, (int) $event->actor_account_id);
        $this->get($redirect)->assertNotFound(); // single use
    }

    public function test_only_the_applications_type_and_only_with_the_export_right(): void
    {
        $this->as('role.administrator');
        Livewire::test(SearchPage::class)->set('mode', 'family')->call('exportDetails', 'csv')->assertForbidden();

        $this->as('role.guest');
        $this->assertFalse(app(ApplicationDetailsExport::class)->canExport(auth()->user()));
        Livewire::test(SearchPage::class)->set('mode', 'applications')->assertDontSee(__('search.export_details', [], 'ar'))->call('exportDetails', 'csv')->assertForbidden();
    }
}
