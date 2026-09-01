<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\AssignTaskTool;
use App\Mcp\Tools\ConfirmWriteTool;
use App\Mcp\Tools\CreateCardTool;
use App\Mcp\Tools\CreateExternalTotEventTool;
use App\Mcp\Tools\SaveTimesheetDraftTool;
use App\Mcp\Tools\UpdateCardTool;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\Project;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\TotSession;
use App\Models\User;
use App\Models\WorkItem;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        return $this->callTool(ConfirmWriteTool::class, ['confirm_token' => $token], $headers);
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

    /**
     * A board card owned by $employee (defaults to staffEmpA), carrying the given
     * category/project so it is ready for save_timesheet_draft to log against.
     * Board-first (commit 84dc9cf): a timesheet row names one of these, it never
     * carries its own category/project.
     *
     * -1 (not null) is "use the default" for $categoryId/$projectId, since null is
     * itself a meaningful value here (an uncategorised card, or one with no project) —
     * `??` would silently replace an intentional null with the default.
     */
    private function card(?int $categoryId = -1, ?int $projectId = -1, ?Employee $employee = null): WorkItem
    {
        app(CurrentTenant::class)->set($this->tenantA);
        $item = WorkItem::create([
            'tenant_id' => $this->tenantA->id,
            'employee_id' => ($employee ?? $this->staffEmpA)->id,
            'title' => 'Card '.WorkItem::count(),
            'type' => 'task',
            'priority' => 'medium',
            'status' => 'prog',
            'timesheet_category_id' => $categoryId === -1 ? $this->categoryA->id : $categoryId,
            'project_id' => $projectId === -1 ? $this->projectA->id : $projectId,
        ]);
        app(CurrentTenant::class)->set(null);

        return $item;
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

    /**
     * timesheet_category_id sticks on create — without it a card never turns up on
     * the timesheet screen (BoardSuggestions::categoryFor() holds an uncategorised
     * card's rows back).
     */
    public function test_create_card_accepts_timesheet_category_id(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['board:write']);

        $preview = $this->callTool(CreateCardTool::class, [
            'title' => 'Categorised card', 'type' => 'task', 'priority' => 'medium',
            'timesheet_category_id' => $this->categoryA->id, 'project_id' => $this->projectA->id,
        ], $headers);
        $this->assertFalse($this->toolIsError($preview));

        $confirm = $this->confirm($this->toolData($preview)['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $card = WorkItem::where('title', 'Categorised card')->first();
        $this->assertSame($this->categoryA->id, $card->timesheet_category_id);
        $this->assertSame($this->projectA->id, $card->project_id);
        app(CurrentTenant::class)->set(null);
    }

    /**
     * The same pairing guard WorkItemController runs (commit 9558b39): a project the
     * chosen category does not offer never sticks, however the card was created. A
     * project tagged to a DIFFERENT category only is not offered under categoryA, so
     * it must be dropped rather than silently paired with a category it disagrees with.
     */
    public function test_create_card_drops_a_project_the_chosen_category_does_not_allow(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        $otherCategory = TimesheetCategory::create([
            'tenant_id' => $this->tenantA->id, 'name' => 'Other Delivery', 'requires_project' => true, 'is_active' => true,
        ]);
        $taggedProject = Project::create(['tenant_id' => $this->tenantA->id, 'code' => 'TAG', 'name' => 'Tagged Project', 'is_active' => true]);
        $taggedProject->categories()->attach($otherCategory->id);
        app(CurrentTenant::class)->set(null);

        $headers = $this->bearer($this->staffA, $this->tenantA, ['board:write']);

        $preview = $this->callTool(CreateCardTool::class, [
            'title' => 'Mismatched card', 'type' => 'task', 'priority' => 'medium',
            'timesheet_category_id' => $this->categoryA->id, 'project_id' => $taggedProject->id,
        ], $headers);
        $this->assertFalse($this->toolIsError($preview));

        $confirm = $this->confirm($this->toolData($preview)['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $card = WorkItem::where('title', 'Mismatched card')->first();
        $this->assertSame($this->categoryA->id, $card->timesheet_category_id);
        $this->assertNull($card->project_id, 'the project is dropped, not silently paired with a category it does not fit');
        app(CurrentTenant::class)->set(null);
    }

    /** A standalone (no-project) category drops any project_id sent alongside it. */
    public function test_create_card_drops_project_for_a_standalone_category(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        $standalone = TimesheetCategory::create([
            'tenant_id' => $this->tenantA->id, 'name' => 'HR & Admin', 'requires_project' => false, 'is_active' => true,
        ]);
        app(CurrentTenant::class)->set(null);

        $headers = $this->bearer($this->staffA, $this->tenantA, ['board:write']);

        $preview = $this->callTool(CreateCardTool::class, [
            'title' => 'Admin card', 'type' => 'task', 'priority' => 'medium',
            'timesheet_category_id' => $standalone->id, 'project_id' => $this->projectA->id,
        ], $headers);
        $confirm = $this->confirm($this->toolData($preview)['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $card = WorkItem::where('title', 'Admin card')->first();
        $this->assertSame($standalone->id, $card->timesheet_category_id);
        $this->assertNull($card->project_id);
        app(CurrentTenant::class)->set(null);
    }

    // --- update_card: preview must not hide a project the confirm step drops ----

    /**
     * Retagging a card to a category the CURRENT project does not fit must show the
     * project being dropped in the PREVIEW, not just apply it silently at confirm —
     * the same "preview and confirm can never disagree" invariant save_timesheet_draft
     * leans on. Without this, a human approving "change effort type to HR & Admin"
     * would not be told the card is about to lose its project too.
     */
    public function test_update_card_preview_shows_the_project_it_will_drop(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        $standalone = TimesheetCategory::create([
            'tenant_id' => $this->tenantA->id, 'name' => 'HR & Admin', 'requires_project' => false, 'is_active' => true,
        ]);
        $card = WorkItem::create([
            'tenant_id' => $this->tenantA->id, 'employee_id' => $this->staffEmpA->id,
            'title' => 'Reclassify me', 'type' => 'task', 'priority' => 'medium', 'status' => 'todo',
            'timesheet_category_id' => $this->categoryA->id, 'project_id' => $this->projectA->id,
        ]);
        app(CurrentTenant::class)->set(null);

        $headers = $this->bearer($this->staffA, $this->tenantA, ['board:write']);

        $preview = $this->callTool(UpdateCardTool::class, [
            'work_item_id' => $card->id,
            'timesheet_category_id' => $standalone->id,
        ], $headers);
        $this->assertFalse($this->toolIsError($preview));
        $changes = $this->toolData($preview)['changes'];
        $this->assertArrayHasKey('project_id', $changes);
        $this->assertSame($this->projectA->id, $changes['project_id']['from']);
        $this->assertNull($changes['project_id']['to']);

        $confirm = $this->confirm($this->toolData($preview)['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $card->refresh();
        $this->assertSame($standalone->id, $card->timesheet_category_id);
        $this->assertNull($card->project_id, 'the preview promised this, and confirm must deliver exactly that');
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

    /**
     * No tool hands out a staff directory, so an MCP client has no way to reach an
     * employee_id on its own — naming the person the way people say it is the only
     * usable path in.
     */
    public function test_assign_task_resolves_the_assignee_by_nickname(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        $this->otherEmpA->update(['nickname' => 'omar']);
        app(CurrentTenant::class)->set(null);

        $headers = $this->bearer($this->managerA, $this->tenantA, ['board:write']);

        $preview = $this->callTool(AssignTaskTool::class, [
            'employee' => 'Omar', 'title' => 'Do it', 'type' => 'adhoc',
            'priority' => 'high', 'due_at' => '2026-08-10',
        ], $headers);

        $this->assertFalse($this->toolIsError($preview));
        $data = $this->toolData($preview);
        $this->assertStringContainsString('Omar', $data['summary']);

        $confirm = $this->confirm($data['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame($this->otherEmpA->id, WorkItem::first()->employee_id);
        app(CurrentTenant::class)->set(null);
    }

    /**
     * Assigning emails the assignee, so a half-matched name must never be guessed at:
     * the wrong guess mails the wrong person. The refusal carries the ids so the
     * caller can settle it in one more turn instead of dead-ending.
     */
    public function test_assign_task_refuses_a_name_matching_more_than_one_person_and_lists_them(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        $nabil = Employee::create(['tenant_id' => $this->tenantA->id, 'name' => 'Nabil Aziz', 'status' => 'active', 'workload' => 'green']);
        $nabilah = Employee::create(['tenant_id' => $this->tenantA->id, 'name' => 'Nabilah Rahim', 'status' => 'active', 'workload' => 'green']);
        app(CurrentTenant::class)->set(null);

        $headers = $this->bearer($this->managerA, $this->tenantA, ['board:write']);

        $response = $this->callTool(AssignTaskTool::class, [
            'employee' => 'Nabil', 'title' => 'Do it', 'type' => 'adhoc',
            'priority' => 'high', 'due_at' => '2026-08-10',
        ], $headers);

        $this->assertTrue($this->toolIsError($response));
        $text = (string) json_encode($response->json('result.content'));
        $this->assertStringContainsString('employee_id '.$nabil->id, $text);
        $this->assertStringContainsString('employee_id '.$nabilah->id, $text);

        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame(0, WorkItem::count());
        app(CurrentTenant::class)->set(null);
    }

    /**
     * An archived person is not assignable, so a name only they answer to means
     * "nobody here" — the same outcome as a typo, and never a silent assignment.
     */
    public function test_assign_task_refuses_a_name_that_matches_nobody_active(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        Employee::create(['tenant_id' => $this->tenantA->id, 'name' => 'Gone Ghazali', 'status' => 'archived', 'archived_at' => now(), 'workload' => 'green']);
        app(CurrentTenant::class)->set(null);

        $headers = $this->bearer($this->managerA, $this->tenantA, ['board:write']);

        foreach (['Ghazali', 'Nobody At All'] as $name) {
            $response = $this->callTool(AssignTaskTool::class, [
                'employee' => $name, 'title' => 'Do it', 'type' => 'adhoc',
                'priority' => 'high', 'due_at' => '2026-08-10',
            ], $headers);

            $this->assertTrue($this->toolIsError($response), $name.' must not resolve to anyone');
        }

        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame(0, WorkItem::count());
        app(CurrentTenant::class)->set(null);
    }

    /**
     * An assigned card is invisible to the assignee's timesheet until it carries an
     * effort type — see WorkItemController::assign()'s docblock. The preview must show
     * the category/project by NAME (an id means nothing to the human approving it), and
     * confirm must persist both.
     */
    public function test_assign_task_accepts_and_persists_the_effort_type(): void
    {
        $headers = $this->bearer($this->managerA, $this->tenantA, ['board:write']);

        $preview = $this->callTool(AssignTaskTool::class, [
            'employee_id' => $this->otherEmpA->id, 'title' => 'Do it', 'type' => 'adhoc',
            'priority' => 'high', 'due_at' => '2026-08-10',
            'timesheet_category_id' => $this->categoryA->id, 'project_id' => $this->projectA->id,
        ], $headers);

        $this->assertFalse($this->toolIsError($preview));
        $data = $this->toolData($preview);
        $this->assertSame($this->categoryA->name, $data['changes']['timesheet_category']);
        $this->assertSame($this->projectA->name, $data['changes']['project']);

        $confirm = $this->confirm($data['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $card = WorkItem::where('title', 'Do it')->first();
        $this->assertSame($this->categoryA->id, $card->timesheet_category_id);
        $this->assertSame($this->projectA->id, $card->project_id);
        app(CurrentTenant::class)->set(null);
    }

    /**
     * The same BoardRules::dropProjectTheCategoryDisallows() guard applies to an
     * assigned card as to a self-made one — a project the chosen category does not
     * offer never sticks, and the preview must say so up front rather than letting it
     * happen silently at confirm time (mirrors UpdateCardTool's preview).
     */
    public function test_assign_task_drops_a_project_the_chosen_category_does_not_allow_and_says_so_in_preview(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        $otherCategory = TimesheetCategory::create([
            'tenant_id' => $this->tenantA->id, 'name' => 'Other Delivery', 'requires_project' => true, 'is_active' => true,
        ]);
        $taggedProject = Project::create(['tenant_id' => $this->tenantA->id, 'code' => 'TAG', 'name' => 'Tagged Project', 'is_active' => true]);
        $taggedProject->categories()->attach($otherCategory->id);
        app(CurrentTenant::class)->set(null);

        $headers = $this->bearer($this->managerA, $this->tenantA, ['board:write']);

        $preview = $this->callTool(AssignTaskTool::class, [
            'employee_id' => $this->otherEmpA->id, 'title' => 'Mismatched assignment', 'type' => 'adhoc',
            'priority' => 'high', 'due_at' => '2026-08-10',
            'timesheet_category_id' => $this->categoryA->id, 'project_id' => $taggedProject->id,
        ], $headers);
        $this->assertFalse($this->toolIsError($preview));
        $changes = $this->toolData($preview)['changes'];
        $this->assertNull($changes['project'], 'the preview must not show the project as staying on the card');
        $this->assertArrayHasKey('project_dropped', $changes);

        $confirm = $this->confirm($this->toolData($preview)['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $card = WorkItem::where('title', 'Mismatched assignment')->first();
        $this->assertSame($this->categoryA->id, $card->timesheet_category_id);
        $this->assertNull($card->project_id, 'the project is dropped, not silently paired with a category it does not fit');
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
            'title' => 'Conference', 'host' => 'Acme Events Co', 'event_date' => '2026-09-01', 'description' => 'Feat @Other Omar',
        ], $headers);

        $this->assertFalse($this->toolIsError($preview));
        $data = $this->toolData($preview);
        $this->assertSame([], $data['changes']['tagged_employee_ids']);

        $confirm = $this->confirm($data['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $event = CompanyEvent::whereNotNull('host')->first();
        $this->assertNotNull($event);
        $this->assertSame([], $event->tagged_employee_ids);
        app(CurrentTenant::class)->set(null);
    }

    /**
     * host is what CompanyEvent::isExternal() reads, so an event posted without one
     * is an ordinary INTERNAL event -- RSVP instead of the organiser's sign-up link.
     * The browser cannot produce that (the "External" tick makes the field required);
     * this tool has no tick, so the rule lives in its validation instead.
     */
    public function test_an_event_posted_without_a_host_is_refused(): void
    {
        $headers = $this->bearer($this->hrA, $this->tenantA, ['tot:write']);

        $response = $this->callTool(CreateExternalTotEventTool::class, [
            'title' => 'Conference', 'event_date' => '2026-09-01',
        ], $headers);

        $this->assertTrue($this->toolIsError($response));

        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame(0, CompanyEvent::count(), 'nothing is posted when the host is missing');
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
        // Tuesday deliberately left out: under board-first a saved row's line key
        // includes work_item_id (WeekWriter::lineKey()), so a fresh card-based row can
        // never upsert a typed legacy row the way same category/project once did — it
        // would simply add alongside it and blow the day's capacity. Seeding Tuesday
        // here would turn this into a capacity test, which isn't the point of this one.
        $days = ['Mon' => 0, 'Wed' => 2, 'Thu' => 3, 'Fri' => 4];
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
        $card = $this->card();

        $preview = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => $tuesday,
                'work_item_id' => $card->id,
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
        $card = $this->card();

        $response = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK,
                'work_item_id' => $card->id,
                'percentage' => 50,
            ]],
        ], $headers);

        $this->assertTrue($this->toolIsError($response));
    }

    /**
     * A card carrying a standalone (no-project) category must still preview cleanly
     * — its project must come back null, read off the card, never guessed at.
     */
    public function test_preview_renders_an_entry_from_a_card_with_a_standalone_category(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        $standalone = TimesheetCategory::create([
            'tenant_id' => $this->tenantA->id, 'name' => 'Admin', 'requires_project' => false, 'is_active' => true,
        ]);
        app(CurrentTenant::class)->set(null);
        $card = $this->card(categoryId: $standalone->id, projectId: null);

        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);

        $preview = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK,
                'work_item_id' => $card->id,
                'percentage' => 100,
            ]],
        ], $headers);

        $this->assertFalse($this->toolIsError($preview));
        $row = $this->toolData($preview)['changes']['resulting_week'][self::WEEK][0];
        $this->assertSame('Admin', $row['category']);
        $this->assertNull($row['project']);
        $this->assertSame($card->title, $row['card']);
    }

    /**
     * A row naming a card that is not the caller's own — not owned, not
     * participated in — is refused rather than logged against it.
     */
    public function test_a_row_naming_another_employees_card_is_refused(): void
    {
        $othersCard = $this->card(employee: $this->otherEmpA);
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);

        $response = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK,
                'work_item_id' => $othersCard->id,
                'percentage' => 100,
            ]],
        ], $headers);

        $this->assertTrue($this->toolIsError($response));
        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame(0, TimesheetEntry::where('work_item_id', $othersCard->id)->count());
        app(CurrentTenant::class)->set(null);
    }

    /**
     * A row with no work_item_id at all is refused outright — under board-first
     * there is no other way to name a timesheet line, and validation must catch
     * this before the tool ever tries to look a card up.
     */
    public function test_a_row_with_no_work_item_id_is_refused(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);

        $response = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK,
                'percentage' => 100,
            ]],
        ], $headers);

        $this->assertTrue($this->toolIsError($response));
    }

    /**
     * The category is derived from the card, never trusted from the caller: a bogus
     * category_id in the request is simply ignored, and the resulting row is costed
     * exactly as the card says.
     */
    public function test_a_sent_category_id_is_ignored_in_favour_of_the_cards_own(): void
    {
        $card = $this->card();
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);

        $preview = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK,
                'work_item_id' => $card->id,
                'category_id' => 999999, // bogus — must have no effect
                'percentage' => 100,
            ]],
        ], $headers);

        $this->assertFalse($this->toolIsError($preview));
        $row = $this->toolData($preview)['changes']['resulting_week'][self::WEEK][0];
        $this->assertSame($this->categoryA->name, $row['category']);

        $confirm = $this->confirm($this->toolData($preview)['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $stored = TimesheetEntry::where('work_item_id', $card->id)->first();
        $this->assertSame($this->categoryA->id, $stored->category_id);
        app(CurrentTenant::class)->set(null);
    }

    /**
     * A card whose timesheet_category_id is null has not answered the board's
     * question yet — the row is refused with a message pointing at the board,
     * mirroring what BoardSuggestions::categoryFor() holds back on the capture
     * screen, rather than silently filing it under Others.
     */
    public function test_a_card_with_no_effort_type_set_is_refused(): void
    {
        $uncategorized = $this->card(categoryId: null, projectId: null);
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);

        $response = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK,
                'work_item_id' => $uncategorized->id,
                'percentage' => 100,
            ]],
        ], $headers);

        $this->assertTrue($this->toolIsError($response));
        $this->assertStringContainsString('effort type', $response->json('result.content.0.text'));
    }

    /**
     * EVERY offending card is named, not just the first. Drafting a week from a
     * project folder routinely touches several cards at once, and reporting one
     * problem per attempt costs the developer a trip to the board and a retry for
     * each — the same round-trip cost WeekWriter::assertDatesInWindow() and
     * assertNoBlankLines() gather their messages to avoid.
     */
    public function test_every_unusable_card_is_named_in_one_refusal(): void
    {
        $uncategorized = $this->card(categoryId: null, projectId: null);
        $projectless = $this->card(projectId: null);
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);

        $response = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [
                ['entry_date' => self::WEEK, 'work_item_id' => $uncategorized->id, 'percentage' => 50],
                ['entry_date' => self::WEEK, 'work_item_id' => $projectless->id, 'percentage' => 50],
            ],
        ], $headers);

        $this->assertTrue($this->toolIsError($response));

        $text = $response->json('result.content.0.text');
        $this->assertStringContainsString($uncategorized->title, $text);
        $this->assertStringContainsString($projectless->title, $text, 'the second bad card is reported in the same refusal, not on a retry');
    }

    /**
     * A card whose category DOES require a project (categoryA — 'Project Work') but
     * carries none of its own is refused with a message pointing at the board, not
     * normaliseEntries()'s raw "Choose a project for X" — this tool has no project_id
     * field for the caller to answer that with any more.
     */
    public function test_a_card_whose_category_needs_a_project_it_does_not_have_is_refused(): void
    {
        $projectless = $this->card(projectId: null);
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);

        $response = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK,
                'work_item_id' => $projectless->id,
                'percentage' => 100,
            ]],
        ], $headers);

        $this->assertTrue($this->toolIsError($response));
        $this->assertStringContainsString('needs a project', $response->json('result.content.0.text'));
    }

    /**
     * The split-day case work_item_id in lineKey() exists for: two different cards
     * on the SAME project on the SAME day must both survive the merge, not collapse
     * into "the same work listed twice".
     */
    public function test_two_different_cards_on_the_same_project_and_day_both_survive(): void
    {
        $cardOne = $this->card();
        $cardTwo = $this->card();
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);

        $first = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK, 'work_item_id' => $cardOne->id, 'percentage' => 50,
            ]],
        ], $headers);
        $this->confirm($this->toolData($first)['confirm_token'], $headers);

        $second = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK, 'work_item_id' => $cardTwo->id, 'percentage' => 50,
            ]],
        ], $headers);
        $this->assertFalse($this->toolIsError($second));
        $rows = $this->toolData($second)['changes']['resulting_week'][self::WEEK];
        $this->assertCount(2, $rows, 'both cards survive, even though they share a project');

        $confirm = $this->confirm($this->toolData($second)['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $stored = TimesheetEntry::whereDate('entry_date', self::WEEK)->whereNull('source')->get();
        $this->assertCount(2, $stored);
        $this->assertEqualsCanonicalizing(
            [$cardOne->id, $cardTwo->id],
            $stored->pluck('work_item_id')->map(fn ($id) => (int) $id)->all(),
        );
        app(CurrentTenant::class)->set(null);
    }

    /**
     * Bug 2 (black-box): a valid date that simply belongs to a different week must be
     * refused, not silently filed under the week it was submitted against — both at
     * preview time (WeekWriter::resolveWeek()) and by the browser path that shares
     * WeekWriter::save().
     */
    public function test_an_entry_dated_outside_its_week_is_refused_at_preview_and_in_the_browser(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);
        // A valid Monday in its own right — just not the week being saved.
        $wrongWeekDate = date('Y-m-d', strtotime(self::WEEK.' -7 day'));
        $card = $this->card();

        $preview = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => $wrongWeekDate,
                'work_item_id' => $card->id,
                'percentage' => 100,
            ]],
        ], $headers);

        $this->assertTrue($this->toolIsError($preview));
        // Specifically the in-week refusal, not the (also true) backfill-window one — the
        // two dates are close enough that a weaker assertion here could pass for either.
        $this->assertStringContainsString('is not in the week', $preview->json('result.content.0.text'));

        // Same rule, same shared WeekWriter::save() — the browser grid must be refused too.
        $this->actingAs($this->staffA)->withSession(['current_tenant' => $this->tenantA->id]);
        $response = $this->post('/app/timesheets', [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => $wrongWeekDate,
                'category_id' => $this->categoryA->id,
                'project_id' => $this->projectA->id,
                'percentage' => 100,
            ]],
        ]);
        $response->assertSessionHasErrors('entries.0.entry_date');
        $this->assertStringContainsString('is not in the week', session('errors')->first('entries.0.entry_date'));

        app(CurrentTenant::class)->set($this->tenantA);
        $this->assertSame(0, TimesheetEntry::whereDate('entry_date', $wrongWeekDate)->count());
        app(CurrentTenant::class)->set(null);
    }

    /**
     * Bug 3 (black-box): the preview must render the SAME resulting week confirm actually
     * stores. A typed row on a fully-locked public holiday must be dropped from the preview
     * (not shown as submitted), the generated Public Holiday row must appear in its place,
     * and the drop must be named in `changes.dropped` — not just a silently different week.
     */
    public function test_preview_matches_what_confirm_stores_when_a_locked_day_drops_the_typed_row(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        TimesheetCategory::create(['tenant_id' => $this->tenantA->id, 'name' => 'Public Holiday', 'requires_project' => false, 'is_active' => true]);
        PublicHoliday::create(['tenant_id' => $this->tenantA->id, 'name' => 'Test Holiday', 'date' => self::WEEK]);
        app(CurrentTenant::class)->set(null);

        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);
        $card = $this->card();

        $preview = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK,
                'work_item_id' => $card->id,
                'percentage' => 100,
            ]],
        ], $headers);

        $this->assertFalse($this->toolIsError($preview));
        $data = $this->toolData($preview);

        // The typed "Project Work" row is absent; the generated holiday row is present.
        $rows = $data['changes']['resulting_week'][self::WEEK];
        $this->assertCount(1, $rows);
        $this->assertSame('Public Holiday', $rows[0]['category']);

        // The drop is surfaced, not silent.
        $dropped = $data['changes']['dropped'];
        $this->assertCount(1, $dropped);
        $this->assertSame(self::WEEK, $dropped[0]['entry_date']);
        $this->assertSame('Project Work', $dropped[0]['category']);

        $confirm = $this->confirm($data['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $timesheet = Timesheet::forWeek(self::WEEK)->where('employee_id', $this->staffEmpA->id)->first();
        $stored = TimesheetEntry::where('timesheet_id', $timesheet->id)->whereDate('entry_date', self::WEEK)->get();
        $this->assertCount(1, $stored, 'exactly what the preview showed is what got stored');
        $this->assertSame('holiday', $stored->first()->source);
        app(CurrentTenant::class)->set(null);
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
        $card = $this->card();

        $response = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK,
                'work_item_id' => $card->id,
                'percentage' => 50,
            ]],
        ], $headers);

        $this->assertTrue($this->toolIsError($response));
        $this->assertStringContainsString('timesheets:write', $response->json('result.content.0.text'));
    }

    // --- save_timesheet_draft: split-day merge, capacity cap, removal ------

    /**
     * The bug this whole change fixes: a Tuesday saved as project A 50% from one
     * project folder, then project B 50% from another, must leave BOTH halves
     * standing — not have the second call silently erase the first.
     */
    public function test_saving_two_different_projects_on_the_same_day_combines_them(): void
    {
        $projectB = Project::create(['tenant_id' => $this->tenantA->id, 'code' => 'IGM', 'name' => 'iGuaman', 'is_active' => true]);
        $cardA = $this->card(projectId: $this->projectA->id);
        $cardB = $this->card(projectId: $projectB->id);
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);
        $tuesday = date('Y-m-d', strtotime(self::WEEK.' +1 day'));

        $first = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => $tuesday, 'work_item_id' => $cardA->id, 'percentage' => 50,
            ]],
        ], $headers);
        $this->assertFalse($this->toolIsError($first));
        $this->confirm($this->toolData($first)['confirm_token'], $headers);

        $second = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => $tuesday, 'work_item_id' => $cardB->id, 'percentage' => 50,
            ]],
        ], $headers);
        $this->assertFalse($this->toolIsError($second));
        $data = $this->toolData($second);
        $rows = $data['changes']['resulting_week'][$tuesday];
        $this->assertCount(2, $rows, 'both projects survive the second save');
        $byProject = collect($rows)->keyBy('project');
        $this->assertSame('already stored', $byProject['KPT: RMS']['status']);
        $this->assertSame('added by this change', $byProject['iGuaman']['status']);

        $confirm = $this->confirm($data['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $timesheet = Timesheet::forWeek(self::WEEK)->where('employee_id', $this->staffEmpA->id)->first();
        $stored = TimesheetEntry::where('timesheet_id', $timesheet->id)->whereDate('entry_date', $tuesday)->whereNull('source')->get();
        $this->assertCount(2, $stored);
        $this->assertEqualsWithDelta(100.0, $stored->sum('percentage'), 0.01);
        app(CurrentTenant::class)->set(null);
    }

    /**
     * A day already full must refuse another line rather than quietly going over
     * 100% — and the refusal must say what's stored, what was being added, and
     * what the total would become.
     */
    public function test_a_merge_that_would_exceed_the_days_capacity_is_refused_at_preview(): void
    {
        $projectB = Project::create(['tenant_id' => $this->tenantA->id, 'code' => 'IGM', 'name' => 'iGuaman', 'is_active' => true]);
        $cardA = $this->card(projectId: $this->projectA->id);
        $cardB = $this->card(projectId: $projectB->id);
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);
        $tuesday = date('Y-m-d', strtotime(self::WEEK.' +1 day'));

        $full = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => $tuesday, 'work_item_id' => $cardA->id, 'percentage' => 100,
            ]],
        ], $headers);
        $this->confirm($this->toolData($full)['confirm_token'], $headers);

        $overflow = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => $tuesday, 'work_item_id' => $cardB->id, 'percentage' => 50,
            ]],
        ], $headers);

        $this->assertTrue($this->toolIsError($overflow));
        $message = $overflow->json('result.content.0.text');
        $this->assertStringContainsString('Tue', $message);
        $this->assertStringContainsString('100', $message);
        $this->assertStringContainsString('150', $message);
    }

    /**
     * The cap is re-checked at confirm, not just trusted from the preview: a
     * valid preview can be made stale by another save landing before confirm.
     */
    public function test_the_cap_is_re_checked_at_confirm_time(): void
    {
        $projectB = Project::create(['tenant_id' => $this->tenantA->id, 'code' => 'IGM', 'name' => 'iGuaman', 'is_active' => true]);
        $cardB = $this->card(projectId: $projectB->id);
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);
        $tuesday = date('Y-m-d', strtotime(self::WEEK.' +1 day'));

        // Valid at preview time: the day is empty.
        $preview = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => $tuesday, 'work_item_id' => $cardB->id, 'percentage' => 50,
            ]],
        ], $headers);
        $this->assertFalse($this->toolIsError($preview));
        $token = $this->toolData($preview)['confirm_token'];

        // Now fill the day to capacity through a different path (the browser grid)
        // before the token is redeemed.
        $this->actingAs($this->staffA)->withSession(['current_tenant' => $this->tenantA->id]);
        $this->post('/app/timesheets', [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => $tuesday, 'category_id' => $this->categoryA->id,
                'project_id' => $this->projectA->id, 'percentage' => 100,
            ]],
        ]);
        Auth::forgetGuards();

        $confirm = $this->confirm($token, $headers);
        $this->assertTrue($this->toolIsError($confirm));
        $this->assertStringContainsString('150', $confirm->json('result.content.0.text'));

        app(CurrentTenant::class)->set($this->tenantA);
        $timesheet = Timesheet::forWeek(self::WEEK)->where('employee_id', $this->staffEmpA->id)->first();
        $stored = TimesheetEntry::where('timesheet_id', $timesheet->id)->whereDate('entry_date', $tuesday)->whereNull('source')->get();
        $this->assertCount(1, $stored, 'the confirm never landed');
        $this->assertSame($this->projectA->id, $stored->first()->project_id);
        app(CurrentTenant::class)->set(null);
    }

    /**
     * The first Saturday of the month is the TOT half day (DayCapacity) — its cap
     * is 50%, not 100%.
     */
    public function test_the_tot_saturday_caps_at_50_percent(): void
    {
        $projectB = Project::create(['tenant_id' => $this->tenantA->id, 'code' => 'IGM', 'name' => 'iGuaman', 'is_active' => true]);
        $cardA = $this->card(projectId: $this->projectA->id);
        $cardB = $this->card(projectId: $projectB->id);
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);

        // The first Saturday of the CURRENT month, falling back to last month's if
        // this month's has not happened yet — always in the past, always inside the
        // 6-week backfill window.
        // TotSession::firstSaturday() is the app's own rule — "first saturday of" the
        // month, which is the 1st itself when the 1st IS a Saturday. startOfMonth()
        // ->next(SATURDAY) is NOT the same thing: it skips a 1st that already is one,
        // landing on the 8th, an ordinary 100% day that would never trip the cap.
        $today = Carbon::now()->startOfDay();
        $totSaturday = TotSession::firstSaturday((int) $today->year, (int) $today->month);
        if ($totSaturday->greaterThan($today)) {
            $prev = $today->copy()->subMonthNoOverflow();
            $totSaturday = TotSession::firstSaturday((int) $prev->year, (int) $prev->month);
        }
        $weekStart = $totSaturday->copy()->subDays(5)->toDateString();
        $totSaturday = $totSaturday->toDateString();

        $half = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => $weekStart,
            'entries' => [[
                'entry_date' => $totSaturday, 'work_item_id' => $cardA->id, 'percentage' => 50,
            ]],
        ], $headers);
        $this->assertFalse($this->toolIsError($half));
        $this->confirm($this->toolData($half)['confirm_token'], $headers);

        $overflow = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => $weekStart,
            'entries' => [[
                'entry_date' => $totSaturday, 'work_item_id' => $cardB->id, 'percentage' => 25,
            ]],
        ], $headers);

        $this->assertTrue($this->toolIsError($overflow));
        $message = $overflow->json('result.content.0.text');
        $this->assertStringContainsString('75', $message);
        $this->assertStringContainsString('50', $message);
    }

    /**
     * Re-sending the same card on the same day is a correction (upsert), not a
     * duplicate — assertNoDuplicateLines() would otherwise refuse it, and simple
     * addition would double-count it.
     */
    public function test_identical_lines_combine_instead_of_duplicating(): void
    {
        $card = $this->card();
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);

        $first = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK, 'work_item_id' => $card->id, 'percentage' => 50,
            ]],
        ], $headers);
        $this->confirm($this->toolData($first)['confirm_token'], $headers);

        // Same line, corrected percentage.
        $second = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => self::WEEK, 'work_item_id' => $card->id, 'percentage' => 100,
            ]],
        ], $headers);
        $this->assertFalse($this->toolIsError($second));
        $data = $this->toolData($second);
        $rows = $data['changes']['resulting_week'][self::WEEK];
        $this->assertCount(1, $rows, 'the correction replaces the line, it does not duplicate it');
        $this->assertEquals(100.0, $rows[0]['percentage']);

        $confirm = $this->confirm($data['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $timesheet = Timesheet::forWeek(self::WEEK)->where('employee_id', $this->staffEmpA->id)->first();
        $stored = TimesheetEntry::where('timesheet_id', $timesheet->id)->whereDate('entry_date', self::WEEK)->whereNull('source')->get();
        $this->assertCount(1, $stored);
        $this->assertEquals(100.0, (float) $stored->first()->percentage);
        app(CurrentTenant::class)->set(null);
    }

    /**
     * The only way to drop a line the tool never typed: naming its date in
     * replace_days, which falls back to the old whole-day replace for that date.
     */
    public function test_replace_days_drops_a_line_the_caller_never_typed(): void
    {
        $projectB = Project::create(['tenant_id' => $this->tenantA->id, 'code' => 'IGM', 'name' => 'iGuaman', 'is_active' => true]);
        $cardA = $this->card(projectId: $this->projectA->id);
        $cardB = $this->card(projectId: $projectB->id);
        $headers = $this->bearer($this->staffA, $this->tenantA, ['timesheets:write']);
        $tuesday = date('Y-m-d', strtotime(self::WEEK.' +1 day'));

        // Wrongly split across two projects, as in the bug report.
        $first = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => $tuesday, 'work_item_id' => $cardA->id, 'percentage' => 50,
            ]],
        ], $headers);
        $this->confirm($this->toolData($first)['confirm_token'], $headers);

        $second = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => $tuesday, 'work_item_id' => $cardB->id, 'percentage' => 50,
            ]],
        ], $headers);
        $this->confirm($this->toolData($second)['confirm_token'], $headers);

        // Correction: "actually it was all Amanahku" — replace_days drops the
        // iGuaman half the tool never mentioned this time.
        $correction = $this->callTool(SaveTimesheetDraftTool::class, [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => $tuesday, 'work_item_id' => $cardA->id, 'percentage' => 100,
            ]],
            'replace_days' => [$tuesday],
        ], $headers);
        $this->assertFalse($this->toolIsError($correction));
        $data = $this->toolData($correction);
        $rows = $data['changes']['resulting_week'][$tuesday];
        $this->assertCount(1, $rows);
        $this->assertSame('KPT: RMS', $rows[0]['project']);
        $this->assertEquals(100.0, $rows[0]['percentage']);

        $confirm = $this->confirm($data['confirm_token'], $headers);
        $this->assertFalse($this->toolIsError($confirm));

        app(CurrentTenant::class)->set($this->tenantA);
        $timesheet = Timesheet::forWeek(self::WEEK)->where('employee_id', $this->staffEmpA->id)->first();
        $stored = TimesheetEntry::where('timesheet_id', $timesheet->id)->whereDate('entry_date', $tuesday)->whereNull('source')->get();
        $this->assertCount(1, $stored);
        $this->assertSame($this->projectA->id, $stored->first()->project_id);
        app(CurrentTenant::class)->set(null);
    }

    /**
     * The regression guard for the whole split: TimesheetController::store()
     * (the browser grid) must keep replacing a day wholesale, exactly as before —
     * only the MCP tool's mergePartialIntoExisting() path merges.
     */
    public function test_the_browser_path_still_replaces_the_whole_day(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenantA->id, 'employee_id' => $this->staffEmpA->id,
            'week_start' => self::WEEK, 'status' => 'draft',
        ]);
        $tuesday = date('Y-m-d', strtotime(self::WEEK.' +1 day'));
        TimesheetEntry::create([
            'tenant_id' => $this->tenantA->id, 'timesheet_id' => $timesheet->id,
            'entry_date' => $tuesday, 'category_id' => $this->categoryA->id,
            'project_id' => $this->projectA->id, 'percentage' => 100, 'hours' => 8,
        ]);
        app(CurrentTenant::class)->set(null);

        $projectB = Project::create(['tenant_id' => $this->tenantA->id, 'code' => 'IGM', 'name' => 'iGuaman', 'is_active' => true]);

        $this->actingAs($this->staffA)->withSession(['current_tenant' => $this->tenantA->id]);
        $response = $this->post('/app/timesheets', [
            'week_start' => self::WEEK,
            'entries' => [[
                'entry_date' => $tuesday, 'category_id' => $this->categoryA->id,
                'project_id' => $projectB->id, 'percentage' => 50,
            ]],
        ]);
        $response->assertSessionHasNoErrors();

        app(CurrentTenant::class)->set($this->tenantA);
        $timesheet->refresh();
        $stored = $timesheet->entries()->whereDate('entry_date', $tuesday)->whereNull('source')->get();
        // The whole grid replaces the week — the old projectA row is gone, only
        // the newly-posted projectB row remains.
        $this->assertCount(1, $stored, 'the browser grid replaces the day, it does not merge into it');
        $this->assertSame($projectB->id, $stored->first()->project_id);
        $this->assertEquals(50.0, (float) $stored->first()->percentage);
        app(CurrentTenant::class)->set(null);
    }
}
