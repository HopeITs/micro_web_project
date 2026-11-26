<?php

namespace Tests\Feature;

use App\Livewire\SearchPage;
use App\Models\SavedView;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Reports\ProjectReportFilters;
use App\Services\Reports\ReportFilterFields;
use App\Services\Reports\Search\ApplicationSearch;
use App\Services\Reports\Search\SearchModes;
use App\Services\Reports\Search\SearchService;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyntheticSearchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\TestCase;

/**
 * M4.17-A user scenarios: the administrator sees the 22 search types and each returns the rows a direct query counts; data entry has no loans;
 * the financial role sees loans with the SYP/USD summary; guest, legal, media and monitoring see what their capabilities allow; a one-city
 * account never sees another city's rows; the common filters narrow (two age ranges both apply); the search text never reaches an address;
 * the export is token based, authorised and audited.
 *
 * Filter bar (25 November 2026): every type sorts over all pages by its listed columns; the count beside a value is what ticking it gives
 * and "is none of" is its complement, in every type; changing the type keeps the filters it also takes; saved views never hold the text.
 */
class SearchScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['applications', 'family', 'educations', 'attachments', 'checklists', 'loans', 'visit-dates', 'me-visits', 'special-cases', 'meetings', 'materials', 'project-power',
        'home-power', 'relief-aids', 'aid-sources', 'assets', 'illness', 'losses', 'current-works', 'work-experiences', 'courses', 'goals'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // Detail rows for the types the base seed leaves empty (checklists, attachments, meetings, person detail tables); other tests expect those empty.
        $this->seed(SyntheticSearchSeeder::class);
    }

    private function as(string $username): WebAccount
    {
        $account = WebAccount::where('username', $username)->firstOrFail();
        $this->actingAs($account);

        return $account;
    }

    /** A new account holding the given capabilities in the given cities (ids), signed in. */
    private function custom(string $name, array $capabilities, array $cities): WebAccount
    {
        $provisioner = app(AccountProvisioner::class);
        $account = $provisioner->createPlain($name, $name.'@hcsyria.org', Hash::make('a-long-test-password'), 'test:search');
        foreach ($cities as $city) {
            foreach ($capabilities as $capability) {
                $provisioner->grant($account, $city, $capability, 'test:search');
            }
        }
        // A normal password (not the one-time kind), so no page redirects to the password change.
        DB::table('web_accounts')->where('id', $account->id)->update(['password_hash' => Hash::make('a-long-test-password'), 'password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        $account = $account->fresh();
        $this->actingAs($account);

        return $account;
    }

    private function page(string $mode, array $set = [])
    {
        $page = Livewire::test(SearchPage::class)->set('mode', $mode);
        foreach ($set as $key => $value) {
            $page->set($key, $value);
        }

        return $page;
    }

    private function keys(WebAccount $account): array
    {
        return array_keys(app(SearchService::class)->available($account));
    }

    /** Rows each type must list on the seeded data, from direct queries. */
    private function expected(string $key): int
    {
        $count = fn (string $table) => DB::table($table)->whereNull('deleted_at')->count();
        $withState = 'from projects p join (select project_id, max(id) h from project_state_history where is_current = 1 and deleted_at is null group by project_id) cur on cur.project_id = p.id';

        return match ($key) {
            'applications' => (int) DB::selectOne("select count(*) c $withState left join project_applicants a on a.project_id = p.id and a.deleted_at is null left join loans l on l.project_id = p.id and l.deleted_at is null where p.deleted_at is null")->c,
            'family' => $count('family_members'), 'attachments' => $count('attachments'), 'checklists' => $count('checklists'), 'loans' => $count('loans'),
            'visit-dates' => (int) DB::selectOne("select count(*) c $withState where p.deleted_at is null")->c, 'me-visits' => $count('me_visits'), 'special-cases' => $count('special_cases'),
            'meetings' => $count('profession_meetings'), 'materials' => $count('project_materials'), 'project-power' => $count('project_power_sources'), 'home-power' => $count('person_power_sources'),
            'relief-aids' => $count('person_aid_received'), 'aid-sources' => $count('person_aid_sources'), 'assets' => $count('person_assets'), 'illness' => $count('person_illnesses'),
            'losses' => $count('person_losses'), 'courses' => $count('person_courses'), 'goals' => $count('person_goals'),
            'current-works' => DB::table('person_work_experiences')->whereNull('deleted_at')->where('status', 'yes')->count(),
            'work-experiences' => DB::table('person_work_experiences')->whereNull('deleted_at')->where('status', 'no')->count(),
            'educations' => (int) DB::selectOne('select count(*) c from family_members fm join project_applicants a on a.person_id = coalesce(fm.provider_person_id, fm.person_id) and a.deleted_at is null where fm.deleted_at is null')->c,
        };
    }

    public function test_the_administrator_sees_22_types_and_each_returns_the_direct_count(): void
    {
        $admin = $this->as('role.administrator');
        $this->assertSame(self::ALL, array_values(array_intersect(self::ALL, $this->keys($admin))));
        $this->assertCount(22, $this->keys($admin));
        $this->assertSame(22, count(SearchModes::keys()));

        $this->get('/ar/search')->assertOk()->assertSeeLivewire(SearchPage::class)->assertSee(route('search.index', ['locale' => 'ar']));
        $this->get('/en/search')->assertOk()->assertSee('Search by');
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'reports.search')->exists());
        $this->assertNotNull(collect(Navigation::for($admin)['tools'])->firstWhere('key', 'search')['url']);

        foreach (self::ALL as $key) {
            $page = $this->page($key)->assertOk();
            $mode = SearchModes::make($key);
            $rows = $page->viewData('rows');
            $this->assertGreaterThan(0, $rows->total(), "$key has rows on the seeded data");
            $this->assertSame($this->expected($key), $rows->total(), "$key row count equals a direct query");
            $this->assertGreaterThan(0, $page->viewData('total'));
            foreach ($page->viewData('cells') as $line) {
                $this->assertCount(count($mode->headings()), $line['cells'], "$key cells match the headings");
            }
            $this->assertNotContains('', array_map(fn ($h) => str_contains($h, 'search.columns') ? '' : 'ok', $mode->headings()), "$key headings are translated");
        }
        // The counter is the distinct projects for a project type and the rows otherwise.
        $this->assertSame(200, $this->page('applications')->viewData('total'));
        $this->assertSame(DB::table('loans')->count(), $this->page('loans')->viewData('total'));
        $this->assertSame(DB::table('family_members')->count(), $this->page('family')->viewData('total'));
        $this->assertSame(DB::table('project_materials')->distinct()->count('project_id'), $this->page('materials')->viewData('total'));
        // Column headings exist in both languages.
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (self::ALL as $key) {
                foreach (SearchModes::make($key)->headings() as $heading) {
                    $this->assertDoesNotMatchRegularExpression('/^search\.columns\./', $heading, "$key/$locale");
                }
            }
        }
        app()->setLocale('ar');
    }

    public function test_applications_totals_marker_sustainability_and_links(): void
    {
        $this->as('role.administrator');
        $page = $this->page('applications');
        $summary = $page->viewData('summary');
        $this->assertSame(200, $summary['projects']);
        $this->assertSame((int) DB::table('people as pe')->join('project_applicants as a', 'a.person_id', '=', 'pe.id')->whereNull('a.deleted_at')->sum('pe.household_size'), $summary['members']);
        $first = $page->viewData('cells')[0];
        $this->assertNotNull($first['url'], 'The number opens the project where the reader may.');
        $this->assertStringContainsString('/projects/', $first['url']);

        // The same name on two different people is marked on both rows (a chip, not only a colour).
        $a = DB::table('people as pe')->join('project_applicants as pa', 'pa.person_id', '=', 'pe.id')->where('pe.city_id', 1)->orderBy('pe.id')->first(['pe.*', 'pa.project_id']);
        $other = DB::table('projects')->where('city_id', 1)->where('id', '<>', $a->project_id)->orderBy('id')->value('id');
        $twin = DB::table('people')->insertGetId(['city_id' => 1, 'first_name' => $a->first_name, 'last_name' => $a->last_name, 'father_name' => $a->father_name, 'sex' => 'male', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('project_applicants')->insert(['city_id' => 1, 'project_id' => $other, 'person_id' => $twin, 'role' => 'شريك', 'created_at' => now(), 'updated_at' => now()]);
        $found = $this->page('applications', ['term' => $a->first_name]);
        $flagged = collect($found->viewData('cells'))->where('flag', true)->count();
        $this->assertGreaterThanOrEqual(2, $flagged);
        $found->assertSee(__('search.duplicate', [], 'ar'));

        // Sustainability: closed → loan to state date; financed → loan to today; otherwise empty.
        $today = CarbonImmutable::parse('2026-11-23');
        $this->assertEqualsWithDelta(2.0, ApplicationSearch::sustainability('Closed', '2023-01-01', '2025-01-01', $today), 0.01);
        $this->assertSame(round(CarbonImmutable::parse('2024-11-23')->diffInDays($today) / 365.25, 2), ApplicationSearch::sustainability('Financed', '2024-11-23', '', $today));
        $this->assertSame('', ApplicationSearch::sustainability('Under Review', '2024-11-23', '', $today));
        $this->assertSame('', ApplicationSearch::sustainability('Financed', '', '', $today));

        // No link without the capability of the target in the row's city: applications + people only.
        $this->custom('search.nolink', ['applications.view', 'people.view'], [1]);
        $line = $this->page('applications')->viewData('cells')[0];
        $this->assertNull($line['url']);
    }

    public function test_data_entry_has_no_loans_and_financial_sees_loans_with_the_summary(): void
    {
        $dataEntry = $this->as('role.data-entry');
        $keys = $this->keys($dataEntry);
        $this->assertNotContains('loans', $keys);
        $this->assertContains('checklists', $keys);
        $this->assertContains('special-cases', $keys);
        Livewire::test(SearchPage::class)->set('mode', 'loans')->assertForbidden();
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'access.denied')->exists());

        $this->as('role.financial');
        $this->assertContains('loans', $this->keys(WebAccount::where('username', 'role.financial')->first()));
        // Money of one loan in USD (rate 15000): paid 50 of 100.
        $loan = DB::table('loans')->orderBy('id')->first();
        DB::table('loans')->where('id', $loan->id)->update(['currency' => 'USD', 'amount' => 100, 'exchange_rate' => 15000, 'returned_amount' => 100, 'installment_amount' => 25, 'rate' => 100]);
        DB::table('loan_installments')->where('loan_id', $loan->id)->delete();
        foreach ([1 => true, 2 => true, 3 => false, 4 => false] as $i => $paid) {
            DB::table('loan_installments')->insert(['city_id' => $loan->city_id, 'loan_id' => $loan->id, 'amount' => 25, 'due_on' => now()->subMonths(5 - $i)->toDateString(), 'is_paid' => $paid,
                'received_on' => $paid ? now()->subMonths(5 - $i)->toDateString() : null, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('donor_groups')->where('id', DB::table('projects')->where('id', $loan->project_id)->value('donor_group_id'))->update(['name_en' => 'Installment loans']);

        $page = $this->page('loans');
        $summary = $page->viewData('summary');
        $this->assertSame(['SYP', 'USD'], array_keys($summary));
        $this->assertEqualsWithDelta(100, (float) $summary['USD']['loans'], 0.001);
        $this->assertEqualsWithDelta(50, (float) $summary['USD']['repayments'], 0.001);
        $this->assertEqualsWithDelta(50, (float) $summary['USD']['net'], 0.001);
        $this->assertEqualsWithDelta(100, (float) $summary['USD']['repayment_loans'], 0.001);
        $this->assertEqualsWithDelta(-50, (float) $summary['USD']['balance'], 0.001);
        $this->assertSame(DB::table('loans')->where('currency', 'SYP')->count(), $summary['SYP']['count']);
        $this->assertEqualsWithDelta((float) DB::table('loans')->where('currency', 'SYP')->sum('amount'), (float) $summary['SYP']['loans'], 0.01, 'Currencies are never added together.');

        $headings = SearchModes::make('loans')->headings();
        $row = collect($this->page('loans', ['term' => $this->numberOf($loan->project_id)])->viewData('cells'))->first(fn ($l) => $l['cells'][array_search(__('search.columns.currency'), $headings, true)] === 'USD');
        $cell = fn (string $key) => $row['cells'][array_search(__('search.columns.'.$key), $headings, true)];
        $this->assertEqualsWithDelta(1500000, (float) $cell('loan_sp'), 0.01);
        $this->assertEqualsWithDelta(100, (float) $cell('loan_usd'), 0.01);
        $this->assertEqualsWithDelta(100, (float) $cell('return_rate'), 0.01);
        $this->assertEqualsWithDelta(50, (float) $cell('paid'), 0.01);
        $this->assertEqualsWithDelta(50, (float) $cell('remaining'), 0.01);
        $this->assertEqualsWithDelta(4, (float) $cell('total_installments'), 0.01);
        $this->assertSame(2, (int) $cell('paid_installments'));
        $this->assertEqualsWithDelta(2, (float) $cell('remaining_installments'), 0.01);
        $this->assertSame(2, (int) $cell('unpaid_installments'));
        $this->assertContains((int) $cell('months_late'), [1, 2], 'Latest unpaid due date to today + 1 month.');
        $this->assertStringContainsString('/finance', $row['url']);
        // Fund amount in USD range: only the loan with a rate has a USD value.
        $this->assertSame(1, $this->page('loans', ['filters.usd_from' => '50', 'filters.usd_to' => '150'])->viewData('rows')->total());
        $this->assertSame(0, $this->page('loans', ['filters.usd_from' => '150'])->viewData('rows')->total());
    }

    private function numberOf(int $project): string
    {
        $p = DB::table('projects as p')->join('cities as c', 'c.id', '=', 'p.city_id')->where('p.id', $project)->first(['c.code', 'p.project_number']);

        return $p->code.'-'.$p->project_number;
    }

    public function test_guest_media_legal_monitoring_and_a_bare_account(): void
    {
        $details = ['materials', 'project-power', 'home-power', 'relief-aids', 'aid-sources', 'assets', 'illness', 'losses', 'current-works', 'work-experiences', 'courses', 'goals'];
        $readers = ['applications', 'family', 'educations', ...$details];
        foreach (['role.guest', 'role.media'] as $name) {
            $account = $this->as($name);
            $this->assertEqualsCanonicalizing($readers, $this->keys($account), $name);
            $this->assertFalse(app(SearchService::class)->canExport($account, 'applications'), 'No reports.export.');
        }
        $legal = $this->as('role.legal');
        $this->assertEqualsCanonicalizing([...$readers, 'loans', 'attachments'], $this->keys($legal));
        // Legal holds only the legal category: a third of the attachments.
        $legalTypes = DB::table('attachment_types')->where('category', 'like', '%Legal%')->pluck('id');
        $this->assertSame(DB::table('attachments')->whereIn('attachment_type_id', $legalTypes)->count(), $this->page('attachments')->viewData('rows')->total());
        $this->as('role.financial');
        $financialTypes = DB::table('attachment_types')->where('category', 'like', '%Financial%')->pluck('id');
        $this->assertSame(DB::table('attachments')->whereIn('attachment_type_id', $financialTypes)->count(), $this->page('attachments')->viewData('rows')->total());

        $monitoring = $this->as('role.monitoring');
        $this->assertEqualsCanonicalizing(['visit-dates', 'me-visits', 'special-cases', 'meetings', 'materials', 'project-power'], $this->keys($monitoring));
        $this->assertSame(DB::table('special_cases')->count(), $this->page('special-cases')->viewData('rows')->total());
        $this->get('/ar/search')->assertOk();

        // projects.view alone offers nothing: refused and audited, no sidebar entry.
        $bare = $this->custom('search.bare', ['projects.view'], [1]);
        $this->assertSame([], $this->keys($bare));
        $this->get('/ar/search')->assertForbidden();
        $this->assertTrue(DB::table('account_audit_events')->where('actor_account_id', $bare->id)->where('event_type', 'access.denied')->exists());
        $this->assertNull(collect(Navigation::for($bare)['tools'] ?? [])->firstWhere('key', 'search'));
    }

    public function test_a_one_city_account_never_sees_another_citys_rows(): void
    {
        $city = (int) DB::table('cities')->where('code', 'HMS')->value('id');
        $other = (int) DB::table('cities')->where('code', 'DAM')->value('id');
        $account = $this->custom('search.onecity', AccessRoles::capabilities('administrator'), [$city]);
        $this->assertCount(22, $this->keys($account));
        $name = DB::table('cities')->where('id', $city)->value('name_ar');

        foreach (self::ALL as $key) {
            $rows = collect($this->page($key)->viewData('cells'));
            $this->assertGreaterThan(0, $rows->count(), $key);
            $this->assertSame([$name], $rows->pluck('cells.0')->unique()->values()->all(), "$key lists only the account's city");
        }
        $this->assertSame(DB::table('special_cases')->where('city_id', $city)->count(), $this->page('special-cases')->viewData('rows')->total());
        // Asking for the other city in the filter changes nothing; the URL cannot widen the scope.
        $this->assertSame(DB::table('special_cases')->where('city_id', $city)->count(), $this->page('special-cases', ['cities' => [$other]])->viewData('rows')->total());
        $this->assertSame(0, collect($this->page('applications', ['term' => 'DAM-1001'])->viewData('cells'))->where('cells.0', DB::table('cities')->where('id', $other)->value('name_ar'))->count());
    }

    public function test_filters_narrow_the_result_and_two_age_ranges_both_apply(): void
    {
        $this->as('role.administrator');
        $all = $this->page('visit-dates')->viewData('rows')->total();
        $this->assertSame(200, $all);

        $financed = (int) DB::table('project_states')->where('name_en', 'Financed')->value('id');
        $this->assertSame(DB::table('project_state_history')->where('project_state_id', $financed)->where('is_current', true)->count(),
            $this->page('visit-dates', ['filters.status' => [(string) $financed]])->viewData('rows')->total());
        $fund = (int) DB::table('fund_types')->orderBy('id')->value('id');
        $this->assertSame(DB::table('projects')->where('fund_type_id', $fund)->count(), $this->page('visit-dates', ['filters.fund_type' => [(string) $fund]])->viewData('rows')->total());
        $this->assertSame(DB::table('projects')->where('request_date', '>=', '2025-01-01')->where('request_date', '<=', '2025-06-30')->count(),
            $this->page('visit-dates', ['filters.request_from' => '2025-01-01', 'filters.request_to' => '2025-06-30'])->viewData('rows')->total());
        $this->assertSame(DB::table('projects')->where('partnership', '>', 1)->count(), $this->page('visit-dates', ['filters.partnership' => 'partnership'])->viewData('rows')->total());
        $this->assertSame(DB::table('projects')->where('requested_amount', '>=', 5000000)->count(), $this->page('visit-dates', ['filters.amount_from' => '5000000'])->viewData('rows')->total());
        $this->assertSame(DB::table('loans')->where('taken_on', '>=', '2025-01-01')->distinct()->count('project_id'), $this->page('visit-dates', ['filters.fund_from' => '2025-01-01'])->viewData('rows')->total());
        // An empty "to" is open-ended (the desktop made it 0).
        $this->assertSame($this->page('visit-dates', ['filters.request_from' => '2000-01-01'])->viewData('rows')->total(), $all - DB::table('projects')->whereNull('request_date')->count());

        // Age at request with two ranges: both are used (the desktop used the first only).
        $ranges = DB::table('age_ranges')->whereIn('min_age', [26, 36])->orderBy('min_age')->get();
        $this->assertCount(2, $ranges);
        $ageOf = function ($p) {
            return CarbonImmutable::parse($p->birth_date)->diffInYears(CarbonImmutable::parse($p->request_date), true);
        };
        $people = DB::table('projects as p')->join('project_applicants as a', 'a.project_id', '=', 'p.id')->join('people as pe', 'pe.id', '=', 'a.person_id')->where('a.role', 'مستفيد')
            ->whereNotNull('pe.birth_date')->get(['p.id', 'p.request_date', 'pe.birth_date']);
        $count = fn ($set) => $people->filter(function ($p) use ($set, $ageOf) {
            $age = (int) $ageOf($p);

            return collect($set)->contains(fn ($r) => $age >= $r->min_age && $age < $r->max_age);
        })->pluck('id')->unique()->count();
        $one = $this->page('visit-dates', ['filters.age' => [(string) $ranges[0]->id]])->viewData('rows')->total();
        $two = $this->page('visit-dates', ['filters.age' => [(string) $ranges[0]->id, (string) $ranges[1]->id]])->viewData('rows')->total();
        $this->assertSame($count([$ranges[0]]), $one);
        $this->assertSame($count([$ranges[0], $ranges[1]]), $two);
        $this->assertGreaterThan($one, $two);
        // Sex narrows too.
        $this->assertSame(DB::table('project_applicants as a')->join('people as pe', 'pe.id', '=', 'a.person_id')->where('a.role', 'مستفيد')->where('pe.sex', 'female')->distinct()->count('a.project_id'),
            $this->page('visit-dates', ['filters.sex' => ['female']])->viewData('rows')->total());

        // The text: a number with the city code finds one project; a name finds its owner (Arabic letters folded).
        $this->assertSame(1, $this->page('visit-dates', ['term' => 'DAM-1001'])->viewData('rows')->total());
        $this->assertSame(5, $this->page('visit-dates', ['term' => '1001'])->viewData('rows')->total());
        $person = DB::table('people as pe')->join('project_applicants as a', 'a.person_id', '=', 'pe.id')->orderBy('pe.id')->first(['pe.first_name', 'pe.last_name']);
        $this->assertGreaterThan(0, $this->page('applications', ['term' => $person->first_name.' '.$person->last_name])->viewData('rows')->total());
        $this->assertSame(0, $this->page('applications', ['term' => 'لا يوجد اسم كهذا'])->viewData('rows')->total());
        // The family type honours no project filter: the panel is not offered and the filters are ignored there.
        $this->assertSame($this->expected('family'), $this->page('family', ['filters.fund_type' => [(string) $fund]])->viewData('rows')->total());
        // Meetings take the career, category and date filters.
        $meeting = DB::table('profession_meetings')->orderBy('id')->first();
        $this->assertSame(DB::table('profession_meetings')->where('career_id', $meeting->career_id)->count(), $this->page('meetings', ['filters.career' => [(string) $meeting->career_id]])->viewData('rows')->total());
        $this->assertSame(DB::table('profession_meetings')->where('meeting_date', '>=', '2025-06-01')->count(), $this->page('meetings', ['filters.meeting_from' => '2025-06-01'])->viewData('rows')->total());
        // The filter service on its own: lists are cleaned, a bad date dropped.
        $clean = ProjectReportFilters::clean(['fund_type' => ['3', 'x', '0'], 'request_from' => '2025-02-30', 'usd_from' => '-4', 'sex' => ['male', 'robot']]);
        $this->assertSame([3], $clean['fund_type']);
        $this->assertSame(['', '', ['male']], [$clean['request_from'], $clean['usd_from'], $clean['sex']]);
    }

    public function test_every_sortable_column_of_every_type_orders_all_pages_and_keeps_the_rows(): void
    {
        $admin = $this->as('role.administrator');
        $service = app(SearchService::class);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (self::ALL as $key) {
                $mode = SearchModes::make($key);
                $this->assertSame([], array_diff(array_keys($mode->sorts()), ['city', ...$mode->columns()]), "$key sorts only by its own columns");
                $this->assertNotEmpty($mode->sorts(), $key);
                $plain = $service->page($admin, $key, [])['rows']->total();
                foreach (array_keys($mode->sorts()) as $column) {
                    $sorted = $service->page($admin, $key, ['sort' => $column, 'dir' => $locale === 'ar' ? 'desc' : 'asc']);
                    $this->assertSame($plain, $sorted['rows']->total(), "$key by $column");
                    $this->assertNotEmpty($sorted['cells'], "$key by $column");
                }
                // A hidden or computed column is never sorted by.
                foreach (array_intersect_key(['city', ...$mode->columns()], array_flip($mode->sensitive())) as $hidden) {
                    $this->assertArrayNotHasKey($hidden, $mode->sorts(), "$key never orders by a column some readers cannot see");
                }
            }
        }
        app()->setLocale('ar');
    }

    public function test_sorting_runs_over_every_page_and_the_export_follows_it(): void
    {
        $admin = $this->as('role.administrator');
        $column = array_search(__('search.columns.request_date'), SearchModes::make('applications')->headings(), true);

        $page = Livewire::test(SearchPage::class)->set('mode', 'applications')->call('sortBy', 'request_date')->assertSet('sort', 'request_date')->assertSet('dir', 'asc');
        $first = array_map(fn ($l) => $l['cells'][$column], $page->viewData('cells'));
        $second = array_map(fn ($l) => $l['cells'][$column], $page->call('nextPage')->viewData('cells'));
        $all = [...$first, ...$second];
        $ordered = $all;
        sort($ordered);
        $this->assertSame($ordered, $all, 'The second page continues the first.');
        $this->assertSame(substr((string) DB::table('projects')->whereNull('deleted_at')->min('request_date'), 0, 10), $first[0]);
        // Descending starts from the newest and goes back to page one; a third press restores the type's own order.
        $page->call('sortBy', 'request_date')->assertSet('dir', 'desc')->assertSet('paginators.page', 1);
        $this->assertSame(substr((string) DB::table('projects')->whereNull('deleted_at')->max('request_date'), 0, 10), $page->viewData('cells')[0]['cells'][$column]);
        [, $rows] = app(SearchService::class)->export($admin, 'applications', ['sort' => 'request_date', 'dir' => 'desc']);
        $exported = array_map(fn ($l) => $l[$column], iterator_to_array($rows, false));
        $expected = $exported;
        rsort($expected);
        $this->assertSame($expected, $exported);
        $page->call('sortBy', 'request_date')->assertSet('sort', '');
        // Unlisted keys do nothing, from the page or from the address, and never reach the query.
        $page->call('sortBy', 'national_number')->assertSet('sort', '')->call('sortBy', 'p.id; drop table projects')->assertSet('sort', '');
        Livewire::withQueryParams(['mode' => 'applications', 'sort' => 'national_number', 'dir' => 'sideways'])->test(SearchPage::class)->assertOk()->assertSet('dir', 'asc')->assertViewHas('sortedBy', '');
    }

    public function test_the_count_beside_a_value_equals_the_result_of_ticking_it_in_every_type(): void
    {
        $admin = $this->as('role.administrator');
        $service = app(SearchService::class);
        $checked = [];
        foreach (self::ALL as $key) {
            $mode = SearchModes::make($key)->for($admin);
            if ($mode->filterKind() === 'none') {
                $this->assertSame([], $service->facetCounts($admin, $key, 'fund_type', []));

                continue;
            }
            foreach (ReportFilterFields::for($mode->filterKind()) as $field => [$type]) {
                if ($type !== 'list' && $type !== 'choice') {
                    continue;
                }
                $counts = $service->facetCounts($admin, $key, $field, []);
                foreach (array_slice(array_filter($counts), 0, 1, true) as $value => $count) {
                    $filters = [$field => $type === 'list' ? [$value] : $value];
                    $this->assertSame($count, $service->page($admin, $key, $filters)['total'], "$key: $field = $value");
                    $checked[$key] = ($checked[$key] ?? 0) + 1;
                    if ($type === 'list') {
                        // Included and excluded together are every row, each once.
                        $rows = fn (array $f) => $service->page($admin, $key, $f)['rows']->total();
                        $this->assertSame($rows([]), $rows($filters) + $rows($filters + ['not' => [$field]]), "$key: $field complement");
                    }
                }
            }
        }
        $this->assertGreaterThanOrEqual(10, $checked['applications']);
        $this->assertSame(3, $checked['meetings'] ?? 0, 'Career, category and sub category of the meetings.');
    }

    public function test_the_filter_bar_the_type_picker_and_the_year_filters(): void
    {
        $this->as('role.administrator');
        $fund = (string) DB::table('fund_types')->orderBy('id')->value('id');
        $career = (string) DB::table('profession_meetings')->whereNotNull('career_id')->orderBy('id')->value('career_id');
        $page = $this->page('visit-dates')->call('openFilter', 'fund_type');
        $this->assertSame(DB::table('projects')->where('fund_type_id', $fund)->count(), collect($page->viewData('bar')['editor']['options'])->firstWhere('value', $fund)['count']);
        $page->call('toggleValue', 'fund_type', $fund)->call('openFilter', 'career')->call('toggleValue', 'career', $career);
        $page->assertSee(__('search.ui.add'))->assertSeeHtml('data-flt-trigger="fund_type"');
        // The filters the next type also takes stay; the others go.
        $page->call('setMode', 'loans')->assertSet('mode', 'loans')->assertSet('filters', ['fund_type' => [(int) $fund], 'career' => [(int) $career]]);
        $page->call('setMode', 'meetings')->assertSet('filters', ['career' => [(int) $career]])->call('openFilter', 'fund_type')->assertSet('editing', null);
        $page->call('setMode', 'family')->assertSet('filters', [])->assertSee(__('search.ui.no_filters'));

        $year = (int) substr((string) DB::table('projects')->orderBy('id')->value('request_date'), 0, 4);
        $inYear = DB::table('projects')->where('request_date', '>=', "$year-01-01")->where('request_date', '<', ($year + 1).'-01-01')->count();
        $this->assertSame($inYear, $this->page('visit-dates', ['filters.request_year' => [$year]])->viewData('rows')->total());
        $this->assertSame(200 - $inYear, $this->page('visit-dates', ['filters.request_year' => [$year], 'filters.not' => ['request_year']])->viewData('rows')->total());
        // The text narrows the counts too, and it is in neither the printed header nor the address.
        $counted = $this->page('visit-dates', ['term' => 'DAM-1001'])->call('openFilter', 'status');
        $this->assertSame(1, array_sum(array_column($counted->viewData('bar')['editor']['options'], 'count')));
    }

    public function test_a_saved_search_keeps_the_type_filters_and_order_but_never_the_text(): void
    {
        $this->as('role.administrator');
        $fund = (int) DB::table('fund_types')->orderBy('id')->value('id');
        $page = $this->page('loans', ['term' => 'secretterm'])->call('openFilter', 'fund_type')->call('setExclude', 'fund_type', true)->call('toggleValue', 'fund_type', (string) $fund)
            ->call('sortBy', 'loan_date')->call('saveView', 'قروض');
        $view = SavedView::query()->where('dataset', 'search')->firstOrFail();
        $this->assertSame([['field' => 'fund_type', 'op' => 'not_in', 'values' => [$fund]]], $view->spec['filters']);
        $this->assertSame(['mode' => 'loans', 'last' => false, 'sort' => 'loan_date', 'dir' => 'asc'], $view->spec['view']);
        $this->assertStringNotContainsString('secretterm', json_encode($view->getAttributes(), JSON_UNESCAPED_UNICODE));
        $page->call('setMode', 'visit-dates')->call('applyView', $view->id)->assertSet('mode', 'loans')->assertSet('sort', 'loan_date')
            ->assertSet('filters', ['fund_type' => [$fund], 'not' => ['fund_type']]);

        // An account without the type keeps its own type and is told that something was left out.
        $view->update(['is_shared' => true]);
        $this->custom('search.viewer', ['applications.view', 'people.view'], [1]);
        Livewire::test(SearchPage::class)->call('applyView', $view->id)->assertSet('mode', 'applications')->assertSet('viewNotice', __('hc.view_filters_dropped'));
    }

    public function test_m_and_e_visits_evaluation_last_only_and_visit_dates(): void
    {
        $this->as('role.administrator');
        $this->assertSame(10, $this->page('me-visits')->viewData('rows')->total());
        $this->assertSame(5, $this->page('me-visits', ['last' => true])->viewData('rows')->total(), 'The highest visit number of each person.');
        $headings = SearchModes::make('me-visits')->headings();
        $line = collect($this->page('me-visits', ['last' => true])->viewData('cells'))->first();
        $this->assertSame(90, (int) $line['cells'][array_search(__('search.columns.result'), $headings, true)]);
        $this->assertSame('ممتاز', $line['cells'][array_search(__('search.columns.evaluation'), $headings, true)]);
        $this->assertSame('جيد', $line['cells'][array_search(__('search.columns.visitor1_mark'), $headings, true)]);
        $this->assertSame('وسط', $line['cells'][array_search(__('search.columns.visitor2_mark'), $headings, true)]);
        $dates = SearchModes::make('visit-dates')->headings();
        $withVisit = collect($this->page('visit-dates', ['term' => $this->numberOf((int) DB::table('me_visits')->orderBy('id')->value('project_id'))])->viewData('cells'))->first();
        $this->assertSame('2025-04-15', $withVisit['cells'][array_search(__('search.columns.m1'), $dates, true)]);
        $this->assertSame('2025-05-15', $withVisit['cells'][array_search(__('search.columns.m2'), $dates, true)]);
    }

    public function test_sensitive_cells_need_people_view_in_the_rows_city(): void
    {
        $this->custom('search.money', ['finance.view', 'applications.view', 'projects.view'], [1]);
        $headings = SearchModes::make('loans')->headings();
        $mobile = array_search(__('search.columns.mobile'), $headings, true);
        $this->assertSame(['' => true], collect($this->page('loans')->viewData('cells'))->mapWithKeys(fn ($l) => [(string) $l['cells'][$mobile] => true])->all(), 'No people.view: the mobile column is blank.');
        $this->as('role.financial');
        $this->assertNotSame('', (string) collect($this->page('loans')->viewData('cells'))->first()['cells'][$mobile]);
    }

    public function test_the_search_text_never_reaches_an_address_and_the_export_is_token_based_authorised_and_audited(): void
    {
        $admin = $this->as('role.administrator');
        foreach (['mode', 'cities', 'filters', 'last'] as $property) {
            $this->assertNotEmpty((new ReflectionProperty(SearchPage::class, $property))->getAttributes(Url::class), $property.' may be in the URL');
        }
        $this->assertEmpty((new ReflectionProperty(SearchPage::class, 'term'))->getAttributes(Url::class), 'The text is never in a URL.');
        Livewire::withQueryParams(['term' => 'secret-name', 'q' => 'secret-name', 'f' => ['q' => 'secret-name']])->test(SearchPage::class)->assertSet('term', '')->assertSet('filters', []);

        $secret = $this->page('loans', ['term' => 'secretterm'])->call('exportTable', 'csv')->effects['redirect'];
        $this->assertStringNotContainsString('secretterm', $secret);
        $page = $this->page('loans', ['cities' => [(int) DB::table('cities')->where('code', 'DAM')->value('id')]])->call('exportTable', 'csv');
        $redirect = $page->effects['redirect'];
        $this->assertMatchesRegularExpression('#/ar/search/export/[A-Za-z0-9]{40}$#', $redirect);
        $this->assertStringNotContainsString('?', $redirect);
        $before = DB::table('account_audit_events')->where('event_type', 'reports.exported')->count();
        $response = $this->get($redirect)->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $expected = DB::table('loans as l')->join('projects as p', 'p.id', '=', 'l.project_id')->join('cities as c', 'c.id', '=', 'p.city_id')->where('c.code', 'DAM')->whereNull('l.deleted_at')->count();
        $this->assertSame($expected + 1, count(array_filter(preg_split('/\r\n/', trim($csv)))), 'Header plus every matching row (all pages).');
        $this->assertStringContainsString(__('search.columns.loan_usd'), $csv);
        $event = DB::table('account_audit_events')->where('event_type', 'reports.exported')->latest('id')->first();
        $this->assertSame($before + 1, DB::table('account_audit_events')->where('event_type', 'reports.exported')->count());
        $this->assertSame('search:loans/rows:'.$expected, $event->decision_locator);
        $this->assertSame($admin->id, (int) $event->actor_account_id);
        $this->get($redirect)->assertNotFound(); // single use

        // Excel as well.
        $xlsx = $this->page('materials')->call('exportTable', 'xlsx')->effects['redirect'];
        $this->get($xlsx)->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Another account cannot use the token.
        $token = $this->page('materials')->call('exportTable', 'csv')->effects['redirect'];
        $this->as('role.financial');
        $this->get($token)->assertNotFound();
    }

    /** The export reads in chunks of 500: rows of the second chunk onwards must line up with their cells (found by the M4 gate oracle on real data). */
    public function test_an_export_of_more_than_500_rows_lists_every_row(): void
    {
        $admin = $this->as('role.administrator');
        $project = DB::table('projects')->whereNull('deleted_at')->orderBy('id')->first(['id', 'city_id']);
        $now = now();
        DB::table('project_materials')->insert(array_map(fn ($i) => ['city_id' => $project->city_id, 'project_id' => $project->id, 'name' => 'item '.$i, 'quantity' => 1,
            'price' => $i, 'local_contribution' => 0, 'created_at' => $now, 'updated_at' => $now], range(1, 1050)));
        $expected = DB::table('project_materials')->whereNull('deleted_at')->count();
        $this->assertGreaterThan(1000, $expected);

        [$headings, $rows] = app(SearchService::class)->export($admin, 'materials', []);
        $lines = iterator_to_array($rows, false);
        $this->assertCount($expected, $lines);
        $this->assertSame(count($headings), count($lines[$expected - 1]));
        $this->assertContains('item 1050', array_merge(...$lines));
    }

    public function test_export_refusals(): void
    {
        // Guest cannot export at all.
        $this->as('role.guest');
        $page = Livewire::test(SearchPage::class)->assertDontSee(__('search.export_csv', [], 'ar'));
        $page->call('exportTable', 'csv')->assertForbidden();

        // Loans export also needs finance.export: finance.view + reports.export is not enough.
        $account = $this->custom('search.nofx', ['finance.view', 'applications.view', 'projects.view', 'reports.export'], [1]);
        $this->assertTrue(app(SearchService::class)->canExport($account, 'materials'));
        $this->assertFalse(app(SearchService::class)->canExport($account, 'loans'));
        $this->page('loans')->call('exportTable', 'csv')->assertForbidden();
        $this->assertSame(0, DB::table('account_audit_events')->where('actor_account_id', $account->id)->where('event_type', 'reports.exported')->count());
        // With finance.export it is allowed, and only for the cities that hold both.
        app(AccountProvisioner::class)->grant($account, 1, 'finance.export', 'test:search');
        $this->assertTrue(app(SearchService::class)->canExport($account, 'loans'));
        $redirect = $this->page('loans')->call('exportTable', 'csv')->effects['redirect'];
        $this->get($redirect)->assertOk();
        $this->assertSame(1, DB::table('account_audit_events')->where('actor_account_id', $account->id)->where('event_type', 'reports.exported')->count());
        // A token for a type the account lost is refused at the route, before any byte.
        $token = $this->page('materials')->call('exportTable', 'csv')->effects['redirect'];
        DB::table('account_city_capabilities')->where('account_id', $account->id)->where('capability', 'reports.export')->update(['revoked_at' => now()]);
        $this->get($token)->assertForbidden();
        $this->assertTrue(DB::table('account_audit_events')->where('actor_account_id', $account->id)->where('event_type', 'access.denied')->exists());
    }
}
