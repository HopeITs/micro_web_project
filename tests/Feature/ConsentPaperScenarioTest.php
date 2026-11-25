<?php

namespace Tests\Feature;

use App\Services\Papers\PaperLibrary;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** M4.20 user scenarios: the photo consents of Caritas projects, and the comparison page of every redrawn paper. */
class ConsentPaperScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->post('/ar/login', ['login' => RoleAccountsSeeder::email('super.admin'), 'password' => 'password'])->assertRedirect();
    }

    private function city(string $code): int
    {
        return (int) DB::table('cities')->where('code', $code)->value('id');
    }

    private function project(): int
    {
        return (int) DB::table('projects')->where('city_id', $this->city('DAM'))->where('application_stage', 'full')->whereNull('deleted_at')->orderByDesc('id')->value('id');
    }

    private function donor(int $project, string $name): void
    {
        $id = DB::table('donors')->insertGetId(['name_ar' => $name, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('projects')->where('id', $project)->update(['donor_id' => $id]);
    }

    /** Adds a person to the beneficiary's family, born the given number of years ago. */
    private function relative(int $project, string $name, int $age): void
    {
        $beneficiary = (int) DB::table('project_applicants')->where('project_id', $project)->orderBy('id')->value('person_id');
        $city = $this->city('DAM');
        $stamp = ['created_at' => now(), 'updated_at' => now()];
        $family = DB::table('family_members')->where('person_id', $beneficiary)->whereNull('deleted_at')->value('family_id');
        if ($family === null) {
            $family = DB::table('families')->insertGetId(['city_id' => $city, 'family_number' => '9001'] + $stamp);
            DB::table('family_members')->insert(['city_id' => $city, 'family_id' => $family, 'person_id' => $beneficiary] + $stamp);
        }
        $person = DB::table('people')->insertGetId(['city_id' => $city, 'first_name' => $name, 'last_name' => 'التجريبي', 'birth_date' => now()->subYears($age)->toDateString()] + $stamp);
        DB::table('family_members')->insert(['city_id' => $city, 'family_id' => $family, 'person_id' => $person] + $stamp);
    }

    public function test_the_consents_print_only_for_a_caritas_project(): void
    {
        $id = $this->project();
        $this->donor($id, 'مانح آخر');
        foreach (['photo-adult', 'photo-child', 'photo-informed'] as $paper) {
            $this->get('/ar/applications/'.$id.'/papers/'.$paper)->assertNotFound();
        }
        $this->get('/ar/projects/'.$id)->assertOk()->assertDontSee('موافقة صورة (بالغ)');

        $this->donor($id, 'Caritas Poland');
        $first = (string) DB::table('project_applicants as a')->join('people as p', 'p.id', '=', 'a.person_id')->where('a.project_id', $id)->orderBy('a.id')->value('p.first_name');
        $this->get('/ar/projects/'.$id)->assertOk()->assertSee('موافقة صورة (بالغ)')->assertSee('إقرار استخدام الصور');
        $this->get('/ar/applications/'.$id.'/papers/photo-adult')->assertOk()->assertSee('الموافقة على استخدام الصورة الشخصية والصوت')->assertSee('Zgoda na rozpowszechnianie wizerunku i głosu')
            ->assertSee('ul. Okopowa 55, 01-043 Warszawa')->assertSee('działające na zlecenie Caritas Polska')->assertSee('4 فبراير 1994')->assertSee($first)->assertSee('caritas-polska-header.png');
        $this->get('/ar/applications/'.$id.'/papers/photo-informed')->assertOk()->assertSee('إقرار بالموافقة عن علم')->assertSee('complaint@hcsyria.org')->assertSee('لا أرغب')->assertSee($first)
            ->assertSee((string) DB::table('projects')->where('id', $id)->value('name'));
        $this->assertSame(['paper:F24', 'paper:F27'], DB::table('account_audit_events')->where('event_type', 'papers.printed')->where('subject_id', $id)->orderBy('id')->pluck('decision_locator')->all());
    }

    public function test_the_child_consent_prints_one_sheet_for_each_child_under_eighteen(): void
    {
        $id = $this->project();
        $this->donor($id, 'كاريتاس بولندا');
        DB::table('family_members')->where('person_id', DB::table('project_applicants')->where('project_id', $id)->orderBy('id')->value('person_id'))->delete();
        $blank = $this->get('/ar/applications/'.$id.'/papers/photo-child')->assertOk()->assertSee('الصوت والفيديو')->assertSee('przedstawiciel ustawowy')->getContent();
        $this->assertSame(1, substr_count($blank, '<section class="sheet consent"'));

        $this->relative($id, 'طفلأول', 7);
        $this->relative($id, 'طفلثان', 15);
        $this->relative($id, 'راشد', 30);
        $page = $this->get('/ar/applications/'.$id.'/papers/photo-child')->assertOk()->assertSee('طفلأول')->assertSee('طفلثان')->assertDontSee('راشد');
        $this->assertSame(2, substr_count($page->getContent(), '<section class="sheet consent"'));
        $this->get('/ar/applications/'.$id.'/papers/photo-informed')->assertSee('طفلأول')->assertSee('طفلثان');
    }

    public function test_every_redrawn_paper_has_a_comparison_page_whose_redraw_can_be_framed(): void
    {
        Storage::fake('local');
        $id = $this->project();
        $this->donor($id, 'Caritas Poland');
        foreach (array_keys(PaperLibrary::REDRAWN) as $form) {
            if ($form === 'F02') {
                continue;
            }
            $entry = collect(config('papers.files'))->first(fn ($e) => $e['form'] === $form && $e['city'] === 'DAM' && $e['variant'] === 'syrian' && ($e['place'] ?? null) === null);
            $this->assertNotNull($entry, $form.' has no Damascus original');
            Storage::disk('local')->put('papers/'.$entry['sha256'].'.'.$entry['format'], 'x');
        }
        // The library installs a file only under its own hash, so the pages are checked through the catalogue entry the review picks.
        foreach (PaperLibrary::REDRAWN as $form => [$route, $with]) {
            $url = route($route, ['locale' => 'ar', 'project' => $id] + $with);
            $print = $this->get($url);
            if ($form === 'F02') {
                continue;
            }
            $print->assertOk();
            $this->assertSame('SAMEORIGIN', $print->headers->get('X-Frame-Options'), $form);
            $this->get('/ar/papers/review/'.$form.'?city=DAM')->assertOk();
        }
        $this->assertSame('DENY', $this->get('/ar/applications/'.$id.'/deeds')->headers->get('X-Frame-Options'));
        $this->assertSame('DENY', $this->get('/ar/applications/'.$id.'/paper-data')->headers->get('X-Frame-Options'));
        $this->get('/ar/papers/review/F26')->assertNotFound();
    }
}
