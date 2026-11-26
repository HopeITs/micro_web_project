<?php

namespace Tests\Unit;

use App\Services\Ask\LocalQueryParser;
use App\Services\Ask\ParseResult;
use App\Support\Datasets\DatasetRegistry;
use App\Support\Datasets\QuerySpec;
use Carbon\CarbonImmutable;
use Database\Seeders\CitiesSeeder;
use Database\Seeders\ReferenceListsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The local ask-box parser (X1): Arabic and English questions become validated filters, never SQL; nonsense stays empty. */
class LocalQueryParserTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-26';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CitiesSeeder::class, ReferenceListsSeeder::class]);
        app()->setLocale('ar');
        DB::table('donors')->insert(['name_ar' => 'منظمة الأمل', 'name_en' => 'Hope Organization', 'sort_order' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function city(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function state(string $en): string
    {
        return (string) DB::table('project_states')->where('name_en', $en)->value('id');
    }

    private function lookup(string $table, string $en): string
    {
        return (string) DB::table($table)->where('name_en', $en)->value('id');
    }

    /** @return list<int> */
    private function all(): array
    {
        return DB::table('cities')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function parse(string $dataset, string $question, ?array $cities = null): ParseResult
    {
        $result = (new LocalQueryParser)->parse($dataset, $question, app()->getLocale(), $cities ?? $this->all(), CarbonImmutable::parse(self::TODAY));
        // Whatever comes out must pass QuerySpec validation for the dataset.
        QuerySpec::fromArray($result->spec())->validate(DatasetRegistry::find($dataset));

        return $result;
    }

    /** @return array<string, array{0: string, 1: list<mixed>}> field => [op, values] */
    private function filters(ParseResult $r): array
    {
        $out = [];
        foreach ($r->filters as $f) {
            $out[$f['field']] = [$f['op'], array_map(fn ($v) => is_numeric($v) && ! is_string($v) ? $v + 0 : $v, $f['values'])];
        }

        return $out;
    }

    public function test_financed_projects_in_a_city_in_arabic(): void
    {
        $r = $this->parse('projects', 'المشاريع الممولة في حلب');
        $this->assertSame(['state' => ['in', [$this->state('Financed')]], 'city' => ['in', [(string) $this->city('ALP')]]], $this->filters($r));
        $this->assertSame([], $r->unknown);
    }

    public function test_financed_projects_in_a_city_in_english(): void
    {
        $r = $this->parse('projects', 'show financed projects in Damascus');
        $this->assertSame(['state' => ['in', [$this->state('Financed')]], 'city' => ['in', [(string) $this->city('DAM')]]], $this->filters($r));
        $this->assertSame([], $r->unknown);
    }

    public function test_several_cities_and_exclusion(): void
    {
        $r = $this->parse('projects', 'مشاريع حلب ودمشق');
        $this->assertEqualsCanonicalizing([(string) $this->city('ALP'), (string) $this->city('DAM')], $this->filters($r)['city'][1]);
        $r = $this->parse('projects', 'كل المشاريع ما عدا حلب');
        $this->assertSame(['city' => ['not_in', [(string) $this->city('ALP')]]], $this->filters($r));
        $r = $this->parse('projects', 'projects except Homs and Latakia');
        $this->assertSame('not_in', $this->filters($r)['city'][0]);
        $this->assertCount(2, $this->filters($r)['city'][1]);
    }

    public function test_states_by_word_and_by_path_stage(): void
    {
        $this->assertSame(['state' => ['in', [$this->state('Rejected')]]], $this->filters($this->parse('projects', 'المشاريع المرفوضة')));
        $r = $this->parse('projects', 'rejected or withdrawn projects');
        $this->assertEqualsCanonicalizing([$this->state('Rejected'), $this->state('Withdrew')], $this->filters($r)['state'][1]);
        $r = $this->parse('projects', 'المشاريع في مرحلة المتابعة');
        $this->assertSame([$this->state('Financed')], $this->filters($r)['state'][1]);
        $this->assertSame([], $r->unknown);
    }

    public function test_project_type_and_donor_by_name_or_one_word(): void
    {
        $r = $this->parse('projects', 'مشاريع زراعية');
        $this->assertSame(['project_type' => ['in', [$this->lookup('project_types', 'Agricultural Projects')]]], $this->filters($r));
        $r = $this->parse('projects', 'projects of Hope Organization');
        $this->assertSame(['donor' => ['in', [$this->lookup('donors', 'Hope Organization')]]], $this->filters($r));
        $r = $this->parse('projects', 'grant projects');
        $this->assertSame(['fund_type' => ['in', [$this->lookup('fund_types', 'Grant')]]], $this->filters($r));
    }

    public function test_without_a_donor_means_empty(): void
    {
        $this->assertSame(['donor' => ['empty', []]], $this->filters($this->parse('projects', 'مشاريع بدون جهة مانحة')));
        $this->assertSame(['donor' => ['empty', []]], $this->filters($this->parse('projects', 'projects without donor')));
        $this->assertSame(['donor' => ['not_empty', []]], $this->filters($this->parse('projects', 'مشاريع مع مانح')));
    }

    public function test_women_over_forty(): void
    {
        $r = $this->parse('people', 'النساء فوق 40 سنة');
        $this->assertSame(['sex' => ['in', ['female']], 'age' => ['gt', [40]]], $this->filters($r));
        $this->assertSame([], $r->unknown);
        $r = $this->parse('people', 'women older than 40');
        $this->assertSame(['sex' => ['in', ['female']], 'age' => ['gt', [40]]], $this->filters($r));
    }

    public function test_age_ranges_in_both_digit_systems(): void
    {
        $this->assertSame(['age' => ['between', [25, 40]]], $this->filters($this->parse('people', 'أعمارهم بين 25 و 40 سنة')));
        $this->assertSame(['age' => ['between', [25, 40]]], $this->filters($this->parse('people', 'بين ٢٥ و ٤٠ سنة')));
        $this->assertSame(['age' => ['between', [25, 40]]], $this->filters($this->parse('people', 'people between 25 and 40')));
        $this->assertSame(['age' => ['lt', [18]]], $this->filters($this->parse('people', 'أقل من ۱۸ سنة')));
        $this->assertSame(['age' => ['between', [60, null]]], $this->filters($this->parse('people', 'at least 60 years old')));
        $this->assertSame(['age' => ['in', [30]]], $this->filters($this->parse('people', 'عمره 30')));
    }

    public function test_marital_status_education_and_projects_of_people(): void
    {
        $this->assertSame(['marital_status' => ['in', [$this->lookup('marital_statuses', 'Married')]]], $this->filters($this->parse('people', 'المتزوجين')));
        $this->assertSame(['education' => ['in', [$this->lookup('education_levels', 'Student')]]], $this->filters($this->parse('people', 'students')));
        $this->assertSame(['has_projects' => ['in', ['without']]], $this->filters($this->parse('people', 'الأشخاص بدون مشاريع')));
        $this->assertSame(['has_projects' => ['in', ['with']]], $this->filters($this->parse('people', 'people with projects')));
        $r = $this->parse('people', 'أكثر من مشروعين');
        $this->assertSame(['projects_count' => ['gt', [2]]], $this->filters($r));
    }

    public function test_amounts_with_comparison_words_and_multipliers(): void
    {
        $this->assertSame(['requested_amount' => ['gt', [5000]]], $this->filters($this->parse('projects', 'مشاريع بمبلغ أكثر من 5000')));
        $this->assertSame(['requested_amount' => ['gt', [5000000]]], $this->filters($this->parse('projects', 'المبلغ المطلوب أكثر من 5 مليون')));
        $this->assertSame(['requested_amount' => ['lt', [1500]]], $this->filters($this->parse('projects', 'amount under 1,500')));
        $this->assertSame(['requested_amount' => ['between', [1000, 3000]]], $this->filters($this->parse('projects', 'amount between 1000 and 3000')));
        $r = $this->parse('projects', 'قرض أكثر من 1000 دولار');
        $this->assertSame(['loan_amount' => ['gt', [1000]]], $this->filters($r));
    }

    public function test_payment_words_and_currency(): void
    {
        $this->assertSame(['status' => ['in', ['overdue']]], $this->filters($this->parse('installments', 'الدفعات المتأخرة')));
        $this->assertSame(['status' => ['in', ['paid']]], $this->filters($this->parse('installments', 'paid installments')));
        $r = $this->parse('installments', 'دفعات غير مدفوعة بالدولار أكثر من 500 دولار');
        $this->assertSame('not_in', $this->filters($r)['status'][0]);
        $this->assertSame(['gt', [500]], $this->filters($r)['amount']);
        $this->assertSame(['in', ['USD']], $this->filters($r)['currency']);
        $r = $this->parse('installments', 'الدفعات المتأخرة أكثر من 30 يوم');
        $this->assertSame(['status' => ['in', ['overdue']], 'days_overdue' => ['gt', [30]]], $this->filters($r));
        $this->assertSame(['overdue_installments' => ['gt', [0]]], $this->filters($this->parse('projects', 'overdue projects')));
    }

    public function test_relative_periods(): void
    {
        $this->assertSame(['request_date' => ['between', ['this_month']]], $this->filters($this->parse('projects', 'مشاريع هذا الشهر')));
        $this->assertSame(['request_date' => ['between', ['2026-10-28', '2026-11-26']]], $this->filters($this->parse('projects', 'مشاريع آخر 30 يوم')));
        $this->assertSame(['request_date' => ['between', ['last_90_days']]], $this->filters($this->parse('projects', 'projects in the last 90 days')));
        $this->assertSame(['request_date' => ['between', ['2025-01-01', '2025-12-31']]], $this->filters($this->parse('projects', 'السنة الماضية')));
        $this->assertSame(['request_date' => ['between', ['this_year']]], $this->filters($this->parse('projects', 'this year')));
        $this->assertSame(['due_on' => ['between', ['next_30_days']]], $this->filters($this->parse('installments', 'الدفعات خلال 30 يوم')));
        $this->assertSame(['due_on' => ['between', ['2026-10-01', '2026-10-31']]], $this->filters($this->parse('installments', 'payments due last month')));
    }

    public function test_years_months_and_date_ranges(): void
    {
        $this->assertSame(['request_date' => ['between', ['2025-01-01', '2025-12-31']]], $this->filters($this->parse('projects', 'مشاريع سنة 2025')));
        $this->assertSame(['request_date' => ['between', ['2025-03-01', '2025-03-31']]], $this->filters($this->parse('projects', 'مشاريع آذار 2025')));
        $this->assertSame(['request_date' => ['between', ['2025-03-01', '2025-03-31']]], $this->filters($this->parse('projects', 'projects in March 2025')));
        $this->assertSame(['request_date' => ['between', ['2025-01-01', '2025-03-31']]], $this->filters($this->parse('projects', 'من 2025-01-01 الى 2025-03-31')));
        $this->assertSame(['request_date' => ['between', ['2024-01-01', '2025-12-31']]], $this->filters($this->parse('projects', 'between 2024 and 2025')));
        $this->assertSame(['request_date' => ['gt', ['2025-06-30']]], $this->filters($this->parse('projects', 'after June 2025')));
        $this->assertSame(['registered' => ['between', ['this_year']]], $this->filters($this->parse('people', 'سجلوا في 2026')));
        $this->assertSame(['birth_date' => ['between', ['1990-01-01', '1990-12-31']]], $this->filters($this->parse('people', 'مواليد 1990')));
    }

    public function test_project_numbers_become_the_search_term(): void
    {
        $r = $this->parse('projects', 'DAM-12');
        $this->assertSame('DAM-12', $r->search);
        $this->assertSame([], $r->filters);
        $this->assertSame('1001, 1002, DAM-7', $this->parse('projects', 'مشروع رقم ١٠٠١ و 1002 و dam7')->search);
        $this->assertSame('1001, 1002, 1003', $this->parse('projects', '1001, 1002, 1003')->search);
        $this->assertSame('DAM-12', $this->parse('installments', 'payments of DAM-12')->search);
    }

    public function test_nonsense_gives_an_empty_spec_and_lists_the_words(): void
    {
        $r = $this->parse('projects', 'blorp zxqv wibble');
        $this->assertSame(['filters' => [], 'sort' => []], $r->spec());
        $this->assertSame(['blorp', 'zxqv', 'wibble'], $r->unknown);
        $this->assertTrue($r->isEmpty());
        $r = $this->parse('people', 'كلمات عشوائية');
        $this->assertSame([], $r->filters);
        $this->assertSame(['كلمات', 'عشوائية'], $r->unknown);
    }

    public function test_a_city_outside_the_accounts_rights_is_ignored_and_reported(): void
    {
        $r = $this->parse('projects', 'المشاريع الممولة في حلب', [$this->city('DAM')]);
        $this->assertSame(['state' => ['in', [$this->state('Financed')]]], $this->filters($r));
        $this->assertSame(['حلب'], $r->unknown);
        $r = $this->parse('projects', 'projects in Damascus and Aleppo', [$this->city('DAM')]);
        $this->assertSame(['city' => ['in', [(string) $this->city('DAM')]]], $this->filters($r));
        $this->assertSame(['Aleppo'], $r->unknown);
    }

    public function test_national_numbers_are_never_carried(): void
    {
        $r = $this->parse('projects', 'مشاريع الرقم الوطني 12345678901');
        $this->assertSame('', $r->search);
        $this->assertContains('12345678901', $r->unknown);
    }

    public function test_report_wording_in_report_mode(): void
    {
        $r = $this->parse('projects', 'عدد المشاريع حسب المدينة');
        $this->assertSame(['rows' => 'city', 'columns' => null, 'measure' => 'count'], $r->report);
        $this->assertSame([], $r->unknown);
        $r = $this->parse('projects', 'projects by state and city');
        $this->assertSame(['rows' => 'state', 'columns' => 'city', 'measure' => 'count'], $r->report);
        $r = $this->parse('installments', 'مجموع المبلغ لكل عملة');
        $this->assertSame(['rows' => 'currency', 'columns' => null, 'measure' => 'sum:amount'], $r->report);
        $r = $this->parse('projects', 'financed projects per city');
        $this->assertSame('city', $r->report['rows']);
        $this->assertSame(['state' => ['in', [$this->state('Financed')]]], $this->filters($r));
    }

    public function test_not_married_and_negated_states(): void
    {
        $this->assertSame(['marital_status' => ['not_in', [$this->lookup('marital_statuses', 'Married')]]], $this->filters($this->parse('people', 'غير متزوج')));
        $this->assertSame(['state' => ['not_in', [$this->state('Rejected')]]], $this->filters($this->parse('projects', 'projects excluding rejected')));
    }
}
