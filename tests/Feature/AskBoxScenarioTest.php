<?php

namespace Tests\Feature;

use App\Livewire\AskBox;
use App\Livewire\CommandPalette;
use App\Livewire\PaymentSchedule;
use App\Livewire\PeopleIndex;
use App\Livewire\ProjectsIndex;
use App\Models\AiQuery;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Ask\AskExamples;
use App\Services\Ask\AskLinks;
use App\Services\Ask\AskService;
use App\Support\CityContext;
use App\Support\Datasets\ProjectsDataset;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** X1 user scenarios: the ask box turns a plain question into the same filters a person would set by hand, inside the account's rights. */
class AskBoxScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('ar');
    }

    private function city(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function state(string $en): string
    {
        return (string) DB::table('project_states')->where('name_en', $en)->value('id');
    }

    /** Signs in and limits the request to the account's cities, as the middleware does. */
    private function signIn(WebAccount $account): WebAccount
    {
        $this->actingAs($account);
        app(CityContext::class)->restrictTo(DB::table('account_city_capabilities')->where('account_id', $account->id)->where('capability', 'projects.view')->whereNull('revoked_at')->pluck('city_id'));

        return $account;
    }

    private function role(string $username): WebAccount
    {
        return $this->signIn(WebAccount::where('username', $username)->firstOrFail());
    }

    private function damascusClerk(): WebAccount
    {
        $account = app(AccountProvisioner::class)->createPlain('dam.clerk', 'dam.clerk@hcsyria.org', Hash::make('a-long-test-password'), 'test:ask');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now()]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('DAM'), 'data-entry', 'test:ask');

        return $this->signIn($account->fresh());
    }

    /** Asks in the box and returns the `ask-applied` payload it dispatched. @return array{dataset: string, spec: array} */
    private function ask(string $dataset, string $question, string $mode = ''): array
    {
        $payload = null;
        Livewire::test(AskBox::class, ['dataset' => $dataset, 'mode' => $mode])->set('question', $question)->call('ask')
            ->assertDispatched('ask-applied', function (string $event, array $params) use (&$payload) {
                $payload = $params;

                return true;
            });

        return $payload;
    }

    /** @return list<int> ids on the list's first page */
    private function ids(Testable $list, string $key): array
    {
        return array_map(fn ($row) => (int) $row->id, $list->viewData($key)->items());
    }

    public function test_an_administrators_question_lists_the_same_projects_as_the_manual_filters(): void
    {
        $this->role('role.administrator');
        $payload = $this->ask('projects', 'المشاريع الممولة في حلب');
        $this->assertSame('projects', $payload['dataset']);

        $asked = Livewire::test(ProjectsIndex::class)->dispatch('ask-applied', ...$payload);
        $manual = Livewire::test(ProjectsIndex::class)->call('toggleFilterValue', 'state', $this->state('Financed'))->call('toggleCity', $this->city('ALP'));

        $asked->assertSet('cities', [$this->city('ALP')])->assertSet('filters', ['state' => ['op' => 'in', 'values' => [$this->state('Financed')]]]);
        $this->assertGreaterThan(0, $manual->viewData('projects')->total());
        $this->assertSame($manual->viewData('projects')->total(), $asked->viewData('projects')->total());
        $this->assertSame($this->ids($manual, 'projects'), $this->ids($asked, 'projects'));
        $this->assertSame(
            DB::table('projects as p')->join('project_state_history as h', fn ($j) => $j->on('h.project_id', '=', 'p.id')->where('h.is_current', true)->whereNull('h.deleted_at'))
                ->whereNull('p.deleted_at')->where('p.city_id', $this->city('ALP'))->where('h.project_state_id', $this->state('Financed'))->count(),
            $asked->viewData('projects')->total(),
        );
    }

    public function test_the_chips_show_what_was_understood_and_the_words_that_were_not(): void
    {
        $this->role('role.administrator');
        Livewire::test(AskBox::class, ['dataset' => 'projects'])->set('question', 'المشاريع الممولة في حلب zzzxq')->call('ask')
            ->assertSee(__('hc.state'))->assertSee(__('hc.city'))->assertSee(__('ask.not_understood'))->assertSee('zzzxq')
            ->call('removeChip', 'city')->assertDispatched('ask-applied')->assertDontSee(__('hc.city').':');
    }

    public function test_a_one_city_data_entry_account_gets_its_city_only(): void
    {
        $this->damascusClerk();
        $payload = $this->ask('projects', 'المشاريع الممولة في دمشق');
        $asked = Livewire::test(ProjectsIndex::class)->dispatch('ask-applied', ...$payload);
        $manual = Livewire::test(ProjectsIndex::class)->call('toggleFilterValue', 'state', $this->state('Financed'));
        $this->assertSame($this->ids($manual, 'projects'), $this->ids($asked, 'projects'));
        $this->assertGreaterThan(0, $asked->viewData('projects')->total());

        // Asking for Aleppo, which this account may not read, is ignored: no city filter, the word is reported, and no Aleppo row can show.
        $box = Livewire::test(AskBox::class, ['dataset' => 'projects'])->set('question', 'المشاريع الممولة في حلب')->call('ask');
        $box->assertSee(__('ask.not_understood'))->assertSee('حلب');
        $this->assertSame([['field' => 'state', 'op' => 'in', 'values' => [$this->state('Financed')]]], $box->get('spec')['filters']);
        $this->assertSame($this->ids($manual, 'projects'), $this->ids(Livewire::test(ProjectsIndex::class)->dispatch('ask-applied', dataset: 'projects', spec: $box->get('spec')), 'projects'));

        // A forged event that names Aleppo cannot widen the list either: the list checks the spec again.
        $forged = ['filters' => [['field' => 'city', 'op' => 'in', 'values' => [(string) $this->city('ALP')]]], 'sort' => []];
        $list = Livewire::test(ProjectsIndex::class)->dispatch('ask-applied', dataset: 'projects', spec: $forged)->assertSet('cities', []);
        $this->assertSame(DB::table('projects')->whereNull('deleted_at')->where('city_id', $this->city('DAM'))->count(), $list->viewData('projects')->total());
    }

    public function test_the_payments_list_follows_the_question_too(): void
    {
        $this->role('role.financial');
        $payload = $this->ask('installments', 'الدفعات المتأخرة');
        $asked = Livewire::test(PaymentSchedule::class)->dispatch('ask-applied', ...$payload);
        $manual = Livewire::test(PaymentSchedule::class)->call('toggleFilterValue', 'status', 'overdue');
        $this->assertSame($manual->viewData('rows')->total(), $asked->viewData('rows')->total());
        $asked->assertSet('filters', ['status' => ['op' => 'in', 'values' => ['overdue']]]);
    }

    public function test_people_questions_need_the_right_to_view_people(): void
    {
        // Monitoring reads projects but not people: the box offers nothing, parses nothing, stores nothing and dispatches nothing.
        $this->role('role.monitoring');
        $box = Livewire::test(AskBox::class, ['dataset' => 'people'])->set('question', 'النساء فوق 40 سنة')->call('ask');
        $box->assertNotDispatched('ask-applied');
        $this->assertTrue($box->viewData('allowed') === false);
        $this->assertSame(0, AiQuery::count());
        $this->assertTrue(Livewire::test(AskBox::class, ['dataset' => 'projects'])->viewData('allowed'));

        $this->assertFalse(app(AskService::class)->allowed(auth()->user(), 'people'));
        $this->assertNull(app(AskService::class)->parse(auth()->user(), 'people', 'النساء'));

        // An administrator gets the same question as the manual filters.
        $this->role('role.administrator');
        $payload = $this->ask('people', 'النساء فوق 40 سنة');
        $asked = Livewire::test(PeopleIndex::class)->dispatch('ask-applied', ...$payload);
        $manual = Livewire::test(PeopleIndex::class)->call('toggleFilterValue', 'sex', 'female')->call('setFilterRange', 'age', '', '');
        $expected = DB::table('people')->whereNull('deleted_at')->where('sex', 'female')->whereRaw('(julianday(?) - julianday(birth_date)) / 365.25 >= 41', [now()->toDateString()])->count();
        $this->assertSame(['sex', 'age'], array_keys($asked->get('filters')));
        $this->assertLessThanOrEqual($manual->viewData('people')->total(), $asked->viewData('people')->total());
        $this->assertSame($expected, $asked->viewData('people')->total());
    }

    public function test_the_stored_question_holds_no_national_number_and_no_name(): void
    {
        $account = $this->role('role.administrator');
        $national = (string) DB::table('people')->whereNotNull('national_number')->value('national_number');
        $this->assertGreaterThanOrEqual(10, strlen($national));
        $arabic = strtr($national, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);

        Livewire::test(AskBox::class, ['dataset' => 'projects'])->set('question', "المشاريع الممولة في حلب للمستفيد أحمد الخطيب الرقم الوطني {$national} أو {$arabic}")->call('ask');

        $row = AiQuery::query()->latest('id')->firstOrFail();
        $this->assertSame($account->id, $row->account_id);
        $this->assertSame('local', $row->model);
        $this->assertSame('projects', $row->dataset);
        $this->assertSame('ok', $row->status);
        $this->assertDoesNotMatchRegularExpression('/\d{10}/', $row->prompt);
        $this->assertStringNotContainsString($national, $row->prompt);
        $this->assertStringNotContainsString(substr($national, 0, 6), $row->prompt);
        $this->assertStringNotContainsString($arabic, $row->prompt);
        $this->assertStringNotContainsString('أحمد', $row->prompt);
        $this->assertStringNotContainsString('الخطيب', $row->prompt);
        $this->assertStringContainsString('الممولة', $row->prompt);
        $this->assertStringNotContainsString($national, json_encode($row->spec));
        $this->assertSame(0, $row->input_tokens + $row->output_tokens);
    }

    public function test_project_numbers_reach_the_search_box_and_never_an_address(): void
    {
        $this->role('role.administrator');
        $payload = $this->ask('projects', 'DAM-1001 و HMS-1002');
        $list = Livewire::test(ProjectsIndex::class)->dispatch('ask-applied', ...$payload);
        $this->assertSame('DAM-1001, HMS-1002', $list->get('search'));
        $this->assertSame(2, $list->viewData('projects')->total());
        $this->assertNull(AskLinks::listUrl(new ProjectsDataset, []));
    }

    public function test_report_mode_adds_the_pivot_and_the_list_mode_does_not(): void
    {
        $this->role('role.administrator');
        $report = $this->ask('projects', 'عدد المشاريع الممولة حسب المدينة', 'report');
        $this->assertSame(['rows' => 'city', 'columns' => null, 'measure' => 'count'], $report['report']);
        $this->assertSame('state', $report['spec']['filters'][0]['field']);
        $this->assertArrayNotHasKey('report', $this->ask('projects', 'عدد المشاريع الممولة حسب المدينة'));
    }

    public function test_suggestions_follow_what_the_account_may_see(): void
    {
        $this->damascusClerk();
        $box = Livewire::test(AskBox::class, ['dataset' => 'projects']);
        $box->assertSee('المشاريع الممولة في دمشق')->assertDontSee('ما عدا')->assertDontSee('مشاريع متأخرة'); // one city, no finance right
        $this->assertGreaterThanOrEqual(4, substr_count($box->html(), 'wire:click="useExample('));
        $box->call('useExample', 0)->assertDispatched('ask-applied');

        $this->role('role.administrator');
        Livewire::test(AskBox::class, ['dataset' => 'projects'])->assertSee('ما عدا')->assertSee('مشاريع متأخرة');
    }

    public function test_ctrl_k_offers_the_filtered_list_with_ids_only_in_the_address(): void
    {
        $this->role('role.administrator');
        $term = 'المشاريع الممولة في حلب';
        $palette = Livewire::test(CommandPalette::class)->set('term', $term)->assertSee(__('ask.palette_show'));
        $palette->call('openAsk');
        $url = $palette->effects['redirect'];
        $this->assertStringContainsString('projects', $url);
        $this->assertStringContainsString(rawurlencode('f[state][values][0]') === '' ? 'f' : 'f%5Bstate%5D', $url);
        $this->assertStringNotContainsString(rawurlencode('الممولة'), $url);
        $this->assertStringNotContainsString('الممولة', urldecode($url));
        $this->assertSame(1, AiQuery::count());

        // Plain searches and nonsense are not offered a list.
        Livewire::test(CommandPalette::class)->set('term', 'DAM-1001')->assertDontSee(__('ask.palette_show'));
        Livewire::test(CommandPalette::class)->set('term', 'blorp zxqv wibble')->assertDontSee(__('ask.palette_show'));
    }

    public function test_every_suggested_example_is_understood_completely_in_both_languages(): void
    {
        $this->role('role.administrator');
        $service = app(AskService::class);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (['projects', 'people', 'installments'] as $dataset) {
                $examples = AskExamples::for(auth()->user(), $dataset, $service->cities(auth()->user(), $dataset)->all());
                $this->assertGreaterThanOrEqual(4, count($examples), "$dataset examples ($locale)");
                foreach ($examples as $example) {
                    $result = $service->parse(auth()->user(), $dataset, $example);
                    $this->assertNotSame([], $result->filters, "[$locale/$dataset] $example");
                    $this->assertSame([], $result->unknown, "[$locale/$dataset] $example");
                }
            }
        }
    }

    public function test_the_ask_texts_exist_in_both_languages(): void
    {
        $ar = require base_path('lang/ar/ask.php');
        $en = require base_path('lang/en/ask.php');
        $this->assertSame(array_keys($ar), array_keys($en));
        foreach ([$ar, $en] as $lang) {
            $this->assertNotContains('', $lang);
        }
        // English works end to end as well.
        app()->setLocale('en');
        $this->role('role.administrator');
        $payload = $this->ask('projects', 'financed projects in Aleppo');
        $this->assertSame('state', $payload['spec']['filters'][0]['field']);
        Livewire::test(AskBox::class, ['dataset' => 'projects'])->assertSee(__('ask.title'))->assertSee('financed projects in Aleppo');
    }
}
