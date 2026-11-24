<?php

namespace Tests\Feature;

use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Papers\ArabicAmountInWords;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** M4.20 wave 2 user scenarios: the promissory note, clearance, disbursement request and partnership contract print from the project's data. */
class FinancePaperScenarioTest extends TestCase
{
    use RefreshDatabase;

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

    /** A known loan of 1,250,000 SYP on the project. */
    private function loan(int $project, int $city): void
    {
        DB::table('loan_installments')->whereIn('loan_id', DB::table('loans')->where('project_id', $project)->pluck('id'))->delete();
        DB::table('loans')->where('project_id', $project)->delete();
        DB::table('loans')->insert(['city_id' => $city, 'project_id' => $project, 'currency' => 'SYP', 'exchange_rate' => 1, 'amount' => 1250000, 'taken_on' => '2026-05-01', 'installments_count' => 10,
            'rate' => 100, 'returned_amount' => 1250000, 'installment_amount' => 125000, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_promissory_note_and_the_clearance_print_the_amount_in_words_the_signatory_and_the_beneficiary(): void
    {
        $id = $this->project('DAM');
        $dam = $this->city('DAM');
        $this->loan($id, $dam);
        $this->signIn('super.admin');
        $this->post('/ar/papers/settings/signatory', ['city' => $dam, 'role' => 'legal_representative', 'full_name' => 'ممثل المركز التجريبي', 'father_name' => 'أبو الممثل', 'national_number' => '99887766554'])->assertRedirect();
        $person = DB::table('project_applicants as a')->join('people as p', 'p.id', '=', 'a.person_id')->where('a.project_id', $id)->orderBy('a.id')->first(['p.first_name', 'p.father_name']);
        $words = ArabicAmountInWords::convert(1250000);

        foreach (['promissory-note' => 'سند أمانة', 'clearance' => 'صك براءة ذمّة'] as $paper => $heading) {
            $page = $this->get('/ar/applications/'.$id.'/finance-papers/'.$paper)->assertOk()->assertSee($heading)->assertSee('ممثل المركز التجريبي')->assertSee('99887766554')
                ->assertSee('1,250,000')->assertSee($words)->assertSee((string) $person->first_name);
            $this->assertSame(1, substr_count($page->getContent(), '<section class="sheet">'));
        }
        $this->assertSame(['paper:F10', 'paper:F23'], DB::table('account_audit_events')->where('event_type', 'papers.printed')->where('subject_id', $id)->orderBy('id')->pluck('decision_locator')->all());
    }

    public function test_a_non_syrian_beneficiary_prints_the_identity_document_instead_of_the_national_number(): void
    {
        $id = $this->project('DAM');
        $this->loan($id, $this->city('DAM'));
        $personId = (int) DB::table('project_applicants')->where('project_id', $id)->orderBy('id')->value('person_id');
        DB::table('people')->where('id', $personId)->update(['nationality' => 'other', 'id_document_type' => 'جواز سفر', 'id_document_number' => 'P7654321']);
        $this->signIn('super.admin');
        $this->get('/ar/applications/'.$id.'/finance-papers/promissory-note')->assertOk()->assertSee('جواز سفر')->assertSee('P7654321');
    }

    public function test_the_disbursement_request_fills_from_the_loan_the_purchases_and_the_stored_request(): void
    {
        $id = $this->project('DAM');
        $dam = $this->city('DAM');
        $this->loan($id, $dam);
        DB::table('purchases')->where('project_id', $id)->delete();
        DB::table('purchases')->insert([['city_id' => $dam, 'project_id' => $id, 'item_name' => 'ماكينة خياطة', 'price' => 400000, 'currency' => 'SYP', 'invoice_number' => 'INV-77', 'created_at' => now(), 'updated_at' => now()],
            ['city_id' => $dam, 'project_id' => $id, 'item_name' => 'طاولة قص', 'price' => 100000, 'currency' => 'SYP', 'invoice_number' => 'INV-78', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('disbursement_requests')->insert(['city_id' => $dam, 'project_id' => $id, 'request_number' => 'REQ-12', 'requested_on' => '2026-06-02', 'hold_amount' => 50000, 'extra_amount' => 75000, 'extra_reason' => 'كلفة النقل', 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn('super.admin');
        $this->get('/ar/applications/'.$id.'/finance-papers/disbursement-request')->assertOk()->assertSee('طلب صرف')->assertSee('REQ-12')->assertSee('2026/06/02')->assertSee('50,000')->assertSee('كلفة النقل')
            ->assertSee('INV-77')->assertSee('ماكينة خياطة')->assertSee('500,000')->assertSee('125,000')->assertSee('100%');
    }

    public function test_the_partnership_contract_prints_two_sheets_with_the_stored_terms(): void
    {
        $id = $this->project('DAM');
        $dam = $this->city('DAM');
        DB::table('partnership_contracts')->insert(['city_id' => $dam, 'project_id' => $id, 'purpose' => 'خياطة وتطريز', 'financed_equipment' => 'ماكينتي خياطة', 'profit_period' => 'كل ثلاثة أشهر',
            'duration_months' => 24, 'starts_on' => '2026-07-01', 'ends_on' => '2028-06-30', 'notice_address' => 'دمشق - القصاع', 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn('super.admin');
        $page = $this->get('/ar/applications/'.$id.'/finance-papers/partnership-contract')->assertOk()->assertSee('عقد شركة محاصة')->assertSee('خياطة وتطريز')->assertSee('ماكينتي خياطة')
            ->assertSee('كل ثلاثة أشهر')->assertSee('2026/07/01')->assertSee('2028/06/30')->assertSee('دمشق - القصاع')->assertSee('شروط خاصة');
        $this->assertSame(2, substr_count($page->getContent(), '<section class="sheet">'));
    }

    public function test_another_city_without_the_finance_right_and_a_guest_are_refused(): void
    {
        $dam = $this->project('DAM');
        $hms = $this->project('HMS');
        $account = app(AccountProvisioner::class)->createPlain('fin.hms', 'fin.hms@hcsyria.org', Hash::make('password'), 'test:papers');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('HMS'), 'data-entry', 'test:papers');
        $this->signIn('fin.hms@hcsyria.org');
        $this->assertContains($this->get('/ar/applications/'.$dam.'/finance-papers/clearance')->status(), [403, 404]);
        $this->assertContains($this->get('/ar/applications/'.$hms.'/finance-papers/clearance')->status(), [200, 403]);
        $this->get('/ar/applications/'.$hms.'/finance-papers/unknown')->assertNotFound();

        \Illuminate\Support\Facades\Auth::guard()->logout();
        $this->flushSession();
        $this->get('/ar/applications/'.$hms.'/finance-papers/clearance')->assertRedirect();
    }
    public function test_the_beneficiary_card_prints_the_instalments_in_the_twelve_slots_and_the_counter_details(): void
    {
        $id = $this->project('DAM');
        $dam = $this->city('DAM');
        $this->loan($id, $dam);
        $loan = (int) DB::table('loans')->where('project_id', $id)->value('id');
        DB::table('loans')->where('id', $loan)->update(['receipt_number' => 'RC-555']);
        foreach (range(1, 14) as $i) {
            DB::table('loan_installments')->insert(['city_id' => $dam, 'loan_id' => $loan, 'amount' => 125000, 'due_on' => sprintf('2026-%02d-15', min($i, 12)).'', 'is_paid' => $i <= 2 ? 1 : 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->signIn('super.admin');
        $html = $this->get('/ar/applications/'.$id.'/finance-papers/beneficiary-card')->assertOk()->assertSee('اسم المستفيد')->assertSee('RC-555')->assertSee('سوريا - دمشق - باب شرقي شارع القصبة')
            ->assertSee('0989 162 112')->assertSee('0993 310 230')->assertSee('يومي السبت والأحد')->assertSee('125,000')->getContent();
        $this->assertSame(24, substr_count($html, 'class="slot"'), '14 instalments need two backs of twelve slots');
        $this->assertSame(2, substr_count($html, 'rb on'), 'the two paid instalments are ticked');
        $this->assertSame('paper:F21', DB::table('account_audit_events')->where('event_type', 'papers.printed')->where('subject_id', $id)->latest('id')->value('decision_locator'));

        // The city's own settings win over the built-in counter text.
        $this->post('/ar/papers/settings', ['city' => $dam, 'address_ar' => 'سوريا - دمشق - عنوان جديد', 'office_hours' => 'من 09:00 حتى 03:00'])->assertRedirect();
        $this->get('/ar/applications/'.$id.'/finance-papers/beneficiary-card')->assertOk()->assertSee('سوريا - دمشق - عنوان جديد')->assertSee('من 09:00 حتى 03:00');
    }

    public function test_the_entry_page_saves_the_disbursement_and_contract_details_and_the_papers_print_them(): void
    {
        $id = $this->project('DAM');
        $dam = $this->city('DAM');
        $this->loan($id, $dam);
        $this->signIn('super.admin');
        $this->get('/ar/applications/'.$id.'/paper-data')->assertOk()->assertSee('بيانات أوراق المشروع')->assertSee('رقم الطلب');

        $this->post('/ar/applications/'.$id.'/paper-data/disbursement', ['request_number' => 'REQ-900', 'requested_on' => '2026-08-09', 'hold_amount' => '75000', 'extra_reason' => 'سبب إضافي'])->assertRedirect('/ar/applications/'.$id.'/paper-data');
        $this->get('/ar/applications/'.$id.'/finance-papers/disbursement-request')->assertOk()->assertSee('REQ-900')->assertSee('2026/08/09')->assertSee('75,000');

        $this->post('/ar/applications/'.$id.'/paper-data/contract', ['purpose' => 'غرض مدخل', 'duration_months' => '18', 'starts_on' => '2026-09-01', 'ends_on' => '2028-02-29', 'print' => '1'])
            ->assertRedirect('/ar/applications/'.$id.'/finance-papers/partnership-contract');
        $this->get('/ar/applications/'.$id.'/finance-papers/partnership-contract')->assertOk()->assertSee('غرض مدخل')->assertSee('2026/09/01');
        $this->assertSame(1, DB::table('partnership_contracts')->where('project_id', $id)->count());

        // A second save updates the same row and the audit keeps the changed fields.
        $this->post('/ar/applications/'.$id.'/paper-data/contract', ['purpose' => 'غرض معدّل', 'duration_months' => '18'])->assertRedirect();
        $this->assertSame(1, DB::table('partnership_contracts')->where('project_id', $id)->count());
        $this->assertSame(3, DB::table('account_audit_events')->where('event_type', 'papers.data_saved')->count());

        // Bad values are refused and nothing changes.
        $this->post('/ar/applications/'.$id.'/paper-data/disbursement', ['hold_amount' => 'abc'])->assertSessionHasErrors('hold_amount');
        $this->post('/ar/applications/'.$id.'/paper-data/contract', ['starts_on' => '2026-09-01', 'ends_on' => '2026-01-01'])->assertSessionHasErrors('ends_on');
        $this->post('/ar/applications/'.$id.'/paper-data/other', [])->assertNotFound();
    }

    public function test_a_reader_without_finance_management_can_read_but_not_save_the_entry_page(): void
    {
        $id = $this->project('HMS');
        $account = app(AccountProvisioner::class)->createPlain('fin.read', 'fin.read@hcsyria.org', Hash::make('password'), 'test:papers');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('HMS'), 'guest', 'test:papers');
        $this->signIn('fin.read@hcsyria.org');
        $this->assertContains($this->get('/ar/applications/'.$id.'/paper-data')->status(), [200, 403]);
        $this->assertContains($this->post('/ar/applications/'.$id.'/paper-data/disbursement', ['request_number' => 'X'])->status(), [403, 404]);
        $this->assertSame(0, DB::table('disbursement_requests')->where('project_id', $id)->count());
    }
}