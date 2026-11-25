<?php

namespace Tests\Feature;

use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Papers\ArabicAmountInWords;
use App\Services\Papers\DeedPaper;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** M4.20 wave 4 user scenarios: the five pledge deeds and the two sale contracts, with their entry pages. */
class LegalPaperScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const DEEDS = ['tools' => 9, 'tools_agri' => 10, 'solar' => 11, 'solar_agri' => 12, 'vehicles' => 11];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function city(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function project(string $city): int
    {
        return (int) DB::table('projects')->where('city_id', $this->city($city))->where('application_stage', 'full')->whereNull('deleted_at')->orderBy('id')->value('id');
    }

    private function signIn(string $login): void
    {
        $this->post('/ar/login', ['login' => (array_key_exists($login, RoleAccountsSeeder::accounts()) ? RoleAccountsSeeder::email($login) : null) ?? $login, 'password' => 'password'])->assertRedirect();
    }

    private function account(string $name, string $role, string $city): void
    {
        \Illuminate\Support\Facades\Auth::guard()->logout();
        $this->flushSession();
        $account = app(AccountProvisioner::class)->createPlain($name, $name.'@hcsyria.org', Hash::make('password'), 'test:papers');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city($city), $role, 'test:papers');
        $this->signIn($name.'@hcsyria.org');
    }

    /** A loan taken on 1 May 2026 with the first instalment three months later, and two purchases from one trader. */
    private function money(int $project, int $city): void
    {
        $stamp = ['created_at' => now(), 'updated_at' => now()];
        DB::table('loan_installments')->whereIn('loan_id', DB::table('loans')->where('project_id', $project)->pluck('id'))->delete();
        DB::table('loans')->where('project_id', $project)->delete();
        $loan = DB::table('loans')->insertGetId(['city_id' => $city, 'project_id' => $project, 'currency' => 'SYP', 'exchange_rate' => 1, 'amount' => 1250000, 'taken_on' => '2026-05-01', 'installments_count' => 10,
            'rate' => 80, 'returned_amount' => 1000000, 'installment_amount' => 125000] + $stamp);
        DB::table('loan_installments')->insert(['city_id' => $city, 'loan_id' => $loan, 'amount' => 125000, 'due_on' => '2026-08-01', 'is_paid' => 0] + $stamp);
        DB::table('purchases')->where('project_id', $project)->delete();
        DB::table('purchases')->insert([['city_id' => $city, 'project_id' => $project, 'item_name' => 'براد عرض', 'price' => 600000, 'quantity' => 1, 'currency' => 'SYP', 'trader_name' => 'محل البائع التجريبي'] + $stamp,
            ['city_id' => $city, 'project_id' => $project, 'item_name' => 'ميزان رقمي', 'price' => 150000, 'quantity' => 2, 'currency' => 'SYP', 'trader_name' => 'محل البائع التجريبي'] + $stamp]);
    }

    private function representative(int $city): void
    {
        $this->post('/ar/papers/settings/signatory', ['city' => $city, 'role' => 'legal_representative', 'full_name' => 'ممثل المركز التجريبي', 'father_name' => 'أبو الممثل', 'national_number' => '99887766554'])->assertRedirect();
    }

    public function test_each_deed_prints_every_clause_filled_from_the_loan_and_the_signatory(): void
    {
        $id = $this->project('DAM');
        $dam = $this->city('DAM');
        $this->money($id, $dam);
        $this->signIn('super.admin');
        $this->representative($dam);
        $person = DB::table('project_applicants as a')->join('people as p', 'p.id', '=', 'a.person_id')->where('a.project_id', $id)->orderBy('a.id')->first(['p.first_name', 'p.national_number']);

        foreach (self::DEEDS as $kind => $clauses) {
            $page = $this->get('/ar/applications/'.$id.'/deeds/'.$kind.'/print')->assertOk()->assertSee('صك إقرار وتعهد')->assertSee('أقرْ وأتعهد بما يلي')->assertSee('ممثل المركز التجريبي')
                ->assertSee('المقر بما فيه')->assertSee('مركز الرجاء المسيحي')->assertSee('125,000')->assertSee(ArabicAmountInWords::convert(125000))->assertSee('80%')
                ->assertSee((string) $person->first_name)->assertSee((string) $person->national_number);
            $html = $page->getContent();
            $this->assertSame(1, substr_count($html, '<section class="sheet">'), $kind);
            $this->assertStringContainsString('<b>'.$clauses.'-</b>', $html, $kind);
            $this->assertStringNotContainsString('<b>'.($clauses + 1).'-</b>', $html, $kind);
            $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/u', preg_replace('~<style>.*?</style>~s', '', $html), $kind);
        }
        // The grace period is the whole months from the loan to the first instalment.
        $this->get('/ar/applications/'.$id.'/deeds/tools/print')->assertSee('3 أشهر');
        $this->assertSame(6, DB::table('account_audit_events')->where('event_type', 'papers.printed')->where('subject_id', $id)->count());
    }

    public function test_the_deed_entry_page_saves_terms_the_solar_system_and_the_vehicle(): void
    {
        $id = $this->project('DAM');
        $dam = $this->city('DAM');
        $this->money($id, $dam);
        $this->signIn('super.admin');
        $url = '/ar/applications/'.$id.'/deeds';
        $this->get($url)->assertOk()->assertSee('صكوك الإقرار والتعهد')->assertSee('صك المركبات')->assertSee('إن تُرك فارغاً: 3');

        $this->post($url.'/tools', ['grace_months' => '2', 'recovery_rate' => '75'])->assertRedirect($url);
        $this->get($url.'/tools/print')->assertSee('شهرين')->assertSee('75%')->assertDontSee('80%');

        $this->post($url.'/solar_agri', ['season_start_on' => '2026-10-01', 'medicines_amount' => '200000', 'delivery_month_year' => '2027-05', 'recovery_rate' => '', 'description' => 'منظومة ست لواقط', 'print' => '1'])
            ->assertRedirect($url.'/solar_agri/print');
        $this->get($url.'/solar_agri/print')->assertSee('2026/10/01')->assertSee('200,000')->assertSee('أيار')->assertSee('2027')->assertSee('منظومة ست لواقط')->assertSee('80%');
        // The solar description is shared by the two solar deeds.
        $this->get($url.'/solar/print')->assertSee('منظومة ست لواقط');

        $this->post($url.'/vehicles', ['make' => 'سوزوكي', 'model' => 'كاري', 'model_year' => '2012', 'plate_number' => '123456'])->assertRedirect($url);
        $this->get($url.'/vehicles/print')->assertSee('سوزوكي كاري 2012 رقم 123456');
        // The vehicle deed was saved with the vehicle only, so it has no terms row.
        $this->assertSame(2, DB::table('project_deed_terms')->where('project_id', $id)->count());
        $this->assertSame(1, DB::table('project_solar_systems')->where('project_id', $id)->count());
        $this->assertSame(1, DB::table('project_vehicles')->where('project_id', $id)->count());
        $this->assertSame(3, DB::table('account_audit_events')->where('event_type', 'papers.data_saved')->count());

        foreach ([['tools', 'recovery_rate', '140'], ['tools', 'grace_months', 'abc'], ['tools_agri', 'delivery_month_year', '2027-13'], ['vehicles', 'model_year', '12']] as [$kind, $field, $value]) {
            $this->post($url.'/'.$kind, [$field => $value])->assertSessionHasErrors($field);
        }
        $this->assertSame('75.00', number_format((float) DB::table('project_deed_terms')->where('project_id', $id)->where('deed_kind', 'tools')->value('recovery_rate'), 2));
        $this->post($url.'/other', [])->assertNotFound();
    }

    public function test_a_non_syrian_prints_the_document_and_an_empty_project_prints_blank_lines(): void
    {
        $id = $this->project('DAM');
        DB::table('loan_installments')->whereIn('loan_id', DB::table('loans')->where('project_id', $id)->pluck('id'))->delete();
        DB::table('loans')->where('project_id', $id)->delete();
        $person = (int) DB::table('project_applicants')->where('project_id', $id)->orderBy('id')->value('person_id');
        DB::table('people')->where('id', $person)->update(['nationality' => 'other', 'id_document_type' => 'جواز سفر', 'id_document_number' => 'P7654321']);
        $this->signIn('super.admin');
        $this->get('/ar/applications/'.$id.'/deeds/tools/print')->assertOk()->assertSee('جواز سفر')->assertSee('P7654321')->assertDontSee('رقم وطني:')->assertSee('class="fill"', false);
        $this->assertSame('شهر واحد', DeedPaper::months(1));
        $this->assertSame('11 شهراً', DeedPaper::months(11));
    }

    public function test_the_sale_contracts_suggest_save_and_print_for_either_buyer(): void
    {
        $id = $this->project('DAM');
        $dam = $this->city('DAM');
        $this->money($id, $dam);
        $this->signIn('super.admin');
        $this->representative($dam);
        $url = '/ar/applications/'.$id.'/sale-contracts';
        $first = (string) DB::table('project_applicants as a')->join('people as p', 'p.id', '=', 'a.person_id')->where('a.project_id', $id)->orderBy('a.id')->value('p.first_name');
        $this->get($url)->assertOk()->assertSee('عقود البيع')->assertSee('محل البائع التجريبي')->assertSee('براد عرض');

        // Before saving: the device contract is suggested from the purchases.
        $this->get($url.'/device/print')->assertOk()->assertSee('عقد بيع وشراء')->assertSee('براد عرض')->assertSee('محل البائع التجريبي')->assertSee('750,000')->assertSee('ممثل المركز التجريبي')
            ->assertSee('محاكم')->assertSee('مؤلف من ستة بنود');
        $this->get($url.'/device/print?buyer=beneficiary')->assertOk()->assertSee($first)->assertDontSee('ممثل المركز التجريبي');

        DB::table('project_vehicles')->insert(['city_id' => $dam, 'project_id' => $id, 'make' => 'كيا', 'model' => 'ريو', 'plate_number' => '555', 'chassis_number' => 'CH-9', 'created_at' => now(), 'updated_at' => now()]);
        $this->post($url.'/car', ['seller_name' => 'البائع الحر', 'subject_text' => 'سيارة كيا ريو بيضاء', 'price' => '9000', 'currency' => 'USD', 'signed_on' => '2026-11-10', 'handover_on' => '2026-11-12', 'handover_time' => '14:30', 'witness_1' => 'شاهد أول', 'print' => '1'])
            ->assertRedirect($url.'/car/print');
        $page = $this->get($url.'/car/print')->assertOk()->assertSee('عقد بيع سيارة قطعي')->assertSee('البائع الحر')->assertSee('سيارة كيا ريو بيضاء')->assertSee('كيا ريو رقم 555')->assertSee('9,000')
            ->assertSee('2026/11/12')->assertSee('14:30')->assertSee('2026/11/10')->assertSee('شاهد أول')->assertSee('تسعة بنود');
        // The car contract is the unbranded sheet: no letterhead picture and no address footer.
        $this->assertStringNotContainsString('checklist-header', $page->getContent());
        $this->assertSame(1, DB::table('sale_contracts')->where('project_id', $id)->count());

        $this->post($url.'/car', ['currency' => 'EUR'])->assertSessionHasErrors('currency');
        $this->post($url.'/car', ['handover_time' => '25:00'])->assertSessionHasErrors('handover_time');
        $this->post($url.'/other', [])->assertNotFound();
    }

    public function test_rights_and_cities_are_enforced(): void
    {
        $dam = $this->project('DAM');
        $hms = $this->project('HMS');

        // Legal staff read both pages and may save both (executive files and purchasing management).
        $this->account('law.hms', 'legal', 'HMS');
        $this->get('/ar/applications/'.$hms.'/deeds')->assertOk();
        $this->post('/ar/applications/'.$hms.'/deeds/tools', ['grace_months' => '4'])->assertRedirect();
        $this->post('/ar/applications/'.$hms.'/sale-contracts/device', ['seller_name' => 'بائع'])->assertRedirect();
        $this->assertContains($this->get('/ar/applications/'.$dam.'/deeds/tools/print')->status(), [403, 404]);
        $this->assertContains($this->get('/ar/applications/'.$dam.'/sale-contracts')->status(), [403, 404]);

        // Data entry handles purchasing but has no finance view: contracts yes, deeds no.
        $this->account('entry.hms', 'data-entry', 'HMS');
        $this->get('/ar/applications/'.$hms.'/sale-contracts')->assertOk();
        $this->get('/ar/applications/'.$hms.'/deeds')->assertForbidden();
        $this->get('/ar/applications/'.$hms.'/deeds/tools/print')->assertForbidden();

        // Financial staff handle the deeds but not the purchasing contracts.
        $this->account('money.hms', 'financial', 'HMS');
        $this->post('/ar/applications/'.$hms.'/deeds/tools', ['grace_months' => '5'])->assertRedirect();
        $this->get('/ar/applications/'.$hms.'/sale-contracts')->assertForbidden();

        $this->account('look.hms', 'guest', 'HMS');
        $this->get('/ar/applications/'.$hms.'/deeds')->assertForbidden();
        $this->post('/ar/applications/'.$hms.'/sale-contracts/device', ['seller_name' => 'x'])->assertForbidden();

        \Illuminate\Support\Facades\Auth::guard()->logout();
        $this->flushSession();
        $this->get('/ar/applications/'.$hms.'/deeds')->assertRedirect();
        $this->assertSame(1, DB::table('project_deed_terms')->count());
    }
}
