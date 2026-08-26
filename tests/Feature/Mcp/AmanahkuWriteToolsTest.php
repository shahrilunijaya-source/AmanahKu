<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\AssignTaskTool;
use App\Mcp\Tools\ConfirmWriteTool;
use App\Mcp\Tools\CreateCardTool;
use App\Mcp\Tools\CreateExternalTotEventTool;
use App\Mcp\Tools\SaveTimesheetDraftTool;
use App\Models\Employee;
use App\Models\ExternalTotEvent;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Models\WorkItem;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Coverage for the MCP server's write tools (routes/ai.php: POST /mcp/amanahku):
 * the two-step preview/confirm flow (App\Mcp\PendingWrite, ConfirmWriteTool) and
 * each write tool's own authorization, sitting on top of BoardRules and WeekWriter.
 */
class AmanahkuWriteToolsTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = '2026-08-03'; // a Monday

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $hrA;

    private User $managerA;

    private User $staffA;

    private Employee $staffEmpA;

    private Employee $otherEmpA;

    private Project $projectA;

    private TimesheetCategory $categoryA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        $this->tenantB = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BE']);

        app(CurrentTenant::class)->set($this->tenantA);

        $this->hrA = User::create(['name' => 'HR Ann', 'email' => 'hr.a@example.com', 'password' => Hash::make('password')]);
        $this->hrA->tenants()->attach($this->tenantA->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $this->tenantA->id, 'user_id' => $this->hrA->id, 'name' => 'HR Ann', 'status' => 'active', 'workload' => 'green']);

        $this->managerA = User::create(['name' => 'Manager Mia', 'email' => 'mgr.a@example.com', 'password' => Hash::make('password')]);
        $this->managerA->tenants()->attach($this->tenantA->id, ['role' => 'manager']);
        Employee::create(['tenant_id' => $this->tenantA->id, 'user_id' => $this->managerA->id, 'name' => 'Manager Mia', 'status' => 'active', 'workload' => 'green']);

        $this->staffA = User::create(['name' => 'Staff Sam', 'email' => 'staff.a@example.com', 'password' => Hash::make('password')]);
        $this->staffA->tenants()->attach($this->tenantA->id, ['role' => 'employee']);
        $this->staffEmpA = Employee::create(['tenant_id' => $this->tenantA->id, 'user_id' => $this->staffA->id, 'name' => 'Staff Sam', 'status' => 'active', 'workload' => 'green']);

        $this->otherEmpA = Employee::create(['tenant_id' => $this->tenantA->id, 'name' => 'Other Omar', 'status' => 'active', 'workload' => 'green']);

        $this->projectA = Project::create(['tenant_id' => $this->tenantA->id, 'code' => 'KPT', 'name' => 'KPT: RMS', 'is_active' => true]);
        $this->categoryA = TimesheetCategory::create(['tenant_id' => $this->tenantA->id, 'name' => 'Project Work', 'requires_project' => true, 'is_active' => true]);

        app(CurrentTenant::class)->set($this->tenantB);
        $hrB = User::create(['name' => 'HR Bea', 'email' => 'hr.b@example.com', 'password' => Hash::make('password')]);
        $hrB->tenants()->attach($this->tenantB->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $this->tenantB->id, 'user_id' => $hrB->id, 'name' => 'HR Bea', 'status' => 'active', 'workload' => 'green']);

        app(CurrentTenant::class)->set(null);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    // --- helpers -----------------------------------------------------------

    private function bearer(User $user, Tenant $tenant, array $abilities = ['*']): array
    {
        $token = $user->mintApiToken($tenant, 'test', $abilities);

        return ['Authorization' => 'Bearer '.$token->plainTextToken];
    }

    private function callTool(string $toolClass, array $arguments, array $headers = []): TestResponse
    {
        $name = app($toolClass)->name();

        // Sanctum's guard caches the resolved user on first use; without forgetting it
        // here, a second call in the same test with a DIFFERENT bearer token would keep
        // resolving to the FIRST call's user (a real Laravel testing gotcha — never an
        // issue outside tests, since each real request is its own PHP process).
        Auth::forgetGuards();

        return $this->postJson('/mcp/amanahku', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], $headers);
    }

    private function confirm(string $token, array $headers): TestResponse
    {
        return $this->callTool(ConfirmWriteTool::class, ['token' => $token], $headers);
    }

    private function toolData(TestResponse $response): array
    {
        $text = $response->json('result.content.0.text');

        return json_decode($text, true) ?? [];
    }

    private function toolIsError(TestResponse $response): bool
    {
        return (bool) $response->json('result.isError');
    }

    // --- create_card: preview writes nothing, confirm applies --------------

    public function test_preview_mints_a_token_and_writes_nothing(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['board:write']);

        $response = $this->callTool(CreateCardTool::class, [
            'title' => 'New card', 'type' => 'task', 'priority' => 'medium',
        ], $headers);

        $this->assertFalse($this->toolIsError($response));
        $data = $this->toolData($response);
        $this->assertArrayHasKey('confirm_token', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('changes', $data);

        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame(0, WorkItem::count());
        app(CurrentTenant::class)->set(null);
    }

    public function test_confirm_applies_the_previewed_change(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['board:write']);

        $preview = $this->callTool(CreateCardTool::class, [
            'title' => 'New card', 'type' => 'task', 'priority' => 'medium',
        ], $headers);
        $token = $this->toolData($preview)['confirm_token'];

        $response = $this->confirm($token, $headers);

        $this->assertFalse($this->toolIsError($response));
        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame(1, WorkItem::count());
        $this->assertSame('New card', WorkItem::first()->title);
        app(CurrentTenant::class)->set(null);
    }

    public function test_a_reused_token_is_refused(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['board:write']);

        $preview = $this->callTool(CreateCardTool::class, [
            'title' => 'New card', 'type' => 'task', 'priority' => 'medium',
        ], $headers);
        $token = $this->toolData($preview)['confirm_token'];

        $this->confirm($token, $headers);
        $second = $this->confirm($token, $headers);

        $this->assertTrue($this->toolIsError($second));
        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame(1, WorkItem::count());
        app(CurrentTenant::class)->set(null);
    }

    public function test_an_expired_token_is_refused(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['board:write']);

        $preview = $this->callTool(CreateCardTool::class, [
            'title' => 'New card', 'type' => 'task', 'priority' => 'medium',
        ], $headers);
        $token = $this->toolData($preview)['confirm_token'];

        $this->travel(11)->minutes();

        $response = $this->confirm($token, $headers);

        $this->assertTrue($this->toolIsError($response));
        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame(0, WorkItem::count());
        app(CurrentTenant::class)->set(null);
    }

    public function test_a_different_users_token_is_refused_at_confirm(): void
    {
        $headersA = $this->bearer($this->staffA, $this->tenantA, ['board:write']);
        $headersOther = $this->bearer($this->hrA, $this->tenantA, ['board:write']);

        $preview = $this->callTool(CreateCardTool::class, [
            'title' => 'New card', 'type' => 'task', 'priority' => 'medium',
        ], $headersA);
        $token = $this->toolData($preview)['confirm_token'];

        $response = $this->confirm($token, $headersOther);

        $this->assertTrue($this->toolIsError($response));
        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame(0, WorkItem::count());
        app(CurrentTenant::class)->set(null);
    }

    public function test_a_token_stashed_in_one_tenant_is_refused_when_confirmed_in_another(): void
    {
        // Same physical user, but a different tenant-bound token (mintApiToken requires
        // membership, so use the HR-Bea style setup: stash under tenant A, confirm under B).
        $headersA = $this->bearer($this->staffA, $this->tenantA, ['board:write']);

        $preview = $this->callTool(CreateCardTool::class, [
            'title' => 'New card', 'type' => 'task', 'priority' => 'medium',
        ], $headersA);
        $token = $this->toolData($preview)['confirm_token'];

        // staffA is not a member of tenantB, so mint a tenantB token for hrB instead, and
        // confirm staffA's stashed token from a request bound to tenant B.
        app(CurrentTenant::class)->set($this->tenantB);
        $hrB = User::where('email', 'hr.b@example.com')->first();
        app(CurrentTenant::class)->set(null);
        $headersB = $this->bearer($hrB, $this->tenantB, ['board:write']);

        $response = $this->confirm($token, $headersB);

        $this->assertTrue($this->toolIsError($response));
    }

    public function test_a_read_only_key_is_refused_at_both_preview_and_confirm(): void
    {
        $readOnly = $this->bearer($this->staffA, $this->tenantA, ['board:read']);

        $preview = $this->callTool(CreateCardTool::class, [
            'title' => 'New card', 'type' => 'task', 'priority' => 'medium',
        ], $readOnly);
        $this->assertTrue($this->toolIsError($preview));
        $this->assertStringContainsString('board:write', $preview->json('result.content.0.text'));

        // Prove the scope re-check at confirm time is real, not just dead code: the
        // SAME user previews with a board:write token (so consume() matches on user +
        // tenant and succeeds), then confirms with a SECOND token for themselves that
        // only carries board:read. The scope gate — not the token match — must be what
        // refuses this.
        $writeKey = $this->bearer($this->staffA, $this->tenantA, ['board:write']);
        $validPreview = $this->callTool(CreateCardTool::class, [
            'title' => 'Another card', 'type' => 'task', 'priority' => 'medium',
        ], $writeKey);
        $token = $this->toolData($validPreview)['confirm_token'];

        $confirmWithReadOnly = $this->confirm($token, $readOnly);
        $this->assertTrue($this->toolIsError($confirmWithReadOnly));
        $this->assertStringContainsString('board:write', $confirmWithReadOnly->json('result.content.0.text'));

        // PendingWrite::consume() matches on user + tenant only (it has no notion of
        // scope), so it is single-use from the moment ANY request authenticated as the
        // right user redeems it — the scope check runs after, and a scope failure still
        // burns the token. Nothing was ever created, whichever key was used.
        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame(0, WorkItem::where('title', 'Another card')->count());
        app(CurrentTenant::class)->set(null);
    }

    public function test_create_card_is_tenant_isolated_via_the_scope_check(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['board:write']);

        $preview = $this->callTool(CreateCardTool::class, [
            'title' => 'Alpha-only card', 'type' => 'task', 'priority' => 'medium',
        ], $headers);
        $token = $this->toolData($preview)['confirm_token'];

        $this->confirm($token, $headers);

        app(CurrentTenant::class)->set($this->tenantB);
        $this->assertSame(0, WorkItem::count());
        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame(1, WorkItem::count());
        app(CurrentTenant::class)->set(null);
    }

    // --- assign_task: role gate + notify wording ----------------------------

    public function test_plain_employee_cannot_assign_a_task(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['board:write']);

        $response = $this->callTool(AssignTaskTool::class, [
            'employee_id' => $this->otherEmpA->id, 'title' => 'Do it', 'type' => 'adhoc',
            'priority' => 'high', 'due_at' => '2026-08-10',
        ], $headers);

        $this->assertTrue($this->toolIsError($response));
        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame(0, WorkItem::count());
        app(CurrentTenant::class)->set(null);
    }

    public function test_manager_can_assign_a_task_and_preview_names_the_notification(): void
    {
        $headers = $this->bearer($this->managerA, $this->tenantA, ['board:write']);

        $preview = $this->callTool(AssignTaskTool::class, [
            'employee_id' => $this->otherEmpA->id, 'title' => 'Do it', 'type' => 'adhoc',
            'priority' => 'high', 'due_at' => '2026-08-10',
        ], $headers);

        $this->assertFalse($this->toolIsError($preview));
        $data = $this->toolData($preview);
        $this->assertStringContainsString('Other Omar', $data['summary']);
        $this->assertStringContainsString('email', $data['summary']);

        $confirm = $this->confirm($data['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $card = WorkItem::first();
        $this->assertNotNull($card);
        $this->assertSame($this->otherEmpA->id, $card->employee_id);
        $this->assertSame('2026-08-10', $card->due_at->toDateString());
        app(CurrentTenant::class)->set(null);
    }

    public function test_assigning_to_an_archived_employee_is_refused(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        $this->otherEmpA->update(['status' => 'archived', 'archived_at' => now()]);
        app(CurrentTenant::class)->set(null);

        $headers = $this->bearer($this->managerA, $this->tenantA, ['board:write']);

        $response = $this->callTool(AssignTaskTool::class, [
            'employee_id' => $this->otherEmpA->id, 'title' => 'Do it', 'type' => 'adhoc',
            'priority' => 'high', 'due_at' => '2026-08-10',
        ], $headers);

        $this->assertTrue($this->toolIsError($response));
    }

    // --- create_external_tot_event: role gate + never tags anyone ----------

    public function test_plain_employee_cannot_post_an_external_tot_event(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['tot:write']);

        $response = $this->callTool(CreateExternalTotEventTool::class, [
            'title' => 'Conference', 'event_date' => '2026-09-01',
        ], $headers);

        $this->assertTrue($this->toolIsError($response));
    }

    public function test_external_tot_event_is_created_with_no_tagged_employees(): void
    {
        $headers = $this->bearer($this->hrA, $this->tenantA, ['tot:write']);

        $preview = $this->callTool(CreateExternalTotEventTool::class, [
            'title' => 'Conference', 'event_date' => '2026-09-01', 'description' => 'Feat @Other Omar',
        ], $headers);

        $this->assertFalse($this->toolIsError($preview));
        $data = $this->toolData($preview);
        $this->assertSame([], $data['changes']['tagged_employee_ids']);

        $confirm = $this->confirm($data['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $event = ExternalTotEvent::first();
        $this->assertNotNull($event);
        $this->assertSame([], $event->tagged_employee_ids);
        app(CurrentTenant::class)->set(null);
    }

    // --- save_timesheet_draft: the merge test -------------------------------

    public function test_saving_one_day_leaves_the_rest_of_the_draft_week_untouched(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenantA->id, 'employee_id' => $this->staffEmpA->id,
            'week_start' => self::WEEK, 'status' => 'draft',
        ]);
        $days = ['Mon' => 0, 'Tue' => 1, 'Wed' => 2, 'Thu' => 3, 'Fri' => 4];
        foreach ($days as $offset) {
            TimesheetEntry::create([
                'tenant_id' => $this->tenantA->id, 'timesheet_id' => $timesheet->id,
                'entry_date' => date('Y-m-d', strtotime(self::WEEK.' +'.$offset.' day')),
                'category_id' => $this->categoryA->id, 'project_id' => $this->projectA->id,
                'percentage' => 100, 'hours' => 8,
            ]);
        }
        app(CurrentTenant::class)->set(null);

        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);
        $tuesday = date('Y-m-d', strtotime(self::WEEK.' +1 day'));

        $preview = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => $tuesday,
                'category_id' => $this->categoryA->id,
                'project_id' => $this->projectA->id,
                'percentage' => 50,
            ]],
        ], $headers);

        $this->assertFalse($this->toolIsError($preview));
        $data = $this->toolData($preview);
        $resulting = $data['changes']['resulting_week'];
        // Five days present, Tuesday changed, the other four untouched at 100%.
        $this->assertCount(5, $resulting);
        $this->assertEquals(50.0, $resulting[$tuesday][0]['percentage']);
        $monday = self::WEEK;
        $this->assertEquals(100.0, $resulting[$monday][0]['percentage']);
        $friday = date('Y-m-d', strtotime(self::WEEK.' +4 day'));
        $this->assertEquals(100.0, $resulting[$friday][0]['percentage']);

        $confirm = $this->confirm($data['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $timesheet->refresh();
        $byDate = $timesheet->entries()->whereNull('source')->get()->keyBy(fn (TimesheetEntry $e) => $e->entry_date->toDateString());
        $this->assertCount(5, $byDate);
        $this->assertEquals(50.0, (float) $byDate[$tuesday]->percentage);
        $this->assertEquals(100.0, (float) $byDate[$monday]->percentage);
        $this->assertEquals(100.0, (float) $byDate[$friday]->percentage);
        app(CurrentTenant::class)->set(null);
    }

    public function test_save_timesheet_draft_refuses_a_submitted_week(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        Timesheet::create([
            'tenant_id' => $this->tenantA->id, 'employee_id' => $this->staffEmpA->id,
            'week_start' => self::WEEK, 'status' => 'submitted',
        ]);
        app(CurrentTenant::class)->set(null);

        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);

        $response = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK,
                'category_id' => $this->categoryA->id,
                'project_id' => $this->projectA->id,
                'percentage' => 50,
            ]],
        ], $headers);

        $this->assertTrue($this->toolIsError($response));
    }

    // --- confirm_write throttle ---------------------------------------------

    public function test_confirm_write_is_throttled_tighter_than_the_route(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['board:write']);

        // 20 confirmed writes is the budget (ConfirmWriteTool::WRITE_LIMIT_PER_MINUTE);
        // the 21st in the same minute must be refused even though the route-level
        // throttle:60,1 has room to spare.
        for ($i = 0; $i < 20; $i++) {
            $preview = $this->callTool(CreateCardTool::class, [
                'title' => "Card {$i}", 'type' => 'task', 'priority' => 'medium',
            ], $headers);
            $token = $this->toolData($preview)['confirm_token'];
            $confirm = $this->confirm($token, $headers);
            $this->assertFalse($this->toolIsError($confirm), "Write {$i} should not be throttled yet.");
        }

        $preview = $this->callTool(CreateCardTool::class, [
            'title' => 'One too many', 'type' => 'task', 'priority' => 'medium',
        ], $headers);
        $token = $this->toolData($preview)['confirm_token'];
        $response = $this->confirm($token, $headers);

        $this->assertTrue($this->toolIsError($response));
        $this->assertStringContainsString('Too many writes', $response->json('result.content.0.text'));

        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame(20, WorkItem::count());
        app(CurrentTenant::class)->set(null);
    }

    public function test_save_timesheet_draft_is_refused_for_a_read_only_key(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:read']);

        $response = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK,
                'category_id' => $this->categoryA->id,
                'project_id' => $this->projectA->id,
                'percentage' => 50,
            ]],
        ], $headers);

        $this->assertTrue($this->toolIsError($response));
        $this->assertStringContainsString('timesheets:write', $response->json('result.content.0.text'));
    }
}
