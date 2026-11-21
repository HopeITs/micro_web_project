<?php

namespace Tests\Feature;

use App\Livewire\ProjectsIndex;
use App\Models\SavedView;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Support\CityContext;
use App\Support\Datasets\InvalidQuerySpec;
use App\Support\Datasets\ProjectsDataset;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/** Dynamic columns, header sort, rows per page and the Excel/CSV export on the projects list (smart-search plan, phase 2). */
class ProjectColumnsScenarioTest extends TestCase
{
    use RefreshDatabase;

    private WebAccount $dataEntry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('ar');
        $this->dataEntry = WebAccount::where('username', 'role.data-entry')->firstOrFail();
        $this->actingAs($this->dataEntry);
        app(CityContext::class)->restrictTo(DB::table('cities')->pluck('id'));
    }

    private function cityId(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    /** @return list<string> the column keys of the rendered table, in order */
    private function shownKeys($list): array
    {
        return array_column($list->viewData('table')['columns'], 'key');
    }

    /** Follows the list's export redirect and returns [headings, rows] of the file. */
    private function download($list, string $format): array
    {
        $url = $list->effects['redirect'] ?? null;
        $this->assertNotNull($url, 'the export redirects to its download');
        $this->assertStringNotContainsString('search', $url);
        $response = $this->get($url)->assertOk();
        $body = $response->streamedContent();
        if ($format === 'csv') {
            $lines = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), preg_split('/\r\n/', trim(substr($body, 3))));

            return [$lines[0], array_slice($lines, 1)];
        }
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $body);
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($v) => (string) $v, $row->toArray());
            }
        }
        $reader->close();
        @unlink($path);

        return [$rows[0], array_slice($rows, 1)];
    }

    public function test_columns_can_be_hidden_reordered_saved_in_a_view_and_restored(): void
    {
        $dataset = new ProjectsDataset;
        $list = Livewire::test(ProjectsIndex::class);
        $this->assertSame($dataset->defaultColumns(), $this->shownKeys($list));

        // New related columns exist and render.
        $list->call('setColumns', ['state', 'number', 'loan_amount', 'overdue_installments', 'state_changed_on', 'donor'])
            ->assertSet('columnError', null)->assertSet('tableColumns', 'state,number,loan_amount,overdue_installments,state_changed_on,donor')
            ->assertSeeHtml('data-label="'.__('hc.loan_amount').'"')->assertSeeHtml('data-label="'.__('hc.overdue_installments').'"')
            ->assertDontSeeHtml('data-label="'.__('hc.requested').'"');
        $this->assertSame(['state', 'number', 'loan_amount', 'overdue_installments', 'state_changed_on', 'donor'], $this->shownKeys($list));
        $first = $list->viewData('projects')->items()[0];
        $this->assertSame((int) DB::table('loans')->where('project_id', $first->id)->whereNull('deleted_at')->sum('amount'), (int) $first->col_loan_amount);

        // An unknown key is refused and leaves the columns as they were.
        $list->call('setColumns', ['number', 'password'])->assertSet('columnError', __('hc.columns_invalid'));
        $this->assertSame(['state', 'number', 'loan_amount', 'overdue_installments', 'state_changed_on', 'donor'], $this->shownKeys($list));
        try {
            $dataset->validColumns(['number', 'password']);
            $this->fail('an unknown column must be refused');
        } catch (InvalidQuerySpec $e) {
            $this->assertSame(['columns.1: unknown column [password]'], $e->errors());
        }
        $this->assertNotSame([], $dataset->columnErrors(['number', 'number']));

        // Saved with the view (columns alone are enough to save), then restored on a fresh list.
        $list->call('saveView', 'أعمدتي')->assertSet('viewError', null);
        $view = SavedView::sole();
        $this->assertSame(['state', 'number', 'loan_amount', 'overdue_installments', 'state_changed_on', 'donor'], $view->columns);
        $fresh = Livewire::test(ProjectsIndex::class);
        $this->assertSame($dataset->defaultColumns(), $this->shownKeys($fresh));
        $fresh->call('applyView', $view->id);
        $this->assertSame(['state', 'number', 'loan_amount', 'overdue_installments', 'state_changed_on', 'donor'], $this->shownKeys($fresh));

        // Hide some and overwrite; reset brings the defaults back.
        $fresh->call('setColumns', ['number', 'state'])->call('overwriteView', $view->id);
        $this->assertSame(['number', 'state'], $view->fresh()->columns);
        $fresh->call('resetColumns')->assertSet('tableColumns', '');
        $this->assertSame($dataset->defaultColumns(), $this->shownKeys($fresh));

        // Unknown keys in the address are dropped; a stored unknown key is left out with a notice.
        $url = Livewire::withQueryParams(['cols' => 'name,evil,number'])->test(ProjectsIndex::class);
        $this->assertSame(['name', 'number'], $this->shownKeys($url));
        $view->update(['columns' => ['number', 'evil']]);
        $again = Livewire::test(ProjectsIndex::class)->call('applyView', $view->id)->assertSet('viewNotice', __('hc.view_filters_dropped'));
        $this->assertSame(['number'], $this->shownKeys($again));
    }

    public function test_header_sort_ascending_descending_and_secondary(): void
    {
        $list = Livewire::test(ProjectsIndex::class)->set('perPage', 100);
        $list->call('sortBy', 'requested_amount')->assertSet('tableSort', 'requested_amount');
        $amounts = array_map(fn ($r) => (float) $r->col_requested_amount, $list->viewData('projects')->items());
        $sorted = $amounts;
        sort($sorted);
        $this->assertSame($sorted, $amounts);
        $this->assertStringContainsString('aria-sort="ascending"', $list->html());

        $list->call('sortBy', 'requested_amount')->assertSet('tableSort', '-requested_amount');
        $amounts = array_map(fn ($r) => (float) $r->col_requested_amount, $list->viewData('projects')->items());
        $sorted = $amounts;
        rsort($sorted);
        $this->assertSame($sorted, $amounts);
        $this->assertStringContainsString('aria-sort="descending"', $list->html());

        // Shift+click adds a secondary sort; a plain click on another column replaces both.
        $list->call('sortBy', 'city')->call('sortBy', 'request_date', true)->assertSet('tableSort', 'city,request_date');
        $rows = $list->viewData('projects')->items();
        $pairs = array_map(fn ($r) => [(int) $r->col_city, (string) $r->col_request_date], $rows);
        $expected = $pairs;
        usort($expected, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $this->assertSame($expected, $pairs);
        $list->call('sortBy', 'request_date', true)->assertSet('tableSort', 'city,-request_date');
        $list->call('sortBy', 'request_date', true)->assertSet('tableSort', 'city');
        $list->call('sortBy', 'city')->assertSet('tableSort', '-city')->call('sortBy', 'city')->assertSet('tableSort', '');

        // Only sortable, shown fields: names and national numbers are not sortable.
        $list->call('sortBy', 'beneficiary')->call('sortBy', 'nope')->assertSet('tableSort', '');

        // The sort is stored with a view and comes back.
        $list->call('sortBy', 'request_date')->call('sortBy', 'number', true)->call('saveView', 'مرتب');
        $this->assertSame([['field' => 'request_date', 'dir' => 'asc'], ['field' => 'number', 'dir' => 'asc']], SavedView::sole()->spec['sort']);
        Livewire::test(ProjectsIndex::class)->call('applyView', SavedView::sole()->id)->assertSet('tableSort', 'request_date,number');
    }

    public function test_rows_per_page(): void
    {
        $total = DB::table('projects')->whereNull('deleted_at')->count();
        $this->assertGreaterThan(50, $total);
        $list = Livewire::test(ProjectsIndex::class);
        $this->assertCount(15, $list->viewData('projects')->items());
        $list->set('perPage', 50);
        $this->assertCount(50, $list->viewData('projects')->items());
        $list->set('perPage', 100);
        $this->assertCount(min(100, $total), $list->viewData('projects')->items());
        $list->set('perPage', 7);
        $this->assertSame(15, $list->viewData('projects')->perPage());
    }

    public function test_excel_and_csv_export_exactly_the_shown_columns_filters_search_and_sort(): void
    {
        $dam = $this->cityId('DAM');
        $list = Livewire::test(ProjectsIndex::class)
            ->call('toggleCity', $dam)->call('toggleFilterValue', 'stage', 'full')
            ->call('setColumns', ['requested_amount', 'number', 'state', 'beneficiary'])->call('sortBy', 'requested_amount')->call('sortBy', 'requested_amount');
        $list->assertSeeHtml("exportTable('xlsx')")->assertSeeHtml("exportTable('csv')");
        $shown = $list->viewData('projects');
        $expectedCount = $shown->total();
        $this->assertGreaterThan(0, $expectedCount);

        $list->call('exportTable', 'xlsx');
        [$head, $rows] = $this->download($list, 'xlsx');
        $this->assertSame([__('hc.requested'), __('hc.number'), __('hc.state'), __('hc.beneficiary')], $head);
        $this->assertCount($expectedCount, $rows);
        $this->assertSame((new ProjectsDataset)->field('number')->cell($shown->items()[0])['text'], $rows[0][1]);
        $this->assertTrue(collect($rows)->every(fn ($r) => str_starts_with($r[1], 'DAM-')));
        $amounts = array_map(fn ($r) => (float) $r[0], $rows);
        $sorted = $amounts;
        rsort($sorted);
        $this->assertSame($sorted, $amounts);

        $event = DB::table('account_audit_events')->where('event_type', 'projects.exported')->sole();
        $this->assertSame($this->dataEntry->id, (int) $event->actor_account_id);
        $this->assertSame('export:projects/format:xlsx/rows:'.$expectedCount.'/cols:requested_amount.number.state.beneficiary/sensitive:beneficiary', $event->decision_locator);
        $this->assertNull($event->details);

        // The search (a name) narrows the CSV the same way and never appears in the address; the token is single-use.
        $person = DB::table('project_applicants as pa')->join('people as p', 'p.id', '=', 'pa.person_id')->join('projects as pr', 'pr.id', '=', 'pa.project_id')
            ->whereNotNull('p.national_number')->orderBy('pa.project_id')->first(['p.national_number', 'pr.project_number', 'pr.city_id']);
        $search = Livewire::test(ProjectsIndex::class)->set('search', $person->national_number)->call('setColumns', ['number', 'name']);
        $expected = $search->viewData('projects')->total();
        $search->call('exportTable', 'csv');
        $url = $search->effects['redirect'];
        $this->assertStringNotContainsString($person->national_number, $url);
        [$head, $rows] = $this->download($search, 'csv');
        $this->assertSame([__('hc.number'), __('hc.project')], $head);
        $this->assertCount($expected, $rows);
        $this->get($url)->assertNotFound();
        $this->assertSame(2, DB::table('account_audit_events')->where('event_type', 'projects.exported')->count());
    }

    public function test_export_stays_in_the_permitted_cities_and_needs_the_export_capability(): void
    {
        // A Damascus-only data entry account exports Damascus rows only, whatever the request says.
        $damascus = app(AccountProvisioner::class)->createPlain('test.damascus', 'test.damascus@hcsyria.org', Hash::make('a-long-test-password'), 'test:damascus-only');
        DB::table('web_accounts')->where('id', $damascus->id)->update(['password_changed_at' => now()]);
        app(AccessRoles::class)->assign($damascus->fresh(), $this->cityId('DAM'), 'data-entry', 'test:damascus-only');
        $this->actingAs($damascus->fresh());
        app(CityContext::class)->restrictTo([$this->cityId('DAM')]);
        $list = Livewire::test(ProjectsIndex::class)->call('toggleCity', $this->cityId('ALP'))->call('setColumns', ['number', 'city']);
        $list->call('exportTable', 'csv');
        [, $rows] = $this->download($list, 'csv');
        $this->assertSame(DB::table('projects')->whereNull('deleted_at')->where('city_id', $this->cityId('DAM'))->count(), count($rows));
        $this->assertTrue(collect($rows)->every(fn ($r) => str_starts_with($r[0], 'DAM-')));

        // A token is bound to its account.
        $list->call('exportTable', 'xlsx');
        $url = $list->effects['redirect'];
        $this->actingAs($this->dataEntry);
        $this->get($url)->assertNotFound();

        // Communication may read the list but never export: no buttons, the action and the route are refused.
        $reader = WebAccount::where('username', 'role.communication')->firstOrFail();
        $this->actingAs($reader);
        app(CityContext::class)->restrictTo(DB::table('cities')->pluck('id'));
        $denied = Livewire::test(ProjectsIndex::class)->assertDontSeeHtml('exportTable(');
        $this->assertFalse($denied->viewData('columnPicker')['can_export']);
        $denied->call('exportTable', 'xlsx')->assertForbidden();
        // Even with a token (planted as the list would leave it), the route refuses and records the refusal.
        $token = str_repeat('a', 40);
        Cache::put('dataset-export:'.$token, ['account' => $reader->id, 'component' => ProjectsIndex::class, 'format' => 'csv',
            'state' => [], 'columns' => ['number'], 'sort' => []], now()->addMinute());
        $this->get(route('projects.export', ['locale' => 'ar', 'token' => $token]))->assertForbidden();
        $this->assertDatabaseHas('account_audit_events', ['actor_account_id' => $reader->id, 'event_type' => 'access.denied', 'outcome' => 'denied']);
        $this->assertSame(0, DB::table('account_audit_events')->where('actor_account_id', $reader->id)->where('event_type', 'projects.exported')->count());
    }
}
