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

    public function test_every_project_state_has_a_stage_and_stopped_states_stop_at_the_decision(): void
    {
        $states = DB::table('project_states')->pluck('name_en')->filter();
        if ($states->isEmpty()) {
            $this->seed(DatabaseSeeder::class);
            $states = DB::table('project_states')->pluck('name_en')->filter();
        }

        foreach ($states as $state) {
            $this->assertContains(ProjectStatus::stage($state), ProjectStatus::STAGES, "No stage for state {$state}");
        }
        foreach (['Rejected', 'Withdrew', 'Canceled'] as $state) {
            $this->assertTrue(ProjectStatus::stopped($state));
            $this->assertSame('danger', ProjectStatus::tone($state));
            $this->assertSame('decision', ProjectStatus::stage($state));
        }
        $this->assertSame('ok', ProjectStatus::tone('Financed'));
        $this->assertSame('implementation', ProjectStatus::stage('Financed'));
        $this->assertFalse(ProjectStatus::stopped('Financed'));
    }

    public function test_a_coloured_status_always_carries_an_icon_and_its_word(): void
    {
        foreach (['ok' => 'check', 'warn' => 'clock', 'info' => 'flag', 'danger' => 'x'] as $tone => $icon) {
            $html = Blade::render('<x-hc.badge :tone="$tone">كلمة</x-hc.badge>', ['tone' => $tone]);
            $this->assertStringContainsString('has-icon', $html);
            $this->assertStringContainsString('data-icon="'.$icon.'"', $html);
            $this->assertStringContainsString('كلمة', $html);
        }
    }

    public function test_the_stage_bar_marks_done_current_and_stopped_steps_for_screen_readers(): void
    {
        app()->setLocale('ar');
        $html = Blade::render('<x-hc.stage-bar current="assessment" note="قيد الدراسة"/>');
        $this->assertSame(1, substr_count($html, 'is-done'));
        $this->assertSame(1, substr_count($html, 'aria-current="step"'));
        $this->assertStringContainsString('قيد الدراسة', $html);
        $this->assertStringContainsString(__('hc.path_state_now'), $html);

        $stopped = Blade::render('<x-hc.stage-bar current="decision" :stopped="true" note="مرفوض"/>');
        $this->assertStringContainsString('is-stopped', $stopped);
        $this->assertStringContainsString(__('hc.path_state_stopped'), $stopped);
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
        $this->get("/en/projects/{$project}")->assertOk()->assertSee('Decision')->assertSee('Rejected');
    }
}
