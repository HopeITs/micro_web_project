<?php

namespace Tests\Feature;

use App\Livewire\TaskBoard;
use App\Mail\StaffMessage;
use App\Models\WebAccount;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M4.16 user scenarios: the administrator opens the board and ticks any cell; a task holder sees "my tasks" and ticks only what is theirs;
 * a stale click is refused; finishing the purchases notifies the communication staff and reopening clears it; a message to a colleague
 * lands in their box with an e-mail copy and is marked seen; accounts without the capability are refused; the grid exports.
 */
class TaskBoardScenarioTest extends TestCase
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

    private function task(string $nameAr): int
    {
        return (int) DB::table('task_definitions')->where('name_ar', $nameAr)->value('id');
    }

    /** A financed project with an open "story" task and an open "purchases" task. */
    private function project(): object
    {
        $financed = (int) DB::table('project_states')->where('name_en', 'Financed')->value('id');
        $project = DB::table('projects as p')->join('project_state_history as h', fn ($j) => $j->on('h.project_id', '=', 'p.id')->where('h.is_current', true))
            ->where('h.project_state_id', $financed)->orderBy('p.id')->first(['p.*']);
        $this->assertNotNull($project);
        foreach (['القصة انكليزي', 'مشتريات'] as $name) {
            DB::table('project_tasks')->updateOrInsert(['project_id' => $project->id, 'task_definition_id' => $this->task($name)],
                ['city_id' => $project->city_id, 'state' => 0, 'task_date' => null, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null]);
        }

        return $project;
    }

    private function cell(object $project, string $name): object
    {
        return DB::table('project_tasks')->where('project_id', $project->id)->where('task_definition_id', $this->task($name))->first();
    }

    public function test_administrator_opens_the_board_and_ticks_a_cell(): void
    {
        $this->as('role.administrator');
        $project = $this->project();
        $this->get('/ar/task-board')->assertOk()->assertSeeLivewire(TaskBoard::class);
        $this->get('/en/task-board')->assertOk()->assertSee('Task board');

        $cell = $this->cell($project, 'القصة انكليزي');
        Livewire::withQueryParams(['view' => 'grid'])->test(TaskBoard::class)->assertSee('القصة انكليزي')
            ->call('toggle', $cell->id, false)->assertSee(__('staff.tasks.saved'));
        $this->assertSame(1, (int) DB::table('project_tasks')->where('id', $cell->id)->value('state'));
        $this->assertTrue(DB::table('account_audit_events')->where('event_type', 'tasks.toggled')->where('subject_id', $project->id)->exists());

        // The page showed "open" but the cell is already done: the stale click is refused and nothing changes.
        Livewire::test(TaskBoard::class)->call('toggle', $cell->id, false)->assertStatus(409);
        $this->assertSame(1, (int) DB::table('project_tasks')->where('id', $cell->id)->value('state'));
    }

    public function test_task_holder_sees_my_tasks_and_ticks_only_their_own(): void
    {
        $me = $this->as('role.data-entry');
        $project = $this->project();
        $story = $this->cell($project, 'القصة انكليزي');
        $purchases = $this->cell($project, 'مشتريات');
        DB::table('account_city_tasks')->insert(['account_id' => $me->id, 'city_id' => $project->city_id, 'task_definition_id' => $this->task('القصة انكليزي'), 'assigned_at' => now()]);

        $page = Livewire::test(TaskBoard::class)->assertSee('القصة انكليزي')->assertDontSee('مشتريات');
        $page->call('toggle', $story->id, false)->assertSee(__('staff.tasks.saved'));
        $this->assertSame(1, (int) DB::table('project_tasks')->where('id', $story->id)->value('state'));

        // Not responsible for the purchases: refused and audited.
        Livewire::test(TaskBoard::class)->call('toggle', $purchases->id, false)->assertStatus(403);
        $this->assertSame(0, (int) DB::table('project_tasks')->where('id', $purchases->id)->value('state'));
    }

    public function test_finishing_the_purchases_notifies_communication_and_reopening_clears_it(): void
    {
        $this->as('role.administrator');
        $project = $this->project();
        $purchases = $this->cell($project, 'مشتريات');
        $communication = WebAccount::where('username', 'role.communication')->value('id');

        Livewire::test(TaskBoard::class)->call('toggle', $purchases->id, false);
        $notice = DB::table('web_notices')->where('project_id', $project->id)->where('kind', 'purchases')->where('account_id', $communication)->first();
        $this->assertNotNull($notice);
        $this->assertSame(0, (int) $notice->seen);

        $this->as('role.communication');
        $this->get('/ar/notifications')->assertOk()->assertSee(__('staff.notifications.kinds.purchases', [], 'ar'));
        $this->get('/ar/notifications?kind=visit')->assertOk()->assertSee(__('staff.notifications.empty', [], 'ar'));

        $this->as('role.administrator');
        Livewire::test(TaskBoard::class)->call('toggle', $purchases->id, true);
        $this->assertSame(1, (int) DB::table('web_notices')->where('id', $notice->id)->value('seen'));
    }

    public function test_a_message_lands_in_the_colleagues_box_with_an_email_copy(): void
    {
        Mail::fake();
        $admin = $this->as('role.administrator');
        $city = (int) DB::table('account_city_capabilities')->where('account_id', $admin->id)->where('capability', 'notifications.send')->whereNull('revoked_at')->value('city_id');
        $colleague = WebAccount::where('username', 'role.communication')->first();

        $this->get('/ar/notifications/send?city='.$city)->assertOk()->assertSee('role.communication');
        $this->post('/ar/notifications/send', ['city' => $city, 'to' => (string) $colleague->id, 'title' => 'اجتماع', 'body' => 'الاجتماع غداً الساعة العاشرة', 'email_copy' => '1'])->assertRedirect();
        $notice = DB::table('web_notices')->where('account_id', $colleague->id)->where('kind', 'message')->first();
        $this->assertNotNull($notice);
        $this->assertSame($admin->id, (int) $notice->sender_account_id);
        Mail::assertSent(StaffMessage::class, fn ($mail) => $mail->hasTo((string) $colleague->email) && $mail->body === 'الاجتماع غداً الساعة العاشرة');

        // Without the box ticked there is no copy.
        $this->post('/ar/notifications/send', ['city' => $city, 'to' => (string) $colleague->id, 'body' => 'بدون نسخة'])->assertRedirect();
        Mail::assertSentCount(1);

        // The colleague reads it, then marks it seen.
        $this->as('role.communication');
        $this->get('/ar/notifications')->assertOk()->assertSee('الاجتماع غداً الساعة العاشرة')->assertSee('role.administrator');
        $this->post('/ar/notifications/seen', ['ids' => [$notice->id]])->assertRedirect();
        $this->assertSame(1, (int) DB::table('web_notices')->where('id', $notice->id)->value('seen'));
        // Someone without the send capability cannot open the form.
        $this->get('/ar/notifications/send')->assertForbidden();
    }

    public function test_the_grid_exports_and_unauthorised_accounts_are_refused(): void
    {
        $this->as('role.administrator');
        $this->project();
        $this->get('/ar/task-board/export?kind=f&format=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->get('/ar/notifications/export?format=csv')->assertOk();

        $this->as('role.guest');
        $this->get('/ar/task-board')->assertForbidden();
        $this->get('/ar/task-board/export')->assertForbidden();
    }
}
