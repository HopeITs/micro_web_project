<?php

namespace Tests\Feature;

use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** M4.20 wave 3 user scenarios: the purchase request, price quote, equipment receipt pledge and equipment return, and their entry page. */
class PurchasePaperScenarioTest extends TestCase
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

    private function account(string $name, string $role, string $city): void
    {
        $account = app(AccountProvisioner::class)->createPlain($name, $name.'@hcsyria.org', Hash::make('password'), 'test:papers');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city($city), $role, 'test:papers');
        $this->signIn($name.'@hcsyria.org');
    }

    /** Two materials and two purchases of known names on the project. */
    private function stock(int $project, int $city): void
    {
        DB::table('project_materials')->where('project_id', $project)->delete();
        DB::table('purchases')->where('project_id', $project)->delete();
        $stamp = ['created_at' => now(), 'updated_at' => now()];
        DB::table('project_materials')->insert([['city_id' => $city, 'project_id' => $project, 'name' => 'قماش قطني', 'quantity' => 12, 'price' => 1000] + $stamp,
            ['city_id' => $city, 'project_id' => $project, 'name' => 'خيوط تطريز', 'quantity' => 30, 'price' => 50] + $stamp]);
        DB::table('purchases')->insert([['city_id' => $city, 'project_id' => $project, 'item_name' => 'ماكينة درزة', 'brand' => 'JUKI-77', 'quantity' => 2, 'price' => 400000, 'currency' => 'SYP', 'trader_name' => 'محل التجربة'] + $stamp,
            ['city_id' => $city, 'project_id' => $project, 'item_name' => 'مكواة بخار', 'brand' => 'ST-5', 'quantity' => 1, 'price' => 100000, 'currency' => 'SYP', 'trader_name' => 'محل آخر'] + $stamp]);
    }

    public function test_the_four_papers_print_filled_from_the_project_before_anything_is_entered(): void
    {
        $id = $this->project('DAM');
        $dam = $this->city('DAM');
        $this->stock($id, $dam);
        $this->signIn('super.admin');
        $this->post('/ar/papers/settings/signatory', ['city' => $dam, 'role' => 'purchasing_officer', 'full_name' => 'مسؤول الشراء التجريبي'])->assertRedirect();
        $first = (string) DB::table('project_applicants as a')->join('people as p', 'p.id', '=', 'a.person_id')->where('a.project_id', $id)->orderBy('a.id')->value('p.first_name');

        $expect = [
            'purchase-request' => ['طلب مشتريات', 'قماش قطني', 'خيوط تطريز', 'مسؤول الشراء التجريبي', 'أن يكون مكان العمل جاهز', $first],
            'price-quote' => ['قائمة عرض أسعار', 'قماش قطني', 'يمنع إضافة أو إزالة', $first],
            'equipment-receipt' => ['تعهد استلام المعدات المطلوبة', 'ماكينة درزة', 'محل التجربة', 'مكواة بخار', 'فحص وتجريب جميع المعدات', $first],
            'equipment-return' => ['مرتجع معدات وبضائع', 'الرقم التسلسلي', $first],
        ];
        foreach ($expect as $paper => $texts) {
            $page = $this->get('/ar/applications/'.$id.'/purchase-papers/'.$paper)->assertOk();
            foreach ($texts as $text) {
                $page->assertSee($text);
            }
            $this->assertSame(1, substr_count($page->getContent(), '<section class="sheet">'), $paper);
        }
        // Nothing was returned yet, so the return paper does not list the purchases.
        $this->get('/ar/applications/'.$id.'/purchase-papers/equipment-return')->assertDontSee('ماكينة درزة');
        $this->assertSame(['paper:F09', 'paper:F08', 'paper:F20', 'paper:F22', 'paper:F22'], DB::table('account_audit_events')->where('event_type', 'papers.printed')->where('subject_id', $id)->orderBy('id')->pluck('decision_locator')->all());
    }

    public function test_the_entry_page_saves_each_paper_and_the_papers_print_what_was_saved(): void
    {
        $id = $this->project('DAM');
        $this->stock($id, $this->city('DAM'));
        $this->signIn('super.admin');
        $url = '/ar/applications/'.$id.'/purchase-data';
        $this->get($url)->assertOk()->assertSee('بيانات أوراق المشتريات')->assertSee('قماش قطني')->assertSee('ماكينة درزة')->assertSee('rows[0][include]', false);

        $this->post($url.'/request', ['rows' => [['material' => 'مقص صناعي', 'item_kind' => 'يدوي', 'quantity' => '٣', 'material_state' => 'used'], ['material' => '', 'quantity' => '']]])->assertRedirect($url);
        $this->get('/ar/applications/'.$id.'/purchase-papers/purchase-request')->assertOk()->assertSee('مقص صناعي')->assertSee('يدوي')->assertSee('مستعملة')->assertDontSee('قماش قطني');

        $this->post($url.'/quote', ['supplier_name' => 'المورد التجريبي', 'quoted_on' => '2026-10-05', 'rows' => [['item_name' => 'قماش', 'quantity' => '4', 'unit_price' => '1,250.50'], ['item_name' => 'خيط', 'quantity' => '2', 'unit_price' => '100']], 'print' => '1'])
            ->assertRedirect('/ar/applications/'.$id.'/purchase-papers/price-quote');
        $this->get('/ar/applications/'.$id.'/purchase-papers/price-quote')->assertOk()->assertSee('المورد التجريبي')->assertSee('2026/10/05')->assertSee('1,250.5')->assertSee('5,002')->assertSee('5,202');

        // A line without a price leaves the grand total blank rather than printing a partial sum.
        $this->post($url.'/quote', ['rows' => [['item_name' => 'قماش', 'quantity' => '4', 'unit_price' => '1000'], ['item_name' => 'خيط', 'quantity' => '2', 'unit_price' => '']]])->assertRedirect();
        $this->get('/ar/applications/'.$id.'/purchase-papers/price-quote')->assertOk()->assertSee('4,000')->assertSee('<td class="sum total"></td>', false);
        $this->assertSame(1, DB::table('price_quotes')->where('project_id', $id)->count());
        $this->assertSame(2, DB::table('price_quote_items')->whereNull('deleted_at')->count());

        $this->post($url.'/receipt', ['received_on' => '2026-11-02'])->assertRedirect();
        $this->get('/ar/applications/'.$id.'/purchase-papers/equipment-receipt')->assertOk()->assertSee('2026/11/02');

        // Only the ticked suggestion and the typed line are returned.
        $this->post($url.'/return', ['returned_on' => '2026-11-20', 'rows' => [
            ['suggested' => '1', 'include' => '1', 'item_name' => 'ماكينة درزة', 'brand' => 'JUKI-77', 'serial_number' => 'SN-900', 'technical_state' => 'جيدة'],
            ['suggested' => '1', 'item_name' => 'مكواة بخار', 'brand' => 'ST-5'],
            ['item_name' => 'طاولة قديمة'],
        ]])->assertRedirect($url);
        $this->get('/ar/applications/'.$id.'/purchase-papers/equipment-return')->assertOk()->assertSee('2026/11/20')->assertSee('SN-900')->assertSee('طاولة قديمة')->assertDontSee('مكواة بخار');
        $this->assertSame(2, DB::table('equipment_return_items')->whereNull('deleted_at')->count());
        $this->assertSame(5, DB::table('account_audit_events')->where('event_type', 'papers.data_saved')->where('subject_id', $id)->count());
    }

    public function test_bad_lines_are_refused_and_nothing_changes(): void
    {
        $id = $this->project('DAM');
        $this->signIn('super.admin');
        $url = '/ar/applications/'.$id.'/purchase-data';
        $this->post($url.'/request', ['rows' => [['material' => '', 'quantity' => '2']]])->assertSessionHasErrors('rows.0.material');
        $this->post($url.'/request', ['rows' => [['material' => 'مقص', 'quantity' => '2.5']]])->assertSessionHasErrors('rows.0.quantity');
        $this->post($url.'/request', ['rows' => [['material' => 'مقص', 'material_state' => 'broken']]])->assertSessionHasErrors('rows.0.material_state');
        $this->post($url.'/quote', ['rows' => [['item_name' => 'قماش', 'unit_price' => 'abc']]])->assertSessionHasErrors('rows.0.unit_price');
        $this->post($url.'/receipt', ['received_on' => '2026-13-40'])->assertSessionHasErrors('received_on');
        $this->post($url.'/quote', ['supplier_name' => ['x'], 'rows' => 'text'])->assertRedirect();
        $this->post($url.'/other', [])->assertNotFound();
        $this->assertSame(0, DB::table('purchase_requests')->where('project_id', $id)->count());
        $this->assertSame(0, DB::table('equipment_receipts')->where('project_id', $id)->count());
    }

    public function test_the_purchasing_right_and_the_city_are_required(): void
    {
        $dam = $this->project('DAM');
        $hms = $this->project('HMS');
        $this->account('buy.hms', 'legal', 'HMS');
        $this->get('/ar/applications/'.$hms.'/purchase-data')->assertOk();
        $this->get('/ar/applications/'.$hms.'/purchase-papers/purchase-request')->assertOk();
        $this->assertContains($this->get('/ar/applications/'.$dam.'/purchase-papers/purchase-request')->status(), [403, 404]);
        $this->assertContains($this->post('/ar/applications/'.$dam.'/purchase-data/receipt', ['received_on' => '2026-11-02'])->status(), [403, 404]);
        $this->get('/ar/applications/'.$hms.'/purchase-papers/unknown')->assertNotFound();

        \Illuminate\Support\Facades\Auth::guard()->logout();
        $this->flushSession();
        $this->get('/ar/applications/'.$hms.'/purchase-data')->assertRedirect();

        $this->account('look.hms', 'guest', 'HMS');
        $this->get('/ar/applications/'.$hms.'/purchase-data')->assertForbidden();
        $this->post('/ar/applications/'.$hms.'/purchase-data/receipt', ['received_on' => '2026-11-02'])->assertForbidden();
        $this->assertSame(0, DB::table('equipment_receipts')->count());
    }
}
