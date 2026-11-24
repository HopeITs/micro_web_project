<?php

namespace Tests\Feature;

use App\Livewire\KoboIndex;
use App\Livewire\KoboVisitForm;
use App\Models\WebAccount;
use App\Services\Kobo\KoboCatalog;
use App\Services\Kobo\KoboRules;
use App\Services\Lifecycle\LifecycleService;
use App\Services\Staff\ProjectNotices;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M4.14b user scenarios: data entry opens the Kobo form of a project and records the opening, then the M1 and M2 monitoring visits of the
 * beneficiary (identity, visit, visitors, task, reminder, audit); duplicates, wrong choices, missing visitors and kinds are refused; an edit
 * changes the answers and the date with before/after in the audit; delete is for the administrator and data entry, not monitoring; the
 * visits the desktop downloaded stay read-only until the cut-over switch; agriculture and livestock stages follow the previous visit and
 * plan the next reminder; repeat groups are stored Kobo-style and read back; refused roles.
 */
class KoboVisitScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected const OPENING = 'aFAaQVePpXeKJTGU89zGgV';

    protected const MONITORING = 'aFqwLzjDcQ4gczMdfUaXNq';

    protected const VEHICLE_OPENING = 'akQA4KSvPFy3Bt9LnCjnBw';

    protected const AGRI_OPENING = 'a6yNApUu9Jqph4RumUzxAw';

    protected const AGRI_MONITORING = 'aL3TGhp5wAhuPseTMhEksd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function as(string $username): WebAccount
    {
        $account = WebAccount::where('username', $username)->firstOrFail();
        $this->actingAs($account);

        return $account;
    }

    protected function visitor(): int
    {
        return (int) WebAccount::where('username', 'role.monitoring')->value('id');
    }

    /** The real desktop configuration of these forms (project number, companions, plate and stage fields) for the project's city. */
    protected function forms(int $city): void
    {
        DB::table('kobo_forms')->where('city_id', $city)->delete();
        $rows = [
            [self::OPENING, 'opening', 'general', 'visit', 'group_mo5jr41/text_fw08u96', 'group_mo5jr41/select_one_ms1dv75', null, null, null],
            [self::MONITORING, 'monitoring', 'general', 'mevisit', 'group_mo5jr41/text_fw08u96', 'group_mo5jr41/select_one_ms1dv75', null, '__010', null],
            [self::VEHICLE_OPENING, 'opening', 'vehicle', 'visit', 'group_mo5jr41/__003', 'group_mo5jr41/__006', 'group_vz9zl11/__008', null, null],
            [self::AGRI_OPENING, 'opening', 'agriculture', 'visit', 'group_vv6uu79/__001', 'group_vv6uu79/__006', null, null, null],
            [self::AGRI_MONITORING, 'monitoring', 'agriculture', 'mevisit', 'group_mo5jr41/text_fw08u96', 'group_mo5jr41/select_one_vt8tw79', null, null, null],
        ];
        foreach ($rows as [$uid, $type, $category, $table, $project, $visitor, $plate, $stage, $fixed]) {
            DB::table('kobo_forms')->insert(['city_id' => $city, 'form_uid' => $uid, 'form_name' => $type.' - '.$category, 'governorate' => 'test', 'visit_type' => $type, 'visit_category' => $category,
                'target_table' => $table, 'is_active' => true, 'project_number_field' => $project, 'visitor_choice_field' => $visitor, 'vehicle_number_field' => $plate, 'stage_field' => $stage,
                'fixed_stage' => $fixed, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** A financed project (loan of 2026-01-10) with tasks and visit notices, of the chosen kind, and the city's Kobo forms. */
    protected function project(int $skip = 0, string $kind = 'other', ?string $type = null): object
    {
        $project = DB::table('projects')->whereExists(fn ($q) => $q->selectRaw('1')->from('project_applicants as a')->whereColumn('a.project_id', 'projects.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('loans as l')->whereColumn('l.project_id', 'projects.id'))->orderBy('id')->skip($skip)->first();
        foreach (['project_state_history', 'project_tasks', 'web_notices', 'visits', 'me_visits', 'kobo_monitoring_records'] as $table) {
            DB::table($table)->where('project_id', $project->id)->delete();
        }
        $sub = DB::table('project_subcategories')->where('name_ar', $kind === 'vehicle' ? 'Taxi' : 'Shop')->value('id');
        DB::table('projects')->where('id', $project->id)->update([
            'project_subcategory_id' => $sub, 'donor_id' => DB::table('donors')->orderBy('id')->value('id'),
            'project_type_id' => DB::table('project_types')->where('name_ar', $kind === 'rehab' ? 'ترميم' : ($type ?? 'مشاريع صغيرة'))->value('id'),
            'fund_type_id' => DB::table('fund_types')->where('name_ar', $kind === 'rehab' ? 'منحة' : 'قرض')->value('id'),
        ]);
        $project = DB::table('projects')->where('id', $project->id)->first();
        $lifecycle = app(LifecycleService::class);
        $lifecycle->recordInitial($project, 'Accepted');
        $lifecycle->assignTasks($project, 'f');
        DB::table('loans')->insert(['city_id' => $project->city_id, 'project_id' => $project->id, 'currency' => 'USD', 'amount' => 1000, 'taken_on' => '2026-01-10', 'created_at' => now(), 'updated_at' => now()]);
        $lifecycle->recordInitial($project, 'Financed');
        app(ProjectNotices::class)->generateVisitSchedule($project, '2026-01-10', null);
        $this->forms((int) $project->city_id);

        return $project;
    }

    protected function index(string $uid, string $path): int
    {
        return (int) array_search($path, array_column(KoboCatalog::forms()[$uid]['fields'], 'path'), true);
    }

    protected function task(int $projectId, string $name): int
    {
        return (int) DB::table('project_tasks as t')->join('task_definitions as d', 'd.id', '=', 't.task_definition_id')->where('t.project_id', $projectId)->where('d.name_ar', $name)->value('t.state');
    }

    protected function notice(int $projectId, string $body): ?int
    {
        $seen = DB::table('web_notices')->where('project_id', $projectId)->whereNull('account_id')->where('body', $body)->value('seen');

        return $seen === null ? null : (int) $seen;
    }

    protected function beneficiary(object $project): int
    {
        return (int) DB::table('project_applicants')->where('project_id', $project->id)->where('role', 'مستفيد')->value('person_id');
    }

    protected function page(object $project, string $uid, ?int $submission = null)
    {
        return Livewire::test(KoboVisitForm::class, ['projectId' => $project->id, 'submissionId' => $submission, 'start' => $submission === null ? $uid : null]);
    }

    /** A sample answer for a question (a person filling the form in). */
    protected function sample(array $field): mixed
    {
        $kind = KoboCatalog::kind($field);

        return match (true) {
            $kind === 'select_one' => $field['choices'] === [] ? 'x' : $field['choices'][0]['code'],
            $kind === 'select_multiple' => $field['choices'] === [] ? [] : [$field['choices'][0]['code']],
            in_array($kind, ['integer', 'decimal'], true) => '5',
            $kind === 'date' => '2026-02-02',
            default => 'x',
        };
    }

    /**
     * Saves like a person would: every required question is answered first (the XLSForm rules make most of them required), then the save is
     * sent; the questions in $stop are left empty so their refusal can be checked, and repeat rows are completed when the save asks for them.
     */
    protected function complete($page, string $uid, array $stop = [])
    {
        $a = (array) $page->get('a');
        foreach (KoboCatalog::forms()[$uid]['fields'] as $i => $field) {
            $rule = KoboRules::for($uid, $field['path']);
            if (! ($rule['required'] ?? false) || KoboCatalog::kind($field) === 'geopoint' || ! empty($field['repeat']) || in_array('a.'.$i, $stop, true) || ($a[$i] ?? '') !== '' && ($a[$i] ?? []) !== []) {
                continue;
            }
            $a[$i] = $this->sample($field);
        }
        $page->set('a', $a)->call('save');
        for ($n = 0; $n < 12; $n++) {
            $filled = false;
            foreach ($page->errors()->keys() as $key) {
                if ($page->errors()->first($key) === __('kobo.errors.required') && preg_match('/^r\.(\d+)\.(\d+)\.(\d+)$/', $key, $m) === 1) {
                    $page->set($key, $this->sample(KoboCatalog::forms()[$uid]['fields'][(int) $m[3]]));
                    $filled = true;
                }
            }
            if (! $filled) {
                break;
            }
            $page->call('save');
        }

        return $page;
    }

    protected function opening(object $project, string $date = '2026-02-01')
    {
        return $this->complete($this->page($project, self::OPENING)->set('date', $date)->set('visitors.0', (string) $this->visitor())
            ->set('a.'.$this->index(self::OPENING, 'group_mo5jr41/Doner'), 'ACN')->set('a.'.$this->index(self::OPENING, 'group_mo5jr41/text_qp0vk54'), 'شارع الجلاء'), self::OPENING);
    }

    protected function monitoring(object $project, string $stage, string $date = '2026-07-15', ?int $person = null)
    {
        return $this->complete($this->page($project, self::MONITORING)->set('date', $date)->set('person', (string) ($person ?? $this->beneficiary($project)))->set('visitors.0', (string) $this->visitor())
            ->set('a.'.$this->index(self::MONITORING, '__010'), $stage), self::MONITORING);
    }

    public function test_data_entry_records_the_opening_form_with_task_notice_and_visitor(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $this->get('/ar/kobo/visits/new/'.$project->id)->assertOk()->assertSeeLivewire(KoboVisitForm::class);
        $this->assertSame(0, $this->notice($project->id, 'Visit Opening-O'));

        $this->opening($project)->assertHasNoErrors()->assertRedirect();
        $submission = DB::table('kobo_submissions')->whereNull('kobo_submission_id')->where('form_uid', self::OPENING)->first();
        $this->assertNull($submission->kobo_submission_id, 'Written on the web, not by the sync.');
        $this->assertSame('visits', $submission->local_table);
        $this->assertSame('local_inserted', $submission->sync_status);
        $raw = json_decode($submission->raw_json, true);
        $this->assertSame((string) $project->project_number, $raw['group_mo5jr41/text_fw08u96']);
        $this->assertSame('ACN', $raw['group_mo5jr41/Doner']);
        $this->assertSame('2026-02-01', $raw['today']);
        $visit = DB::table('visits')->where('id', $submission->local_record_id)->first();
        $this->assertSame('O-op', $visit->kind);
        $this->assertSame('2026-02-01', substr($visit->visit_date, 0, 10));
        $this->assertSame(1, $this->task($project->id, 'Opening Visit'));
        $this->assertSame(1, $this->notice($project->id, 'Visit Opening-O'));
        $this->assertSame(1, DB::table('visit_visitors')->where('visit_id', $visit->id)->where('account_id', $this->visitor())->count());
        $this->assertSame(1, DB::table('kobo_visit_visitors')->where('kobo_submission_id', $submission->id)->where('account_id', $this->visitor())->count());
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'kobo.visit_saved')->exists());

        // It reads like a synced visit: answers in words on the Kobo page, and the second opening is refused.
        Livewire::test(KoboIndex::class)->set('search', (string) $project->project_number)->assertSee(route('kobo.visit.show', ['locale' => 'ar', 'submission' => $submission->id]));
        $this->opening($project, '2026-02-03')->assertHasErrors('visit');
    }

    public function test_monitoring_visits_record_identity_stage_and_reminder(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $person = $this->beneficiary($project);
        $this->monitoring($project, 'M&E_1')->assertHasNoErrors();
        $submission = DB::table('kobo_submissions')->whereNull('kobo_submission_id')->where('form_uid', self::MONITORING)->first();
        $visit = DB::table('me_visits')->where('id', $submission->local_record_id)->first();
        $this->assertSame([$person, '1', 'o'], [(int) $visit->person_id, $visit->visit_number, $visit->kind]);
        $record = DB::table('kobo_monitoring_records')->where('kobo_submission_id', $submission->id)->first();
        $this->assertSame([$project->id, $person, '1', $visit->id], [(int) $record->project_id, (int) $record->person_id, $record->visit_number, (int) $record->me_visit_id]);
        $this->assertSame(1, $this->task($project->id, 'M1'));
        $this->assertSame(1, $this->notice($project->id, 'Visit M1-O'));
        $this->assertSame(1, DB::table('me_visit_visitors')->where('me_visit_id', $visit->id)->count());

        // The same number again is refused; the next number is accepted.
        $this->monitoring($project, 'M&E_1', '2026-08-01')->assertHasErrors('visit');
        $this->monitoring($project, 'M&E_2', '2026-09-01')->assertHasNoErrors();
        $this->assertSame(1, $this->task($project->id, 'M2'));
        // The results page counts both as one row per visit.
        $this->assertSame(2, DB::table('me_visits')->where('project_id', $project->id)->whereNull('deleted_at')->count());
    }

    public function test_refusals_choices_visitors_kinds_and_dates(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $this->page($project, self::OPENING)->set('date', '2026-02-01')->call('save')->assertHasErrors('visitors');
        $this->page($project, self::OPENING)->set('date', '2999-01-01')->set('visitors.0', (string) $this->visitor())->call('save')->assertHasErrors('date');
        $this->page($project, self::OPENING)->set('date', '2025-12-01')->set('visitors.0', (string) $this->visitor())->call('save')->assertHasErrors('date');
        $this->page($project, self::OPENING)->set('date', '2026-02-01')->set('visitors.0', (string) WebAccount::where('username', 'role.guest')->value('id'))->call('save')->assertHasErrors('visitors');
        $this->page($project, self::OPENING)->set('date', '2026-02-01')->set('visitors.0', (string) $this->visitor())
            ->set('a.'.$this->index(self::OPENING, 'group_mo5jr41/Doner'), 'not-a-donor')->call('save')->assertHasErrors('a.'.$this->index(self::OPENING, 'group_mo5jr41/Doner'));
        $this->monitoring($project, 'M5')->assertHasErrors('a.'.$this->index(self::MONITORING, '__010'));
        $this->monitoring($project, 'M&E_1', '2026-07-15', 999999)->assertHasErrors('person');
        // A vehicle form for a non-vehicle project, and a rehabilitation project.
        $this->page($project, self::VEHICLE_OPENING)->set('date', '2026-02-01')->set('visitors.0', (string) $this->visitor())->call('save')->assertHasErrors('form');
        $rehab = $this->project(1, 'rehab');
        $this->page($rehab, self::OPENING)->set('date', '2026-02-01')->set('visitors.0', (string) $this->visitor())->call('save')->assertHasErrors('visit');
        $this->assertSame(0, DB::table('kobo_submissions')->whereNull('kobo_submission_id')->count());
    }

    public function test_vehicle_projects_need_the_plate_and_use_the_vehicle_forms(): void
    {
        $this->as('role.data-entry');
        $project = $this->project(0, 'vehicle');
        $page = $this->page($project, self::VEHICLE_OPENING)->set('date', '2026-02-01')->set('visitors.0', (string) $this->visitor());
        $plate = 'a.'.$this->index(self::VEHICLE_OPENING, 'group_vz9zl11/__008');
        $this->complete($page, self::VEHICLE_OPENING, [$plate])->assertHasErrors($plate);
        $this->complete($page->set($plate, '123456'), self::VEHICLE_OPENING)->assertHasNoErrors();
        $visit = DB::table('visits')->where('project_id', $project->id)->first();
        $this->assertSame(['V-op', '123456'], [$visit->kind, $visit->vehicle_number]);
        $forms = app(\App\Services\Kobo\KoboVisitService::class)->forms($project);
        $this->assertSame(['vehicle'], array_values(array_unique(array_column($forms, 'category'))));
    }

    public function test_edit_records_before_and_after_and_delete_is_for_the_administrator_and_data_entry(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $this->opening($project);
        $submission = (int) DB::table('kobo_submissions')->whereNull('kobo_submission_id')->value('id');
        $address = $this->index(self::OPENING, 'group_mo5jr41/text_qp0vk54');
        $page = $this->page($project, self::OPENING, $submission)->assertSet('a.'.$address, 'شارع الجلاء');
        $page->set('date', '2026-02-10')->set('a.'.$address, 'شارع بغداد')->call('save')->assertHasNoErrors()->assertSee(__('kobo.saved'));
        $this->assertSame('local_updated', DB::table('kobo_submissions')->where('id', $submission)->value('sync_status'));
        $this->assertSame('2026-02-10', substr((string) DB::table('visits')->where('project_id', $project->id)->value('visit_date'), 0, 10));
        $details = DB::table('account_audit_events')->where('event_type', 'kobo.visit_saved')->orderByDesc('id')->value('details');
        $details = json_encode(json_decode((string) $details, true), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('شارع بغداد', $details);
        $this->assertStringContainsString('شارع الجلاء', (string) $details);
        $this->assertSame(1, DB::table('visits')->where('project_id', $project->id)->count(), 'Edited in place, not duplicated.');

        // Monitoring may record but not delete.
        $this->as('role.monitoring');
        $this->page($project, self::OPENING, $submission)->call('delete')->assertForbidden();
        $this->assertNull(DB::table('kobo_submissions')->where('id', $submission)->value('deleted_at'));

        $this->as('role.administrator');
        $this->page($project, self::OPENING, $submission)->call('delete')->assertRedirect();
        $this->assertNotNull(DB::table('kobo_submissions')->where('id', $submission)->value('deleted_at'));
        $this->assertNotNull(DB::table('visits')->where('project_id', $project->id)->value('deleted_at'));
        $this->assertSame(0, $this->task($project->id, 'Opening Visit'), 'The task is unticked.');
        $this->assertSame(0, $this->notice($project->id, 'Visit Opening-O'), 'The reminder is back.');
        $this->assertSame(0, DB::table('kobo_visit_visitors')->where('kobo_submission_id', $submission)->whereNull('deleted_at')->count());
        $this->get('/ar/kobo/visits/'.$submission)->assertNotFound();
        // The opening can be entered again after the delete.
        $this->opening($project)->assertHasNoErrors();
    }

    public function test_visits_downloaded_by_the_desktop_stay_read_only_until_cut_over(): void
    {
        $this->as('role.administrator');
        $project = $this->project();
        $this->opening($project);
        $submission = (int) DB::table('kobo_submissions')->whereNull('kobo_submission_id')->value('id');
        DB::table('kobo_submissions')->where('id', $submission)->update(['kobo_submission_id' => '777001']);

        $this->get('/ar/kobo/visits/'.$submission)->assertOk()->assertSee(__('kobo.visit.synced_locked', [], 'ar'));
        $page = $this->page($project, self::OPENING, $submission)->set('date', '2026-02-09')->call('save');
        $page->assertHasErrors('visit');
        $this->page($project, self::OPENING, $submission)->call('delete')->assertHasErrors('visit');
        $this->assertSame('2026-02-01', substr((string) DB::table('visits')->where('project_id', $project->id)->value('visit_date'), 0, 10));

        config(['kobo.web_edit_synced' => true]);
        $this->page($project, self::OPENING, $submission)->set('date', '2026-02-09')->call('save')->assertHasNoErrors();
        $this->assertSame('2026-02-09', substr((string) DB::table('visits')->where('project_id', $project->id)->value('visit_date'), 0, 10));
        $this->page($project, self::OPENING, $submission)->call('delete')->assertHasNoErrors();
    }

    public function test_agriculture_stages_follow_the_previous_visit_and_plan_the_next_reminder(): void
    {
        $this->as('role.data-entry');
        $project = $this->project(0, 'other', 'مشاريع صغيرة');
        $achieved = $this->index(self::AGRI_MONITORING, 'production_data/production_achieved');
        $agri = fn (string $date, ?string $answer) => $this->complete($this->page($project, self::AGRI_MONITORING)->set('date', $date)->set('person', (string) $this->beneficiary($project))->set('visitors.0', (string) $this->visitor())
            ->set('a.'.$achieved, (string) $answer), self::AGRI_MONITORING, $answer === null ? ['a.'.$achieved] : []);

        $agri('2026-03-01', null)->assertHasErrors();
        $agri('2026-03-01', 'no')->assertHasNoErrors();
        $first = DB::table('kobo_monitoring_records')->where('project_id', $project->id)->first();
        $this->assertSame('1', $first->visit_number);
        $this->assertNotNull(DB::table('web_notices')->where('project_id', $project->id)->where('body', 'Visit M2-O')->where('notice_date', '2026-09-01')->where('seen', false)->first(), 'No production: the next visit is M2, six months later.');

        $agri('2026-09-02', 'yes')->assertHasNoErrors();
        $this->assertSame(['1', '2'], DB::table('me_visits')->where('project_id', $project->id)->whereNull('deleted_at')->orderBy('id')->pluck('visit_number')->all());
        $this->assertNotNull(DB::table('web_notices')->where('project_id', $project->id)->where('body', 'Visit MFinal-O')->where('notice_date', '2027-03-02')->first());
        $this->assertSame(1, (int) DB::table('web_notices')->where('project_id', $project->id)->where('body', 'Visit M2-O')->where('notice_date', '2026-09-01')->value('seen'), 'The M2 visit settled its own reminder.');
        // After M2 the next visit is the final one: the monitoring form refuses a third visit.
        $agri('2026-11-01', 'yes')->assertHasErrors('visit');
    }

    public function test_repeat_groups_are_stored_kobo_style_and_read_back(): void
    {
        $this->as('role.data-entry');
        $project = $this->project(0, 'other', 'مشاريع صغيرة');
        $crop = $this->index(self::AGRI_OPENING, 'general_info/land_inventory/crop_type');
        $area = $this->index(self::AGRI_OPENING, 'general_info/land_inventory/quantity_area');
        $page = $this->page($project, self::AGRI_OPENING)->set('date', '2026-02-01')->set('visitors.0', (string) $this->visitor());
        $page->call('addRow', 0)->set('r.0.0.'.$crop, 'قمح')->set('r.0.0.'.$area, '٢٥٠')->call('addRow', 0)->set('r.0.1.'.$crop, 'زيتون')->set('r.0.1.'.$area, '40');
        $this->complete($page, self::AGRI_OPENING)->assertHasNoErrors();
        $raw = json_decode((string) DB::table('kobo_submissions')->whereNull('kobo_submission_id')->where('form_uid', self::AGRI_OPENING)->value('raw_json'), true);
        $this->assertSame(['قمح', 'زيتون'], array_column($raw['general_info/land_inventory'], 'general_info/land_inventory/crop_type'));
        $this->assertSame(['250', '40'], array_column($raw['general_info/land_inventory'], 'general_info/land_inventory/quantity_area'));
        $this->assertSame(['قمح', 'زيتون'], KoboCatalog::lookup($raw, 'general_info/land_inventory/crop_type'));
        $submission = (int) DB::table('kobo_submissions')->whereNull('kobo_submission_id')->where('form_uid', self::AGRI_OPENING)->value('id');
        $this->page($project, self::AGRI_OPENING, $submission)->assertSet('r.0.1.'.$crop, 'زيتون');
        $this->get('/ar/kobo?open='.$submission)->assertOk();
        // The opening of a production project plans M1 six months later.
        $this->assertSame(0, (int) DB::table('web_notices')->where('project_id', $project->id)->where('body', 'Visit M1-O')->value('seen'), 'M1 stays planned six months after the scheduled opening.');
        $this->assertSame(1, (int) DB::table('web_notices')->where('project_id', $project->id)->where('body', 'Visit MFinal-O')->value('retired_by_kobo'), 'The later reminders are retired by the rule.');
    }

    public function test_roles_without_the_capability_and_other_cities_are_refused(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $this->opening($project);
        $submission = (int) DB::table('kobo_submissions')->whereNull('kobo_submission_id')->value('id');
        foreach (['role.financial', 'role.legal', 'role.guest'] as $user) {
            $this->as($user);
            $this->get('/ar/kobo/visits/new/'.$project->id)->assertForbidden();
            $this->get('/ar/kobo/visits/'.$submission)->assertForbidden();
        }
        $this->as('role.data-entry');
        $this->get('/ar/kobo/visits/new/99999999')->assertNotFound();
        $this->get('/ar/kobo/visits/99999999')->assertNotFound();
        // A single-city account does not reach another city's project.
        $other = DB::table('cities')->where('id', '<>', $project->city_id)->value('id');
        $foreign = DB::table('projects')->where('city_id', $other)->value('id');
        $account = WebAccount::where('username', 'role.data-entry')->first();
        DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', $other)->update(['revoked_at' => now()]);
        $this->get('/ar/kobo/visits/new/'.$foreign)->assertStatus(404);
    }

    /** The first flat question of a form that a single `${p} = 'v'` show-if hides, with the controlling select_one. */
    private function dependent(string $uid): array
    {
        $fields = KoboCatalog::forms()[$uid]['fields'];
        $byPath = array_column($fields, null, 'path');
        foreach ($fields as $i => $field) {
            $rule = KoboRules::for($uid, $field['path']);
            if (($rule['required'] ?? false) && empty($field['repeat']) && KoboCatalog::kind($field) === 'text' && count($rule['relevant'] ?? []) === 1
                && preg_match('/^\$\{([^}]+)\} = \'([^\']+)\'$/', $rule['relevant'][0], $m) === 1 && KoboCatalog::kind($byPath[$m[1]] ?? ['type' => '']) === 'select_one'
                && count($byPath[$m[1]]['choices']) > 1 && ($byPath[$m[1]]['repeat'] ?? false) === false) {
                $other = collect($byPath[$m[1]]['choices'])->first(fn ($c) => $c['code'] !== $m[2])['code'];

                return ['dep' => $i, 'control' => (int) array_search($m[1], array_column($fields, 'path'), true), 'on' => $m[2], 'off' => $other, 'label' => $field['label'], 'path' => $field['path']];
            }
        }
        $this->fail('No suitable dependent question in '.$uid);
    }

    public function test_xlsform_rules_hide_drop_and_require_questions(): void
    {
        $this->as('role.data-entry');
        $project = $this->project();
        $d = $this->dependent(self::MONITORING);
        $stage = $this->index(self::MONITORING, '__010');
        $base = fn () => $this->page($project, self::MONITORING)->set('date', '2026-07-15')->set('person', (string) $this->beneficiary($project))->set('visitors.0', (string) $this->visitor())->set('a.'.$stage, 'M&E_1');

        // Shown and required: refused with the required message, shown on the page.
        $page = $base()->set('a.'.$d['control'], $d['on'])->assertSee($d['label']);
        $this->complete($page, self::MONITORING, ['a.'.$d['dep']])->assertHasErrors('a.'.$d['dep']);
        $this->assertSame(__('kobo.errors.required'), $page->errors()->first('a.'.$d['dep']));

        // Hidden: the question disappears from the page, its answer is dropped and it is no longer required.
        $page = $base()->set('a.'.$d['control'], $d['off'])->set('a.'.$d['dep'], 'zzz-hidden')->assertDontSee($d['label']);
        $this->complete($page, self::MONITORING)->assertHasNoErrors();
        $raw = json_decode((string) DB::table('kobo_submissions')->whereNull('kobo_submission_id')->where('form_uid', self::MONITORING)->value('raw_json'), true);
        $this->assertArrayNotHasKey($d['path'], $raw);
        $this->assertSame($d['off'], $raw[KoboCatalog::forms()[self::MONITORING]['fields'][$d['control']]['path']]);

        // The device draft banner is on the page.
        $this->page($project, self::MONITORING)->assertSeeHtml('id="kobo-draft"');
    }

    public function test_the_rule_expressions_of_the_xlsforms(): void
    {
        $value = fn (array $answers) => fn (string $path) => (string) ($answers[$path] ?? '');
        $this->assertTrue(KoboRules::test("\${a} = 'x'", $value(['a' => 'x'])));
        $this->assertFalse(KoboRules::test("\${a} = 'x'", $value(['a' => 'y'])));
        $this->assertTrue(KoboRules::test("\${a} != 'x'", $value([])));
        $this->assertTrue(KoboRules::test("selected(\${m}, 'two')", $value(['m' => 'one two'])));
        $this->assertFalse(KoboRules::test("selected(\${m}, 'tw')", $value(['m' => 'one two'])));
        $this->assertTrue(KoboRules::test("\${a} = 'x' or \${b} = 'y' and \${c} = 'z'", $value(['a' => 'x'])), 'and binds tighter than or');
        $this->assertFalse(KoboRules::test("\${a} = 'q' or \${b} = 'y' and \${c} = 'z'", $value(['b' => 'y'])));
        $this->assertFalse(KoboRules::test("\${s2} != \${s1}", $value(['s1' => 'v', 's2' => 'v'])), 'the ranking constraint refuses the same choice twice');
        $this->assertTrue(KoboRules::test("\${s2} != \${s1}", $value(['s1' => 'v', 's2' => 'w'])));
        $this->assertTrue(KoboRules::test('not-a-supported-expression', $value([])), 'an unsupported expression never blocks');
        // Every expression of the 47 exported forms is inside the grammar (the exporter reports none outside it).
        foreach (KoboRules::all() as $uid => $paths) {
            foreach ($paths as $rule) {
                foreach ([...($rule['relevant'] ?? []), $rule['constraint'] ?? ''] as $expr) {
                    $left = preg_replace(['/\$\{[^}]+\}/', "/'[^']*'/", '/selected\(|\)|,|\band\b|\bor\b|!=|=|\s+/'], '', (string) $expr);
                    $this->assertSame('', $left, $uid.': '.$expr);
                }
            }
        }
    }
}
