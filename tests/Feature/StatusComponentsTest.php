<?php

namespace Tests\Feature;

use App\Support\ProjectStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * U1 semantic status (colour + icon + word) and the F2/F3 shared components
 * (docs/planning/ux-borrowed-features-2026-11.md), as a data-entry user sees them.
 */
class StatusComponentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_path_follows_the_owners_sequence(): void
    {
        $this->seed(DatabaseSeeder::class);
        foreach (DB::table('project_states')->pluck('name_en')->filter() as $state) {
            $this->assertNotNull(ProjectStatus::tone($state) ?? ProjectStatus::icon($state) ?? ProjectStatus::mode($state), "Unknown state {$state}");
            $this->assertContains(ProjectStatus::path($state, ['Under Review'])['current'], ProjectStatus::STAGES, "No stage for state {$state}");
        }

        // stage, mode, tone as described by the owner on 20 Nov 2026
        $expected = [
            'On Hold' => ['intake', 'now', null], 'Waiting' => ['intake', 'now', 'warn'],
            'Under Review' => ['assessment', 'now', 'warn'],
            'Delayed' => ['decision', 'paused', 'warn'], 'Rejected' => ['decision', 'stopped', 'danger'],
            'Accepted' => ['funding', 'now', 'info'],
            'Financed' => ['followup', 'now', 'ok'], 'Canceled' => ['followup', 'stopped', 'danger'],
            'Finished' => ['closing', 'complete', 'ok'], 'Closed' => ['closing', 'complete', null],
        ];
        foreach ($expected as $state => [$stage, $mode, $tone]) {
            $this->assertSame(['current' => $stage, 'mode' => $mode, 'tone' => $tone], ProjectStatus::path($state), $state);
        }

        // Withdrew: grey, stops where the application had got to (never past the decision).
        $this->assertSame(['current' => 'assessment', 'mode' => 'withdrawn', 'tone' => null], ProjectStatus::path('Withdrew', ['On Hold', 'Under Review']));
        $this->assertSame('intake', ProjectStatus::path('Withdrew', ['On Hold'])['current']);
        $this->assertSame('intake', ProjectStatus::path('Withdrew')['current']);
        $this->assertSame('decision', ProjectStatus::path('Withdrew', ['Accepted'])['current']);
    }

    public function test_a_coloured_status_always_carries_an_icon_and_its_word(): void
    {
        foreach (['ok' => 'check', 'warn' => 'clock', 'info' => 'flag', 'danger' => 'x'] as $tone => $icon) {
            $html = Blade::render('<x-hc.badge :tone="$tone">كلمة</x-hc.badge>', ['tone' => $tone]);
            $this->assertStringContainsString('has-icon', $html);
            $this->assertStringContainsString('data-icon="'.$icon.'"', $html);
            $this->assertStringContainsString('كلمة', $html);
        }
        foreach (['Delayed' => 'pause', 'Withdrew' => 'x', 'Financed' => 'wallet', 'Closed' => 'flag'] as $state => $icon) {
            $html = Blade::render('<x-hc.badge :tone="$t" :icon="$i">كلمة</x-hc.badge>', ['t' => ProjectStatus::tone($state), 'i' => ProjectStatus::icon($state)]);
            $this->assertStringContainsString('data-icon="'.$icon.'"', $html, $state);
        }
    }

    public function test_the_stage_bar_shows_each_mode_for_screen_readers(): void
    {
        app()->setLocale('ar');
        $html = Blade::render('<x-hc.stage-bar current="assessment" tone="warn" note="قيد الدراسة"/>');
        $this->assertSame(1, substr_count($html, 'class="hc-stage is-done'));
        $this->assertSame(1, substr_count($html, 'aria-current="step"'));
        $this->assertStringContainsString('hc-tone-warn', $html);
        $this->assertStringContainsString(__('hc.path_state_now'), $html);

        foreach (['paused' => 'path_state_paused', 'stopped' => 'path_state_stopped', 'withdrawn' => 'path_state_withdrawn', 'complete' => 'path_state_complete'] as $mode => $key) {
            $bar = Blade::render('<x-hc.stage-bar current="decision" :mode="$m" note="x"/>', ['m' => $mode]);
            $this->assertStringContainsString('hc-stages is-'.$mode, $bar);
            $this->assertStringContainsString(__('hc.'.$key), $bar, $mode);
        }
        $complete = Blade::render('<x-hc.stage-bar current="closing" mode="complete" note="منتهي"/>');
        $this->assertSame(6, substr_count($complete, 'class="hc-stage is-done'));
        $this->assertStringContainsString('متابعة', $complete);
    }

    public function test_count_tiles_and_chips_say_which_filter_is_on(): void
    {
        $tile = Blade::render('<x-hc.count-tile :count="1200" label="ممول" tone="ok" :active="true"/>');
        $this->assertStringContainsString('aria-pressed="true"', $tile);
        $this->assertStringContainsString('1,200', $tile);
        $chip = Blade::render('<x-hc.chip :count="50">أولي</x-hc.chip>');
        $this->assertStringContainsString('aria-pressed="false"', $chip);
    }

    public function test_a_data_entry_user_sees_the_path_on_a_project_page(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->post('/ar/login', ['username' => 'role.data-entry', 'password' => 'password'])->assertRedirect('/ar');
        $project = DB::table('project_state_history as h')->join('project_states as s', 's.id', '=', 'h.project_state_id')
            ->where('h.is_current', true)->where('s.name_en', 'Rejected')->value('h.project_id');

        $this->get("/ar/projects/{$project}")->assertOk()
            ->assertSee('hc-stages is-stopped', false)
            ->assertSee(__('hc.path_title', [], 'ar'))
            ->assertSee('hc-badge hc-badge-danger has-icon', false);
        $this->get("/en/projects/{$project}")->assertOk()->assertSee('Decision')->assertSee('Follow-up')->assertSee('Rejected');
    }
}
