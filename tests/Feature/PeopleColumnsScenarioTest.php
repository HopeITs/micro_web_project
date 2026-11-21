<?php

namespace Tests\Feature;

use App\Livewire\PeopleIndex;
use App\Models\SavedView;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Support\CityContext;
use App\Support\Datasets\InvalidQuerySpec;
use App\Support\Datasets\PeopleDataset;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/** Dynamic columns, header sort, rows per page and the Excel/CSV export on the people list (smart-search plan, phase 2). */
class PeopleColumnsScenarioTest extends TestCase
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

    /** @return list<string> */
    private function shownKeys($list): array
    {
        return array_column($list->viewData('table')['columns'], 'key');
    }

    /** Follows the list's export redirect and returns [headings, rows] of the file. */
    private function download($list, string $format): array
    {
        $url = $list->effects['redirect'] ?? null;
        $this->assertNotNull($url, 'the export redirects to its download');
        $body = $this->get($url)->assertOk()->streamedContent();
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

    public function test_default_columns_match_the_former_table_and_extra_columns_render(): void
    {
        $dataset = new PeopleDataset;
        $list = Livewire::test(PeopleIndex::class);
        $this->assertSame(['name', 'national_number', 'birth_year', 'mobile', 'family_number', 'city', 'projects_count'], $this->shownKeys($list));
        $list->assertSeeHtml('class="row-link"');

        $list->call('setColumns', ['name', 'family_size', 'latest_project', 'latest_state', 'sex', 'age', 'registered'])->assertSet('columnError', null);
        $this->assertSame(['name', 'family_size', 'latest_project', 'latest_state', 'sex', 'age', 'registered'], $this->shownKeys($list));

        $withProject = (int) DB::table('project_applicants')->whereNull('deleted_at')->value('person_id');
        $list->set('search', (string) DB::table('people')->where('id', $withProject)->value('first_name'))->set('perPage', 100);
        $row = collect($list->viewData('people')->items())->firstWhere('id', $withProject);
        $this->assertNotNull($row);
        $latest = DB::table('project_applicants as a')->join('projects as p', 'p.id', '=', 'a.project_id')->where('a.person_id', $withProject)
            ->whereNull('a.deleted_at')->whereNull('p.deleted_at')->max('p.id');
        $this->assertSame((int) DB::table('projects')->where('id', $latest)->value('project_number'), (int) $row->col_latest_project);
        $this->assertStringContainsString('-', $dataset->field('latest_project')->cell($row)['text']);
    }

    public function test_columns_can_be_hidden_reordered_saved_in_a_view_and_restored(): void
    {
        $dataset = new PeopleDataset;
        $list = Livewire::test(PeopleIndex::class);
        $list->call('setColumns', ['city', 'name', 'sex', 'family_size'])->assertSet('columnError', null)->assertSet('tableColumns', 'city,name,sex,family_size')
            ->assertDontSeeHtml('data-label="'.__('people.fields.mobile').'"');
        $this->assertSame(['city', 'name', 'sex', 'family_size'], $this->shownKeys($list));

        $list->call('setColumns', ['name', 'password'])->assertSet('columnError', __('hc.columns_invalid'));
        $this->assertSame(['city', 'name', 'sex', 'family_size'], $this->shownKeys($list));
        try {
            $dataset->validColumns(['name', 'password']);
            $this->fail('an unknown column must be refused');
        } catch (InvalidQuerySpec $e) {
            $this->assertSame(['columns.1: unknown column [password]'], $e->errors());
        }

        $list->call('saveView', 'أعمدتي')->assertSet('viewError', null);
        $view = SavedView::sole();
        $this->assertSame(['city', 'name', 'sex', 'family_size'], $view->columns);
        $fresh = Livewire::test(PeopleIndex::class);
        $this->assertSame($dataset->defaultColumns(), $this->shownKeys($fresh));
        $fresh->call('applyView', $view->id);
        $this->assertSame(['city', 'name', 'sex', 'family_size'], $this->shownKeys($fresh));

        $fresh->call('setColumns', ['name', 'city'])->call('overwriteView', $view->id);
        $this->assertSame(['name', 'city'], $view->fresh()->columns);
        $fresh->call('resetColumns')->assertSet('tableColumns', '');
        $this->assertSame($dataset->defaultColumns(), $this->shownKeys($fresh));

        $url = Livewire::withQueryParams(['cols' => 'city,evil,name'])->test(PeopleIndex::class);
        $this->assertSame(['city', 'name'], $this->shownKeys($url));
        $view->update(['columns' => ['name', 'evil']]);
        $again = Livewire::test(PeopleIndex::class)->call('applyView', $view->id)->assertSet('viewNotice', __('hc.view_filters_dropped'));
        $this->assertSame(['name'], $this->shownKeys($again));
    }

    public function test_header_sort_and_rows_per_page(): void
    {
        $total = DB::table('people')->whereNull('deleted_at')->count();
        $this->assertGreaterThan(50, $total);
        $list = Livewire::test(PeopleIndex::class);
        $this->assertCount(15, $list->viewData('people')->items());
        $list->set('perPage', 50);
        $this->assertCount(50, $list->viewData('people')->items());
        $list->set('perPage', 100);
        $this->assertCount(min(100, $total), $list->viewData('people')->items());
        $list->set('perPage', 7);
        $this->assertSame(15, $list->viewData('people')->perPage());

        $list->set('perPage', 100)->call('setColumns', ['name', 'birth_date', 'projects_count', 'city']);
        $list->call('sortBy', 'projects_count')->assertSet('tableSort', 'projects_count');
        $counts = array_map(fn ($r) => (int) $r->col_projects_count, $list->viewData('people')->items());
        $sorted = $counts;
        sort($sorted);
        $this->assertSame($sorted, $counts);
        $this->assertStringContainsString('aria-sort="ascending"', $list->html());

        $list->call('sortBy', 'projects_count')->assertSet('tableSort', '-projects_count');
        $counts = array_map(fn ($r) => (int) $r->col_projects_count, $list->viewData('people')->items());
        $sorted = $counts;
        rsort($sorted);
        $this->assertSame($sorted, $counts);
        $this->assertStringContainsString('aria-sort="descending"', $list->html());

        // Secondary sort with Shift.
        $list->call('sortBy', 'city')->call('sortBy', 'birth_date', true)->assertSet('tableSort', 'city,birth_date');
        $pairs = array_map(fn ($r) => [(int) $r->col_city, (string) $r->col_birth_date], $list->viewData('people')->items());
        $expected = $pairs;
        usort($expected, fn ($a, $b) => $a <=> $b);
        $this->assertSame($expected, $pairs);

        // Names are sortable; national numbers and unknown fields are not.
        $list->call('sortBy', 'city')->call('sortBy', 'city')->call('sortBy', 'city')->call('sortBy', 'birth_date')->call('sortBy', 'birth_date')->call('sortBy', 'birth_date');
        $list->call('setColumns', ['name', 'national_number'])->call('sortBy', 'national_number')->call('sortBy', 'nope')->assertSet('tableSort', '');
        $list->call('sortBy', 'name')->call('saveView', 'مرتب');
        $this->assertSame([['field' => 'name', 'dir' => 'asc']], SavedView::sole()->spec['sort']);
        Livewire::test(PeopleIndex::class)->call('applyView', SavedView::sole()->id)->assertSet('tableSort', 'name');
    }

    public function test_excel_and_csv_export_exactly_the_shown_columns_filters_search_and_sort(): void
    {
        $dam = $this->cityId('DAM');
        $list = Livewire::test(PeopleIndex::class)->call('toggleCity', $dam)->call('toggleFilterValue', 'sex', 'female')
            ->call('setColumns', ['projects_count', 'name', 'city', 'national_number'])->call('sortBy', 'projects_count')->call('sortBy', 'projects_count');
        $list->assertSeeHtml("exportTable('xlsx')")->assertSeeHtml("exportTable('csv')");
        $expectedCount = $list->viewData('people')->total();
        $this->assertGreaterThan(0, $expectedCount);
        $this->assertSame(DB::table('people')->whereNull('deleted_at')->where('city_id', $dam)->where('sex', 'female')->count(), $expectedCount);

        $list->call('exportTable', 'xlsx');
        [$head, $rows] = $this->download($list, 'xlsx');
        $this->assertSame([__('people.projects'), __('people.name'), __('hc.city'), __('people.fields.national_number')], $head);
        $this->assertCount($expectedCount, $rows);
        $counts = array_map(fn ($r) => (int) $r[0], $rows);
        $sorted = $counts;
        rsort($sorted);
        $this->assertSame($sorted, $counts);
        $cityName = DB::table('cities')->where('id', $dam)->value('name_ar');
        $this->assertTrue(collect($rows)->every(fn ($r) => $r[1] !== '' && $r[2] === $cityName));

        $event = DB::table('account_audit_events')->where('event_type', 'people.exported')->sole();
        $this->assertSame($this->dataEntry->id, (int) $event->actor_account_id);
        $this->assertSame('export:people/format:xlsx/rows:'.$expectedCount.'/cols:projects_count.name.city.national_number/sensitive:name.national_number', $event->decision_locator);
        $this->assertNull($event->details);

        // The search (a national number) narrows the CSV and never appears in the address; the token is single-use.
        $national = (string) DB::table('people')->whereNull('deleted_at')->where('national_number', '<>', '')->value('national_number');
        $search = Livewire::test(PeopleIndex::class)->set('search', $national)->call('setColumns', ['name', 'mobile']);
        $expected = $search->viewData('people')->total();
        $this->assertGreaterThan(0, $expected);
        $search->call('exportTable', 'csv');
        $url = $search->effects['redirect'];
        $this->assertStringNotContainsString($national, $url);
        [$head, $rows] = $this->download($search, 'csv');
        $this->assertSame([__('people.name'), __('people.fields.mobile')], $head);
        $this->assertCount($expected, $rows);
        $this->get($url)->assertNotFound();
        $this->assertSame(2, DB::table('account_audit_events')->where('event_type', 'people.exported')->count());
    }

    public function test_export_stays_in_the_permitted_cities_and_needs_the_export_capability(): void
    {
        $damascus = app(AccountProvisioner::class)->createPlain('test.damascus', 'test.damascus@hcsyria.org', Hash::make('a-long-test-password'), 'test:damascus-only');
        DB::table('web_accounts')->where('id', $damascus->id)->update(['password_changed_at' => now()]);
        app(AccessRoles::class)->assign($damascus->fresh(), $this->cityId('DAM'), 'data-entry', 'test:damascus-only');
        $this->actingAs($damascus->fresh());
        app(CityContext::class)->restrictTo([$this->cityId('DAM')]);
        $list = Livewire::test(PeopleIndex::class)->call('toggleCity', $this->cityId('ALP'))->call('setColumns', ['name', 'city']);
        $list->call('exportTable', 'csv');
        [, $rows] = $this->download($list, 'csv');
        $this->assertSame(DB::table('people')->whereNull('deleted_at')->where('city_id', $this->cityId('DAM'))->count(), count($rows));

        // A token is bound to its account.
        $list->call('exportTable', 'xlsx');
        $url = $list->effects['redirect'];
        $this->actingAs($this->dataEntry);
        $this->get($url)->assertNotFound();

        // Guest may read the list but never export.
        $reader = WebAccount::where('username', 'role.guest')->firstOrFail();
        $this->actingAs($reader);
        app(CityContext::class)->restrictTo(DB::table('cities')->pluck('id'));
        $denied = Livewire::test(PeopleIndex::class)->assertDontSeeHtml('exportTable(');
        $this->assertFalse($denied->viewData('columnPicker')['can_export']);
        $denied->call('exportTable', 'xlsx')->assertForbidden();
        $token = str_repeat('a', 40);
        Cache::put('dataset-export:'.$token, ['account' => $reader->id, 'component' => PeopleIndex::class, 'format' => 'csv',
            'state' => [], 'columns' => ['name'], 'sort' => []], now()->addMinute());
        $this->get(route('people.export', ['locale' => 'ar', 'token' => $token]))->assertForbidden();
        $this->assertDatabaseHas('account_audit_events', ['actor_account_id' => $reader->id, 'event_type' => 'access.denied', 'outcome' => 'denied']);
        $this->assertSame(0, DB::table('account_audit_events')->where('actor_account_id', $reader->id)->where('event_type', 'people.exported')->count());
    }
}
