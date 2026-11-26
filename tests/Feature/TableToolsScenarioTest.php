<?php

namespace Tests\Feature;

use App\Livewire\AttachmentsIndex;
use App\Livewire\KoboIndex;
use App\Livewire\LookupEditor;
use App\Livewire\ProfessionMeetingForm;
use App\Livewire\SpecialCaseList;
use App\Livewire\TaskBoard;
use App\Livewire\VisitResults;
use App\Livewire\VisitSchedule;
use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Assessments\ChecklistListService;
use App\Services\Attachments\AttachmentService;
use App\Services\Field\ProfessionMeetingService;
use App\Services\Field\SpecialCaseListService;
use App\Services\Field\VisitResultsService;
use App\Services\Field\VisitScheduleService;
use App\Services\Kobo\KoboReadService;
use App\Services\Staff\AccountAdminService;
use App\Services\Staff\AuditLogService;
use App\Services\Staff\NotificationInboxService;
use App\Services\Staff\TaskBoardService;
use App\Support\TableTools\TableSearch;
use App\Support\TableTools\TableSort;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyntheticSearchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Table tools (owner request, 25 Nov 2026: every table searches, filters and sorts). For the lists the server cuts into pages: each header
 * sorts the whole list (never only the page), a search typed in Arabic finds the stored spelling, text that may hold a name never reaches
 * an address, and the Excel/CSV file matches the screen. Tables whose rows are all on the page are enhanced in the browser
 * (resources/js/modules/tables.js); here only the markup that tells it to stay away is checked.
 */
class TableToolsScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // Checklists, attachments, M&E visits and profession meetings to list.
        $this->seed(SyntheticSearchSeeder::class);
    }

    private function notice(array $values): void
    {
        DB::table('web_notices')->insert($values + ['seen' => 0, 'created_at' => now()]);
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

    /** @return list<list<string>> */
    private function csv(string $content): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, ltrim($content, "\u{FEFF}"));
        rewind($stream);
        $lines = [];
        while (($line = fgetcsv($stream, escape: '')) !== false) {
            $line !== [null] && $lines[] = $line;
        }

        return $lines;
    }

    private function assertOrdered(array $values, string $dir, string $message = ''): void
    {
        $entered = array_values(array_filter($values, fn ($v) => $v !== null && $v !== ''));
        $sorted = $entered;
        $dir === 'asc' ? sort($sorted) : rsort($sorted);
        $this->assertSame($sorted, $entered, $message);
        // Empty values come last in both directions.
        $this->assertSame($entered, array_slice($values, 0, count($entered)), $message.' (empty values last)');
    }

    public function test_sort_keys_are_whitelisted_and_arabic_search_folds_both_sides(): void
    {
        $this->assertSame([null, 'asc'], TableSort::clean('id); drop table projects;--', 'desc', ['date']));
        $this->assertSame([null, 'asc'], TableSort::clean(['date'], 'desc', ['date']));
        $this->assertSame(['date', 'asc'], TableSort::clean('date', 'sideways', ['date']));
        $this->assertSame(['date', 'asc'], TableSort::next(null, 'asc', 'date'));
        $this->assertSame(['date', 'desc'], TableSort::next('date', 'asc', 'date'));
        $this->assertSame([null, 'asc'], TableSort::next('date', 'desc', 'date'));

        $query = DB::table('projects');
        $this->assertFalse(TableSort::apply($query, ['name' => 'name'], 'name desc, (select 1)', 'asc'));
        $this->assertStringNotContainsString('order by', $query->toSql());

        $this->assertSame(['احمد', 'فاطمه', '12'], TableSearch::words(' أَحْمَد  فاطمة ١٢ '));
        $this->assertTrue(TableSearch::matches('أحمد الحدّاد · DAM-12', 'حداد احمد 12'));
        $this->assertFalse(TableSearch::matches('أحمد', 'احمد سعيد'));
        $id = DB::table('donors')->insertGetId(['name_ar' => 'مؤسسة أمل_100%', 'name_en' => 'x', 'is_active' => 1, 'sort_order' => 1]);
        $found = function (string $term) {
            $query = DB::table('donors');
            TableSearch::apply($query, ['name_ar'], $term);

            return $query->pluck('id')->map(fn ($i) => (int) $i)->all();
        };
        $this->assertSame([$id], $found('امل_100%'), '% and _ are literal; hamza and taa marbuta are folded.');
        $this->assertSame([], $found('امل_1000'));
    }

    /** Every whitelisted column of every server list orders the query without an SQL error and without changing which rows are listed. */
    public function test_every_sortable_column_of_every_server_list_works(): void
    {
        $account = $this->as('super.admin');
        $run = function (string $list, array $keys, callable $total) {
            $all = $total([]);
            foreach ($keys as $key) {
                foreach (['asc', 'desc'] as $dir) {
                    $this->assertSame($all, $total(['sort' => $key, 'dir' => $dir]), "{$list} {$key} {$dir}");
                }
            }
        };
        foreach (['f', 'a'] as $kind) {
            $board = app(TaskBoardService::class);
            $run('task board '.$kind, TaskBoardService::sortKeys($board->columns($kind), $kind), fn ($f) => $board->board($account, $f + ['kind' => $kind])['rows']->total());
            $run('task board export '.$kind, TaskBoardService::sortKeys($board->columns($kind), $kind), fn ($f) => iterator_count($board->exportRows($account, $f + ['kind' => $kind])[1]));
        }
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $run('special cases', SpecialCaseListService::SORT_KEYS, fn ($f) => app(SpecialCaseListService::class)->report($account, SpecialCaseListService::clean($f))['rows']->total());
            $run('visit schedule', VisitScheduleService::SORT_KEYS, fn ($f) => app(VisitScheduleService::class)->schedule($account, $f)['rows']->total());
            foreach (['M', 'opening', 'closing'] as $visit) {
                $run('visit results '.$visit, VisitResultsService::sortKeys($visit), fn ($f) => app(VisitResultsService::class)->report($account, VisitResultsService::clean($f + ['view' => 'answers', 'visit' => $visit]))['rows']->total());
            }
            $run('kobo', KoboReadService::SORT_KEYS, fn ($f) => app(KoboReadService::class)->submissions($account, $f)->total());
            $run('checklists', ChecklistListService::SORT_KEYS, fn ($f) => app(ChecklistListService::class)->page($account, $f)['rows']->total());
            $run('meetings', ProfessionMeetingService::SORT_KEYS, fn ($f) => app(ProfessionMeetingService::class)->index($account, $f)['rows']->total());
            $run('attachments', AttachmentService::SORT_KEYS, fn ($f) => app(AttachmentService::class)->search($account, $f)->total());
            $run('accounts', AccountAdminService::SORT_KEYS, fn ($f) => app(AccountAdminService::class)->index($account, $f)['rows']->total());
            $run('notifications', NotificationInboxService::SORT_KEYS, fn ($f) => app(NotificationInboxService::class)->inbox($account, $f)['rows']->total());
            $run('desktop log', AuditLogService::DESKTOP_SORT_KEYS, fn ($f) => app(AuditLogService::class)->desktop($account, $f)['rows']->total());
            // The web trail grows with every listing (each one is recorded): only that each order runs.
            foreach (AuditLogService::WEB_SORT_KEYS as $key) {
                $this->assertGreaterThan(0, app(AuditLogService::class)->web($account, ['sort' => $key, 'dir' => 'desc'])['rows']->total(), $key);
                $this->assertGreaterThan(0, iterator_count(app(AuditLogService::class)->webExport($account, ['sort' => $key, 'dir' => 'asc'])), $key);
            }
        }
    }

    public function test_task_board_sorts_and_keeps_its_search_out_of_the_address(): void
    {
        $this->as('role.administrator');
        $numbers = fn ($page) => $page->viewData('rows')->getCollection()->pluck('project_number')->map(fn ($n) => (int) $n)->all();
        $page = Livewire::test(TaskBoard::class)->set('view', 'grid');
        $default = $numbers($page);
        $this->assertGreaterThan(3, count($default));
        $this->assertOrdered($default, 'desc');
        $page->call('sortBy', 'number')->assertSet('sortKey', 'number')->assertSet('sortDir', 'asc');
        $this->assertOrdered($numbers($page), 'asc');
        $page->assertSee('aria-sort="ascending"', false)->assertSee('data-smart="off"', false);
        $page->call('sortBy', 'number')->assertSet('sortDir', 'desc')->call('sortBy', 'number')->assertSet('sortKey', '');
        $page->call('sortBy', 'p.id; drop table projects')->assertSet('sortKey', '');
        Livewire::withQueryParams(['view' => 'grid', 'sort' => '1) or sleep(1', 'q' => 'سر'])->test(TaskBoard::class)->assertSet('q', '')->assertOk();

        // Without a search the export is a plain link carrying the sort; with one it goes through a single-use token.
        $page->call('sortBy', 'number')->assertSee('sort=number', false)->assertSee('/task-board/export?', false);
        $first = $page->viewData('rows')->first();
        $page->set('q', $first->code.'-'.$first->project_number)->assertDontSee('/task-board/export?', false);
        $page->call('exportList', 'csv');
        $url = $page->effects['redirect'];
        $this->assertStringContainsString('snapshot=', $url);
        $this->assertStringNotContainsString((string) $first->project_number.'&', $url);
        $this->assertStringNotContainsString('q=', $url);
        $lines = $this->csv($this->get($url)->assertOk()->streamedContent());
        $this->assertCount($page->viewData('rows')->total() + 1, $lines, 'The file holds exactly the searched projects.');
        $this->get($url)->assertNotFound();

        // Another account cannot use someone's token.
        $page->call('exportList', 'csv');
        $url = $page->effects['redirect'];
        $this->as('role.monitoring');
        $this->get($url)->assertNotFound();
    }

    public function test_special_cases_sort_every_page_and_export_the_searched_list_privately(): void
    {
        $this->as('role.data-entry');
        $dates = fn ($page) => $page->viewData('rows')->getCollection()->map(fn ($r) => $r[7])->values()->all();
        $page = Livewire::test(SpecialCaseList::class);
        $this->assertOrdered($dates($page), 'desc');
        $oldest = substr((string) DB::table('special_cases')->whereNull('deleted_at')->whereNotNull('case_date')->min('case_date'), 0, 10);
        $page->call('sortBy', 'date');
        $this->assertSame($oldest, $dates($page)[0], 'The sort covers the whole list, not the page.');
        $this->assertOrdered($dates($page), 'asc');

        $page->set('q', 'اعتراض')->assertDontSee('/special-cases/export?', false)->call('exportList', 'csv');
        $url = $page->effects['redirect'];
        $this->assertStringNotContainsString('q=', $url);
        $lines = $this->csv($this->get($url)->assertOk()->streamedContent());
        $this->assertCount($page->viewData('rows')->total() + 1, $lines);
        $this->assertSame($dates($page), array_column(array_slice($lines, 1), 7), 'Same order as the screen.');
        $this->get('/ar/special-cases/export?snapshot=short')->assertNotFound();
    }

    public function test_visit_schedule_searches_in_arabic_sorts_and_exports_what_it_shows(): void
    {
        $this->as('role.data-entry');
        $notice = DB::table('web_notices')->whereIn('kind', ['visit', 'clearance'])->whereNull('account_id')->orderBy('id')->first();
        if ($notice === null) {
            $project = DB::table('projects')->whereNull('deleted_at')->orderBy('id')->first();
            foreach (['2026-03-01', '2026-01-01', '2026-02-01'] as $i => $date) {
                $this->notice(['city_id' => $project->city_id, 'project_id' => $project->id, 'project_number' => $project->project_number, 'kind' => 'visit',
                    'notice_date' => $date, 'title' => 'x'.$i, 'body' => 'Visit M'.($i + 1).'-O']);
            }
            $notice = DB::table('web_notices')->where('title', 'x0')->first();
        }
        DB::table('web_notices')->where('id', $notice->id)->update(['title' => 'فاطمة الأحمد', 'seen' => 0]);

        $page = Livewire::test(VisitSchedule::class);
        $dates = fn () => $page->viewData('rows')->getCollection()->pluck('notice_date')->values()->all();
        $this->assertOrdered($dates(), 'asc');
        $page->call('sortBy', 'date')->call('sortBy', 'date')->assertSet('sortDir', 'desc');
        $this->assertOrdered($dates(), 'desc');
        $page->call('sortTo', 'beneficiary')->assertSet('sortKey', 'beneficiary')->assertSet('sortDir', 'asc')->call('sortTo', '-date')->assertSet('sortDir', 'desc')
            ->call('sortTo', 'mobile')->assertSet('sortKey', '');

        // "احمد فاطمه" finds "فاطمة الأحمد"; the export holds the same rows and no name in its address.
        $page->set('q', 'احمد فاطمه')->assertDontSee('/visits/schedule/export?', false);
        $this->assertSame([(int) $notice->id], $page->viewData('rows')->getCollection()->pluck('id')->map(fn ($i) => (int) $i)->all());
        $page->call('exportList', 'csv');
        $url = $page->effects['redirect'];
        $this->assertStringNotContainsString('q=', $url);
        $lines = $this->csv($this->get($url)->assertOk()->streamedContent());
        $this->assertCount(2, $lines);
        $this->assertSame('فاطمة الأحمد', $lines[1][2]);
        $this->get('/ar/visits/schedule/export?format=csv&sort=date&dir=desc')->assertOk();
    }

    public function test_visit_results_search_and_sort_the_answers(): void
    {
        $this->as('super.admin');
        $page = Livewire::test(VisitResults::class)->set('view', 'answers');
        $total = $page->viewData('total');
        $this->assertGreaterThan(1, $total);
        $dates = fn () => $page->viewData('rows')->getCollection()->map(fn ($r) => $r[3])->values()->all();
        $page->call('sortBy', 'date')->call('sortBy', 'date');
        $this->assertOrdered($dates(), 'desc');
        $first = $page->viewData('rows')->getCollection()->first();
        $page->set('q', $first[1]);
        $this->assertGreaterThan(0, $page->viewData('total'));
        $this->assertTrue($page->viewData('rows')->getCollection()->every(fn ($r) => $r[1] === $first[1]));
        $page->set('q', explode(' ', $first[2])[0])->assertDontSee('/visits/results/export?', false);
        $this->assertGreaterThan(0, $page->viewData('total'));
        // The counts view follows the same search.
        $page->set('view', 'counts');
        $this->assertSame($page->viewData('total'), Livewire::test(VisitResults::class)->set('q', explode(' ', $first[2])[0])->viewData('total'));
        $page->set('view', 'answers')->call('exportList', 'csv');
        $url = $page->effects['redirect'];
        $this->assertStringNotContainsString('q=', $url);
        $this->assertCount($page->viewData('total') + 1, $this->csv($this->get($url)->assertOk()->streamedContent()));
        $page->set('q', 'لا يوجد اسم كهذا');
        $this->assertSame(0, $page->viewData('total'));
    }

    public function test_kobo_sorts_both_lists_and_searches_answers_across_every_row(): void
    {
        $this->as('super.admin');
        $page = Livewire::test(KoboIndex::class)->call('sortBy', 'date');
        $days = $page->viewData('submissions')->getCollection()->pluck('day')->all();
        $this->assertOrdered($days, 'asc');
        $oldest = collect(app(KoboReadService::class)->submissions(auth()->user(), ['sort' => 'date', 'dir' => 'asc'])->items())->first()->day;
        $this->assertSame($oldest, $days[0]);

        $page->call('setTab', 'results')->assertSet('sortKey', '')->set('mode', 'answers');
        $all = count($page->viewData('results')['rows']);
        $numbers = fn () => array_map(fn ($r) => $r['context']['date'], $page->viewData('results')['rows']);
        $page->call('sortBy', 'date')->call('sortBy', 'date');
        $this->assertOrdered($numbers(), 'desc');
        $page->call('sortBy', 'a0')->assertSet('sortKey', 'a0')->assertOk();
        // Every key the headers can send is accepted by the export link.
        foreach (['subcategory', 'a499'] as $key) {
            $this->get('/ar/kobo/export?format=csv&sort='.$key.'&dir=desc')->assertOk();
        }

        $row = $page->viewData('results')['rows'][0];
        $page->set('search', $row['context']['person']);
        $found = $page->viewData('results')['rows'];
        $this->assertNotEmpty($found);
        $this->assertLessThan($all, count($found));
        $page->assertSee('wire:click="exportList(\'xlsx\')"', false)->call('exportList', 'csv');
        $url = $page->effects['redirect'];
        $this->assertStringNotContainsString('q=', $url);
        $this->assertCount(count($found) + 1, $this->csv($this->get($url)->assertOk()->streamedContent()));
        // A project number may travel in the link, and the file follows it.
        $page->set('search', $row['context']['number'])->assertSee('q='.$row['context']['number'], false);
        $lines = $this->csv($this->get('/ar/kobo/export?format=csv&q='.$row['context']['number'])->assertOk()->streamedContent());
        $this->assertCount(count($page->viewData('results')['rows']) + 1, $lines);
        // Typed text in the address is ignored: only numbers are read from it.
        $this->assertCount($all + 1, $this->csv($this->get('/ar/kobo/export?format=csv&q=zzz')->assertOk()->streamedContent()));
    }

    public function test_notification_search_is_posted_kept_in_the_session_and_followed_by_the_export(): void
    {
        $me = $this->as('role.administrator');
        $city = $this->city('DAM');
        foreach ([['أمّ جورج', '2026-01-05'], ['سعيد', '2026-02-05'], ['بسّام', '2026-03-05']] as [$title, $date]) {
            $this->notice(['city_id' => $city, 'account_id' => $me->id, 'kind' => 'message', 'notice_date' => $date, 'title' => $title, 'body' => 'نص '.$date]);
        }
        $this->get('/ar/notifications?sort=date&dir=asc')->assertOk()->assertSeeInOrder(['أمّ جورج', 'سعيد', 'بسّام'])->assertSee('aria-sort="ascending"', false);
        $this->get('/ar/notifications?sort=date&dir=desc')->assertOk()->assertSeeInOrder(['بسّام', 'سعيد', 'أمّ جورج']);

        $this->post('/ar/table-search/notifications', ['q' => 'ام جورج', 'back' => 'kind=message&page=3&q=leak'])->assertRedirect(route('notifications.index', ['locale' => 'ar', 'kind' => 'message']));
        $this->get('/ar/notifications?kind=message')->assertOk()->assertSee('أمّ جورج')->assertDontSee('سعيد')->assertDontSee('بسّام');
        $csv = $this->get('/ar/notifications/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('أمّ جورج', $csv);
        $this->assertStringNotContainsString('سعيد', $csv);
        // Another account does not inherit the search.
        $this->as('super.admin');
        $this->get('/ar/notifications')->assertOk()->assertDontSee('value="ام جورج"', false);
        $this->actingAs($me);
        $this->post('/ar/table-search/notifications', ['q' => 'ام جورج', 'clear' => '1'])->assertRedirect();
        $this->get('/ar/notifications')->assertOk()->assertSee('سعيد')->assertSee('أمّ جورج');
        $this->post('/ar/table-search/unknown', ['q' => 'x'])->assertNotFound();
        $this->post('/ar/table-search/notifications', ['q' => str_repeat('x', 101)])->assertSessionHasErrors('q');
    }

    public function test_meetings_search_and_sort(): void
    {
        $this->as('super.admin');
        $meeting = DB::table('profession_meetings')->whereNull('deleted_at')->orderBy('id')->first();
        $this->assertNotNull($meeting);
        DB::table('profession_meetings')->where('id', $meeting->id)->update(['place' => 'قاعة الأمل', 'meeting_date' => '2020-01-02']);
        $this->get('/ar/profession-meetings?sort=date&dir=asc')->assertOk()->assertSeeInOrder(['2020-01-02'])->assertSee('data-smart="off"', false);
        $this->post('/ar/table-search/meetings', ['q' => 'قاعه الامل'])->assertRedirect();
        $total = fn (string $q) => app(ProfessionMeetingService::class)->index(auth()->user(), ['q' => $q])['rows']->total();
        $this->assertSame(1, $total('قاعه الامل'));
        $this->get('/ar/profession-meetings')->assertOk()->assertSee('قاعة الأمل');
        $career = DB::table('careers')->where('id', $meeting->career_id)->value('name_ar');
        if ($career !== null) {
            $this->assertGreaterThan(0, $total($career));
        }
        $this->assertSame(0, $total('مكان لا وجود له'));
    }

    public function test_the_meeting_eligibility_list_searches_before_it_is_cut(): void
    {
        $this->as('super.admin');
        $project = DB::table('projects as p')->whereNotNull('p.career_id')->whereNull('p.deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('project_applicants as a')->whereColumn('a.project_id', 'p.id')->whereNull('a.deleted_at'))->orderBy('p.id')->first(['p.*']);
        $service = app(ProfessionMeetingService::class);
        $all = $service->beneficiaries(auth()->user(), (int) $project->city_id, [(int) $project->career_id]);
        $code = DB::table('cities')->where('id', $project->city_id)->value('code');
        $one = $service->beneficiaries(auth()->user(), (int) $project->city_id, [(int) $project->career_id], $code.'-'.$project->project_number);
        $this->assertCount(1, $one);
        $this->assertNotEmpty($all);
        $page = Livewire::test(ProfessionMeetingForm::class, ['city' => (int) $project->city_id])->call('toggleCareer', (int) $project->career_id)
            ->set('beneficiarySearch', 'لا يوجد اسم كهذا');
        $this->assertCount(0, $page->viewData('eligible'));
        $this->assertCount($all->count(), $page->viewData('beneficiaries'), 'The project-number suggestions keep the whole list.');
    }

    public function test_checklists_accounts_audit_and_attachments_sort_from_their_headers(): void
    {
        $this->as('super.admin');
        $this->get('/ar/checklists?sort=number&dir=desc&state=')->assertOk()->assertSee('aria-sort="descending"', false)->assertSee('data-smart="off"', false);
        $list = app(ChecklistListService::class);
        $state = (int) DB::table('checklists as c')->join('project_state_history as h', fn ($j) => $j->on('h.project_id', '=', 'c.project_id')->where('h.is_current', true))->value('h.project_state_id');
        $rows = $list->page(auth()->user(), ['state' => $state])['rows'];
        $this->assertGreaterThan(0, $rows->total());
        $this->assertLessThanOrEqual($list->page(auth()->user(), [])['rows']->total(), $rows->total());

        $names = fn (string $dir) => app(AccountAdminService::class)->index(auth()->user(), ['sort' => 'username', 'dir' => $dir])['rows']->getCollection()->pluck('username')->map(fn ($n) => strtolower($n))->all();
        $this->assertOrdered($names('asc'), 'asc');
        $this->assertOrdered($names('desc'), 'desc');
        $this->get('/ar/admin/accounts?sort=username&dir=desc&q=role')->assertOk()->assertSee('name="sort" value="username"', false)->assertSee('role.monitoring');
        $this->get('/ar/admin/accounts?sort=password_hash')->assertOk()->assertDontSee('aria-sort="ascending"', false);
        $this->get('/ar/admin/accounts?dir=up')->assertSessionHasErrors('dir');

        app(AccountAuditTrail::class)->record(auth()->id(), 'people.view', 'succeeded', $this->city('DAM'));
        $this->get('/ar/admin/audit?sort=action&dir=asc')->assertOk()->assertSee('aria-sort="ascending"', false)->assertSee('sort=action', false);
        $this->get('/ar/admin/audit/export?format=csv&sort=account&dir=desc')->assertOk();
        foreach ([['Rami', '2026-11-18 10:00:00', 'first-row'], ['Gabi', '2026-11-19 10:00:00', 'second-row']] as $i => [$user, $at, $text]) {
            DB::table('desktop_activity_log')->insert(['city_id' => $this->city('DAM'), 'legacy_id' => 900000 + $i, 'log_type' => $text, 'on_table' => 'Person', 'user_name' => $user, 'logged_at' => $at, 'seen' => false]);
        }
        $desktop = fn (array $f) => app(AuditLogService::class)->desktop(auth()->user(), $f)['rows']->getCollection()->pluck('user')->all();
        $this->assertOrdered(array_map('strtolower', $desktop(['sort' => 'user', 'dir' => 'asc'])), 'asc');
        $this->get('/ar/admin/audit/desktop?sort=time&dir=asc')->assertOk()->assertSee('aria-sort="ascending"', false);

        $uploaded = fn ($page) => $page->viewData('rows')->getCollection()->map(fn ($r) => (string) $r->uploaded_at)->all();
        $page = Livewire::test(AttachmentsIndex::class)->call('sortBy', 'uploaded');
        $this->assertOrdered($uploaded($page), 'asc');
        $row = DB::table('attachments')->whereNull('deleted_at')->whereNotNull('project_id')->orderBy('id')->first();
        DB::table('attachments')->where('id', $row->id)->update(['original_name' => 'إيصال الأجرة.pdf']);
        $this->assertSame(1, Livewire::test(AttachmentsIndex::class)->set('search', 'ايصال الاجره')->viewData('rows')->total());
    }

    public function test_lists_whole_on_the_page_keep_their_server_search_and_are_left_to_the_browser_for_sorting(): void
    {
        $this->as('super.admin');
        $id = (int) DB::table('donors')->orderBy('id')->value('id');
        DB::table('donors')->where('id', $id)->update(['name_ar' => 'جمعية الإغاثة']);
        $page = Livewire::test(LookupEditor::class, ['list' => 'donors'])->set('search', 'جمعيه الاغاثه');
        $this->assertSame([$id], $page->viewData('rows')->pluck('id')->map(fn ($i) => (int) $i)->all());
        $page->assertSee('data-smart="sort"', false);
        $this->get('/ar/projects')->assertOk()->assertSee('data-smart="off"', false);
        $this->get('/ar/payments')->assertOk()->assertSee('data-smart="off"', false);
    }
}
