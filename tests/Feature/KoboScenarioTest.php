<?php

namespace Tests\Feature;

use App\Livewire\KoboIndex;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Services\Kobo\KoboCatalog;
use App\Services\ProjectNumberAllocator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M4.14 user scenarios (read and review only): monitoring opens the Kobo page, filters the submissions and reads one with its questions in
 * words; the results count only confirmed submissions and export to Excel/CSV; the review queues and visitor names are shown without any
 * decision; a one-city account sees its city only; roles without visits.manage are refused; the catalogue decodes answers.
 */
class KoboScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
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

    public function test_the_synthetic_seed_covers_every_case(): void
    {
        $this->assertSame(5, DB::table('kobo_forms')->distinct()->count('city_id'));
        foreach (['local_inserted', 'local_error', 'deleted', 'local_updated', 'review_replaced'] as $status) {
            $this->assertTrue(DB::table('kobo_submissions')->where('sync_status', $status)->exists(), $status);
        }
        $this->assertTrue(DB::table('kobo_sync_conflicts')->where('state', 'pending')->exists());
        $this->assertTrue(DB::table('kobo_person_reviews')->where('state', 'confirmed')->exists());
        // Every seeded form is a catalogue form, so its answers read as questions.
        foreach (DB::table('kobo_forms')->pluck('form_uid') as $uid) {
            $this->assertNotSame([], KoboCatalog::fields($uid));
        }
    }

    public function test_monitoring_lists_filters_and_reads_submissions(): void
    {
        $me = $this->as('role.monitoring');
        $this->get('/ar/kobo')->assertOk()->assertSeeLivewire(KoboIndex::class)->assertSee(__('kobo.read_only', [], 'ar'))->assertSee(__('kobo.tabs.reviews', [], 'ar'));
        $this->assertTrue(DB::table('account_audit_events')->where('actor_account_id', $me->id)->where('event_type', 'kobo.list')->exists());
        $this->get('/en/kobo')->assertOk()->assertSee('Kobo visits are entered, edited and deleted here');

        $all = DB::table('kobo_submissions')->whereNull('deleted_at')->count();
        $page = Livewire::test(KoboIndex::class)->assertViewHas('submissions', fn ($p) => $p->total() === $all);

        // Status and city filters.
        $page->set('status', 'error')->assertViewHas('submissions', fn ($p) => $p->total() === DB::table('kobo_submissions')->where('sync_status', 'local_error')->count());
        $page->set('status', '')->call('toggleCity', $this->city('HMS'))
            ->assertViewHas('submissions', fn ($p) => $p->total() === DB::table('kobo_submissions')->where('city_id', $this->city('HMS'))->count() && collect($p->items())->every(fn ($s) => $s->city_code === 'HMS'));

        // Search by project number.
        $record = DB::table('kobo_monitoring_records as m')->join('projects as p', 'p.id', '=', 'm.project_id')->join('cities as c', 'c.id', '=', 'p.city_id')
            ->where('c.code', 'HMS')->first(['p.id', 'p.project_number', 'm.kobo_submission_id']);
        $number = ProjectNumberAllocator::display('HMS', (int) $record->project_number);
        $page->set('search', $number)->assertViewHas('submissions', fn ($p) => $p->total() > 0 && collect($p->items())->every(fn ($s) => (int) $s->project_id === (int) $record->id));

        // Reading one: questions in words, choice codes as labels, Kobo metadata hidden; the view is audited.
        $submission = DB::table('kobo_submissions')->find($record->kobo_submission_id);
        $raw = json_decode($submission->raw_json, true);
        $choice = collect(KoboCatalog::fields($submission->form_uid))->first(fn ($f) => KoboCatalog::kind($f) === 'select_one' && isset($raw[$f['path']]) && $f['choices'] !== []);
        $label = collect($choice['choices'])->firstWhere('code', $raw[$choice['path']])['label'];
        $page->call('show', $submission->id)->assertSee($choice['label'])->assertSee($label)->assertDontSee('formhub/uuid');
        $this->assertTrue(DB::table('account_audit_events')->where('actor_account_id', $me->id)->where('event_type', 'kobo.view')->exists());
        $page->call('show', $submission->id)->assertViewHas('detail', null);

        // The import error message is shown on its submission.
        $error = DB::table('kobo_submissions')->where('sync_status', 'local_error')->first();
        Livewire::test(KoboIndex::class)->set('status', 'error')->call('show', $error->id)->assertSee($error->error_message);
    }

    public function test_results_count_confirmed_submissions_and_export(): void
    {
        $this->as('role.monitoring');
        $confirmed = DB::table('kobo_submissions as s')->join('kobo_monitoring_records as m', 'm.kobo_submission_id', '=', 's.id')
            ->whereIn('s.sync_status', ['local_inserted', 'local_updated'])->where('s.is_deleted_in_kobo', false)->distinct()->count('s.id');
        $page = Livewire::test(KoboIndex::class)->call('setTab', 'results')
            ->assertViewHas('results', fn ($r) => $r['submissions'] === $confirmed && $r['questions'] !== []);
        // Each question's options add up to at least its answered count; blank counts are never negative.
        $page->assertViewHas('results', fn ($r) => collect($r['questions'])->every(fn ($q) => array_sum(array_column($q['options'], 'count')) >= $q['answered'] && $q['blank'] >= 0));
        // Latest visit only keeps one row per project and person.
        $page->set('last', true)->assertViewHas('results', fn ($r) => $r['submissions'] < $confirmed);
        $page->set('last', false)->set('mode', 'answers')
            ->assertViewHas('results', fn ($r) => count($r['rows']) === $confirmed && $r['columns'] !== [])->assertSee('Excel');

        $response = $this->get('/ar/kobo/export?format=csv&city[]='.$this->city('DAM'));
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString(__('kobo.context.number', [], 'ar'), $csv);
        $this->assertStringContainsString('DAM', $csv);
        $this->assertStringNotContainsString('HMS-', $csv);
        $this->get('/ar/kobo/export')->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'kobo.exported')->exists());
        $this->get('/ar/kobo/export?from=bad')->assertSessionHasErrors('from');
    }

    public function test_review_queues_and_visitors_are_read_only(): void
    {
        $this->as('role.administrator');
        Livewire::test(KoboIndex::class)->call('setTab', 'reviews')
            ->assertViewHas('conflicts', fn ($c) => $c->contains(fn ($k) => $k->state === 'pending' && count($k->differences) === 2)
                && $c->contains(fn ($k) => $k->state === 'resolved' && $k->history->count() === 3))
            ->assertViewHas('reviews', fn ($r) => $r->contains(fn ($x) => $x->state === 'pending' && $x->candidates->isNotEmpty()) && $r->contains(fn ($x) => $x->chosen !== null))
            ->assertSee(__('kobo.decisions.replace', [], 'ar'))->assertSee('أم جورج');
        Livewire::test(KoboIndex::class)->call('setTab', 'visitors')
            ->assertViewHas('visitors', fn ($v) => $v['mappings']->count() === 20 && $v['unlinked']->isNotEmpty())->assertSee(__('kobo.ignored', [], 'ar'));

        // Nothing on the page writes: no decision method exists, and opening every tab changes no Kobo row.
        foreach (['decide', 'resolve', 'confirm', 'sync', 'download'] as $method) {
            $this->assertFalse(method_exists(KoboIndex::class, $method), $method);
        }
        $before = DB::table('kobo_sync_conflicts')->get()->toJson().DB::table('kobo_person_reviews')->get()->toJson();
        foreach (KoboIndex::TABS as $tab) {
            Livewire::test(KoboIndex::class)->call('setTab', $tab)->assertOk();
        }
        $this->assertSame($before, DB::table('kobo_sync_conflicts')->get()->toJson().DB::table('kobo_person_reviews')->get()->toJson());
    }

    public function test_a_one_city_account_sees_only_its_city(): void
    {
        $account = app(AccountProvisioner::class)->createPlain('only.hms', 'only.hms@hcsyria.org', Hash::make('password'), 'test:kobo');
        DB::table('web_accounts')->where('id', $account->id)->update(['password_changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        app(AccessRoles::class)->assign($account->fresh(), $this->city('HMS'), 'monitoring', 'test:kobo');
        $this->actingAs($account->fresh());
        $hms = DB::table('kobo_submissions')->where('city_id', $this->city('HMS'))->count();
        // Asking for another city falls back to the permitted one.
        Livewire::test(KoboIndex::class)->assertViewHas('cityOptions', fn ($c) => $c->count() === 1)
            ->assertViewHas('submissions', fn ($p) => $p->total() === $hms)
            ->set('cities', [$this->city('DAM')])->assertViewHas('submissions', fn ($p) => $p->total() === $hms && collect($p->items())->every(fn ($s) => $s->city_code === 'HMS'));
        $other = DB::table('kobo_submissions')->where('city_id', $this->city('DAM'))->value('id');
        Livewire::test(KoboIndex::class)->call('show', $other)->assertStatus(404);
        $this->assertStringNotContainsString('DAM-', $this->get('/ar/kobo/export?format=csv&city[]='.$this->city('DAM'))->streamedContent());
    }

    public function test_roles_without_visits_are_refused(): void
    {
        foreach (['role.financial', 'role.legal', 'role.guest', 'role.media'] as $username) {
            $me = $this->as($username);
            $this->get('/ar/kobo')->assertForbidden();
            $this->get('/ar/kobo/export')->assertForbidden();
            $this->assertTrue(DB::table('account_audit_events')->where('actor_account_id', $me->id)->where('event_type', 'access.denied')->exists(), $username);
        }
        $this->as('role.financial');
        $this->get('/ar')->assertOk()->assertDontSee(route('kobo.index', ['locale' => 'ar']));
        $this->as('role.monitoring');
        $this->get('/ar')->assertOk()->assertSee(route('kobo.index', ['locale' => 'ar']));
    }

    public function test_the_catalogue_decodes_answers(): void
    {
        $this->assertCount(46, KoboCatalog::forms());
        $field = ['path' => 'g/q', 'label' => 'سؤال', 'type' => 'select_multiple x', 'choices' => [['code' => 'a', 'label' => 'أ'], ['code' => 'b', 'label' => 'ب']]];
        $this->assertSame('أ، ب، z', KoboCatalog::display($field, 'a b z'));
        $this->assertSame('', KoboCatalog::display($field, null));
        $this->assertSame('هل يعمل المشروع', KoboCatalog::normalize(' هَل يعمـل   المشروع؟ '));
        $this->assertFalse(KoboCatalog::reported(['path' => 'group_mo5jr41/x', 'label' => 'x', 'type' => 'text']));
        $this->assertFalse(KoboCatalog::reported(['path' => 'g/v', 'label' => 'رقم الزيارة', 'type' => 'select_one s']));
        $readable = KoboCatalog::readable('unknown', ['_id' => 1, 'meta/instanceID' => 'x', 'my_field' => 'value']);
        $this->assertSame([['section' => "\u{2026}", 'items' => [['label' => 'my_field', 'value' => 'value', 'path' => 'my_field']]]], $readable);
    }
}
