<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\TimesheetOptionsTool;
use App\Mcp\Tools\TimesheetWeekTool;
use App\Mcp\Tools\TotSessionsTool;
use App\Mcp\Tools\WorkItemsTool;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\TotParticipation;
use App\Models\TotSession;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemProgressStint;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Coverage for the read-only MCP server (routes/ai.php: POST /mcp/amanahku).
 *
 * Exercises the full bearer-token stack (auth:sanctum + api.tenant) rather than
 * mocking the tool layer directly, the same way ApiTokenTest exercises the REST
 * API — so tenant isolation, scope checks and role narrowing are proven end to
 * end through the real HTTP route and real middleware.
 */
class AmanahkuServerTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = '2026-08-03'; // a Monday

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $hrA;

    private User $staffA;

    private Employee $staffEmpA;

    private Employee $otherEmpA;

    private Employee $hrEmpA;

    private Project $projectA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        $this->tenantB = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BE']);

        app(CurrentTenant::class)->set($this->tenantA);

        $this->hrA = User::create(['name' => 'HR Ann', 'email' => 'hr.a@example.com', 'password' => Hash::make('password')]);
        $this->hrA->tenants()->attach($this->tenantA->id, ['role' => 'hr']);
        $this->hrEmpA = Employee::create(['tenant_id' => $this->tenantA->id, 'user_id' => $this->hrA->id, 'name' => 'HR Ann', 'status' => 'active', 'workload' => 'green']);

        $this->staffA = User::create(['name' => 'Staff Sam', 'email' => 'staff.a@example.com', 'password' => Hash::make('password')]);
        $this->staffA->tenants()->attach($this->tenantA->id, ['role' => 'employee']);
        $this->staffEmpA = Employee::create(['tenant_id' => $this->tenantA->id, 'user_id' => $this->staffA->id, 'name' => 'Staff Sam', 'status' => 'active', 'workload' => 'green']);

        $this->otherEmpA = Employee::create(['tenant_id' => $this->tenantA->id, 'name' => 'Other Omar', 'status' => 'active', 'workload' => 'green']);

        $this->projectA = Project::create(['tenant_id' => $this->tenantA->id, 'code' => 'KPT', 'name' => 'KPT: RMS', 'is_active' => true]);

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

    /** Mint a tenant-bound token and return the Bearer auth header array. */
    private function bearer(User $user, Tenant $tenant, array $abilities = ['*']): array
    {
        $token = $user->mintApiToken($tenant, 'test', $abilities);

        return ['Authorization' => 'Bearer '.$token->plainTextToken];
    }

    /** POST a tools/call JSON-RPC envelope against /mcp/amanahku. */
    private function callTool(string $toolClass, array $arguments, array $headers = []): TestResponse
    {
        $name = app($toolClass)->name();

        return $this->postJson('/mcp/amanahku', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], $headers);
    }

    /** The text of the tool result's first content item, decoded from JSON. */
    private function toolData(TestResponse $response): array
    {
        $text = $response->json('result.content.0.text');

        return json_decode($text, true) ?? [];
    }

    private function toolIsError(TestResponse $response): bool
    {
        return (bool) $response->json('result.isError');
    }

    private function submitWeek(Employee $employee, Tenant $tenant, Project $project, array $percentages, string $status = 'submitted'): Timesheet
    {
        app(CurrentTenant::class)->set($tenant);

        $timesheet = Timesheet::create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'week_start' => self::WEEK, 'status' => $status,
        ]);

        foreach ($percentages as $offset => $percentage) {
            TimesheetEntry::create([
                'tenant_id' => $tenant->id, 'timesheet_id' => $timesheet->id,
                'entry_date' => date('Y-m-d', strtotime(self::WEEK.' +'.$offset.' day')),
                'project_id' => $project->id, 'percentage' => $percentage,
            ]);
        }

        app(CurrentTenant::class)->set(null);

        return $timesheet;
    }

    // --- auth ----------------------------------------------------------------

    public function test_unauthenticated_post_is_unauthorized(): void
    {
        $this->postJson('/mcp/amanahku', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'timesheet-week-tool', 'arguments' => []],
        ])->assertUnauthorized();
    }

    // --- scope enforcement -----------------------------------------------------

    public function test_tool_refuses_a_token_missing_its_scope(): void
    {
        // Token has * for board:read only, not timesheets:read.
        $response = $this->callTool(
            TimesheetWeekTool::class,
            ['week_start' => self::WEEK],
            $this->bearer($this->hrA, $this->tenantA, ['board:read'])
        );

        $response->assertOk();
        $this->assertTrue($this->toolIsError($response));
        $this->assertStringContainsString('timesheets:read', $response->json('result.content.0.text'));
    }

    // --- TimesheetWeekTool -----------------------------------------------------

    public function test_timesheet_week_tool_rejects_a_non_monday(): void
    {
        $response = $this->callTool(
            TimesheetWeekTool::class,
            ['week_start' => '2026-08-04'], // a Tuesday
            $this->bearer($this->hrA, $this->tenantA, ['timesheets:read'])
        );

        $this->assertTrue($this->toolIsError($response));
        $this->assertStringContainsString('Monday', $response->json('result.content.0.text'));
    }

    public function test_timesheet_week_tool_happy_path_for_privileged_caller(): void
    {
        $this->submitWeek($this->staffEmpA, $this->tenantA, $this->projectA, [100]);
        $this->submitWeek($this->otherEmpA, $this->tenantA, $this->projectA, [50]);

        $response = $this->callTool(
            TimesheetWeekTool::class,
            ['week_start' => self::WEEK],
            $this->bearer($this->hrA, $this->tenantA, ['timesheets:read'])
        );

        $this->assertFalse($this->toolIsError($response));
        $data = $this->toolData($response);
        $this->assertCount(2, $data['timesheets']);
        $names = collect($data['timesheets'])->pluck('employee')->all();
        $this->assertContains('Staff Sam', $names);
        $this->assertContains('Other Omar', $names);
    }

    public function test_timesheet_week_tool_non_privileged_sees_only_own(): void
    {
        $this->submitWeek($this->staffEmpA, $this->tenantA, $this->projectA, [100]);
        $this->submitWeek($this->otherEmpA, $this->tenantA, $this->projectA, [50]);

        $response = $this->callTool(
            TimesheetWeekTool::class,
            ['week_start' => self::WEEK],
            $this->bearer($this->staffA, $this->tenantA, ['timesheets:read'])
        );

        $data = $this->toolData($response);
        $this->assertCount(1, $data['timesheets']);
        $this->assertSame('Staff Sam', $data['timesheets'][0]['employee']);
        $this->assertSame('KPT: RMS', $data['timesheets'][0]['entries'][0]['project']);
    }

    public function test_timesheet_week_tool_is_tenant_isolated(): void
    {
        app(CurrentTenant::class)->set($this->tenantB);
        $bProject = Project::create(['tenant_id' => $this->tenantB->id, 'code' => 'B1', 'name' => 'Beta Project', 'is_active' => true]);
        $bEmployee = Employee::create(['tenant_id' => $this->tenantB->id, 'name' => 'Beta Bob', 'status' => 'active', 'workload' => 'green']);
        app(CurrentTenant::class)->set(null);
        $this->submitWeek($bEmployee, $this->tenantB, $bProject, [100]);

        $this->submitWeek($this->staffEmpA, $this->tenantA, $this->projectA, [100]);

        $response = $this->callTool(
            TimesheetWeekTool::class,
            ['week_start' => self::WEEK],
            $this->bearer($this->hrA, $this->tenantA, ['timesheets:read'])
        );

        $names = collect($this->toolData($response)['timesheets'])->pluck('employee')->all();
        $this->assertContains('Staff Sam', $names);
        $this->assertNotContains('Beta Bob', $names);
    }

    /**
     * A board card, in In Progress, with a stint that covers self::WEEK — the exact
     * shape BoardSuggestions::forWeek() prefills the capture screen from. Owned by
     * $employee (defaults to staffEmpA) so tests can build one for a privileged
     * caller's own board as well as a plain employee's.
     */
    private function suggestableCard(?Employee $employee = null, string $title = 'Suggested Card'): WorkItem
    {
        app(CurrentTenant::class)->set($this->tenantA);
        // Category name is unique per tenant (timesheet_categories.tenant_id+name), so a
        // test calling this helper more than once needs a distinct name each time — tied
        // to $title, which callers already vary.
        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenantA->id, 'name' => 'Development: '.$title, 'requires_project' => true, 'is_active' => true,
        ]);
        $card = WorkItem::create([
            'tenant_id' => $this->tenantA->id, 'employee_id' => ($employee ?? $this->staffEmpA)->id,
            'title' => $title, 'type' => 'task', 'priority' => 'medium', 'status' => 'prog',
            'timesheet_category_id' => $category->id, 'project_id' => $this->projectA->id,
        ]);
        // Created directly (not via the status-transition observer) so the stint's
        // started_at falls inside self::WEEK rather than "now" (the real test-run date).
        WorkItemProgressStint::create([
            'tenant_id' => $this->tenantA->id, 'work_item_id' => $card->id, 'started_at' => self::WEEK,
        ]);
        app(CurrentTenant::class)->set(null);

        return $card;
    }

    public function test_timesheet_week_tool_includes_suggested_for_own_week(): void
    {
        $card = $this->suggestableCard();

        $response = $this->callTool(
            TimesheetWeekTool::class,
            ['week_start' => self::WEEK],
            $this->bearer($this->staffA, $this->tenantA, ['timesheets:read'])
        );

        $this->assertFalse($this->toolIsError($response));
        $data = $this->toolData($response);
        $this->assertArrayHasKey('suggested', $data);
        $this->assertArrayHasKey(self::WEEK, $data['suggested']);
        $this->assertSame($card->title, $data['suggested'][self::WEEK][0]['title']);
    }

    /**
     * A privileged caller's `timesheets` list is never narrowed to one employee — it
     * reads the whole tenant. `suggested`, though, is always the CALLER's own board
     * cards: HR and management have their own timesheets to fill too, and are exactly
     * the people likely to draft one over MCP. This proves both halves — the caller's
     * own suggestable card shows up, and another employee's does not leak in even
     * though that employee's timesheet is right there in the response.
     */
    public function test_timesheet_week_tool_includes_only_the_privileged_callers_own_suggested(): void
    {
        $ownCard = $this->suggestableCard($this->hrEmpA, 'HR Own Card');
        $othersCard = $this->suggestableCard($this->staffEmpA, 'Staff Card');
        $this->submitWeek($this->staffEmpA, $this->tenantA, $this->projectA, [100]);

        $response = $this->callTool(
            TimesheetWeekTool::class,
            ['week_start' => self::WEEK],
            $this->bearer($this->hrA, $this->tenantA, ['timesheets:read'])
        );

        $this->assertFalse($this->toolIsError($response));
        $data = $this->toolData($response);
        $this->assertArrayHasKey('suggested', $data);
        $titles = collect($data['suggested'][self::WEEK] ?? [])->pluck('title')->all();
        $this->assertContains($ownCard->title, $titles);
        $this->assertNotContains($othersCard->title, $titles);
    }

    // --- TimesheetOptionsTool -----------------------------------------------------

    public function test_timesheet_options_tool_returns_categories_and_projects_for_the_tenant(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        TimesheetCategory::create(['tenant_id' => $this->tenantA->id, 'name' => 'Development', 'requires_project' => true, 'is_active' => true]);
        TimesheetCategory::create(['tenant_id' => $this->tenantA->id, 'name' => 'Meeting', 'requires_project' => false, 'is_active' => true]);
        app(CurrentTenant::class)->set(null);

        $response = $this->callTool(TimesheetOptionsTool::class, [], $this->bearer($this->staffA, $this->tenantA, ['timesheets:read']));

        $this->assertFalse($this->toolIsError($response));
        $data = $this->toolData($response);
        $categoryNames = collect($data['categories'])->pluck('name')->all();
        $this->assertContains('Development', $categoryNames);
        $this->assertContains('Meeting', $categoryNames);
        $projectNames = collect($data['projects'])->pluck('name')->all();
        $this->assertContains('KPT: RMS', $projectNames);
    }

    public function test_timesheet_options_tool_reports_requires_project_correctly(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        TimesheetCategory::create(['tenant_id' => $this->tenantA->id, 'name' => 'Development', 'requires_project' => true, 'is_active' => true]);
        TimesheetCategory::create(['tenant_id' => $this->tenantA->id, 'name' => 'Meeting', 'requires_project' => false, 'is_active' => true]);
        app(CurrentTenant::class)->set(null);

        $response = $this->callTool(TimesheetOptionsTool::class, [], $this->bearer($this->staffA, $this->tenantA, ['timesheets:read']));

        $categories = collect($this->toolData($response)['categories'])->keyBy('name');
        $this->assertTrue($categories['Development']['requires_project']);
        $this->assertFalse($categories['Meeting']['requires_project']);
    }

    public function test_timesheet_options_tool_is_tenant_isolated(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        TimesheetCategory::create(['tenant_id' => $this->tenantA->id, 'name' => 'Development', 'requires_project' => true, 'is_active' => true]);
        app(CurrentTenant::class)->set($this->tenantB);
        TimesheetCategory::create(['tenant_id' => $this->tenantB->id, 'name' => 'Beta Only Category', 'requires_project' => false, 'is_active' => true]);
        Project::create(['tenant_id' => $this->tenantB->id, 'code' => 'B1', 'name' => 'Beta Project', 'is_active' => true]);
        app(CurrentTenant::class)->set(null);

        $response = $this->callTool(TimesheetOptionsTool::class, [], $this->bearer($this->staffA, $this->tenantA, ['timesheets:read']));

        $data = $this->toolData($response);
        $categoryNames = collect($data['categories'])->pluck('name')->all();
        $this->assertNotContains('Beta Only Category', $categoryNames);
        $projectNames = collect($data['projects'])->pluck('name')->all();
        $this->assertNotContains('Beta Project', $projectNames);
    }

    public function test_timesheet_options_tool_refuses_a_token_missing_its_scope(): void
    {
        $response = $this->callTool(TimesheetOptionsTool::class, [], $this->bearer($this->staffA, $this->tenantA, ['board:read']));

        $this->assertTrue($this->toolIsError($response));
        $this->assertStringContainsString('timesheets:read', $response->json('result.content.0.text'));
    }

    // --- WorkItemsTool -----------------------------------------------------

    public function test_work_items_tool_happy_path_for_privileged_caller(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        WorkItem::create(['tenant_id' => $this->tenantA->id, 'employee_id' => $this->staffEmpA->id, 'title' => 'Sam card', 'status' => 'todo', 'project_id' => $this->projectA->id]);
        WorkItem::create(['tenant_id' => $this->tenantA->id, 'employee_id' => $this->otherEmpA->id, 'title' => 'Omar card', 'status' => 'prog']);
        app(CurrentTenant::class)->set(null);

        $response = $this->callTool(WorkItemsTool::class, [], $this->bearer($this->hrA, $this->tenantA, ['board:read']));

        $this->assertFalse($this->toolIsError($response));
        $titles = collect($this->toolData($response)['work_items'])->pluck('title')->all();
        $this->assertContains('Sam card', $titles);
        $this->assertContains('Omar card', $titles);
    }

    public function test_work_items_tool_non_privileged_sees_own_and_unassigned_only(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        WorkItem::create(['tenant_id' => $this->tenantA->id, 'employee_id' => $this->staffEmpA->id, 'title' => 'Sam card', 'status' => 'todo']);
        WorkItem::create(['tenant_id' => $this->tenantA->id, 'employee_id' => $this->otherEmpA->id, 'title' => 'Omar card', 'status' => 'todo']);
        WorkItem::create(['tenant_id' => $this->tenantA->id, 'employee_id' => null, 'title' => 'Unassigned card', 'status' => 'todo']);
        app(CurrentTenant::class)->set(null);

        $response = $this->callTool(WorkItemsTool::class, [], $this->bearer($this->staffA, $this->tenantA, ['board:read']));

        $titles = collect($this->toolData($response)['work_items'])->pluck('title')->all();
        $this->assertContains('Sam card', $titles);
        $this->assertContains('Unassigned card', $titles);
        $this->assertNotContains('Omar card', $titles);
    }

    /**
     * A card the caller was added to as a participant is one save_timesheet_draft
     * accepts (BoardSuggestions::cardsFor()'s membership), so the listing must
     * surface it too — and carry the id, which is the work_item_id that tool takes.
     * Without the id, the only route to it was the week's stint-driven suggestions.
     */
    public function test_work_items_tool_lists_participant_cards_with_ids(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        $shared = WorkItem::create(['tenant_id' => $this->tenantA->id, 'employee_id' => $this->otherEmpA->id, 'title' => 'Shared card', 'status' => 'prog', 'due_at' => '2026-08-07']);
        $shared->participants()->attach($this->staffEmpA->id);
        app(CurrentTenant::class)->set(null);

        $response = $this->callTool(WorkItemsTool::class, [], $this->bearer($this->staffA, $this->tenantA, ['board:read']));

        $row = collect($this->toolData($response)['work_items'])->firstWhere('title', 'Shared card');
        $this->assertNotNull($row);
        $this->assertSame($shared->id, $row['id']);
    }

    public function test_work_items_tool_is_tenant_isolated(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        WorkItem::create(['tenant_id' => $this->tenantA->id, 'title' => 'Alpha card', 'status' => 'todo']);
        app(CurrentTenant::class)->set($this->tenantB);
        WorkItem::create(['tenant_id' => $this->tenantB->id, 'title' => 'Beta card', 'status' => 'todo']);
        app(CurrentTenant::class)->set(null);

        $response = $this->callTool(WorkItemsTool::class, [], $this->bearer($this->hrA, $this->tenantA, ['board:read']));

        $titles = collect($this->toolData($response)['work_items'])->pluck('title')->all();
        $this->assertContains('Alpha card', $titles);
        $this->assertNotContains('Beta card', $titles);
    }

    // --- TotSessionsTool -----------------------------------------------------

    public function test_tot_sessions_tool_happy_path_with_participation(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        $session = TotSession::create([
            'tenant_id' => $this->tenantA->id, 'year' => 2026, 'month' => 8,
            'title' => 'Testing 101', 'status' => 'done', 'presenter_employee_id' => $this->staffEmpA->id,
        ]);
        TotParticipation::create(['tenant_id' => $this->tenantA->id, 'session_id' => $session->id, 'employee_id' => $this->otherEmpA->id, 'watched_at' => now()]);
        app(CurrentTenant::class)->set(null);

        $response = $this->callTool(TotSessionsTool::class, ['year' => 2026, 'month' => 8], $this->bearer($this->staffA, $this->tenantA, ['tot:read']));

        $this->assertFalse($this->toolIsError($response));
        $data = $this->toolData($response);
        $this->assertCount(1, $data['sessions']);
        $this->assertSame('Testing 101', $data['sessions'][0]['topic']);
        $this->assertSame('done', $data['sessions'][0]['status']);
        $this->assertSame(1, $data['sessions'][0]['participant_count']);
        $this->assertContains('Other Omar', $data['sessions'][0]['participants']);
    }

    public function test_tot_sessions_tool_is_tenant_isolated(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);
        TotSession::create(['tenant_id' => $this->tenantA->id, 'year' => 2026, 'month' => 8, 'title' => 'Alpha TOT', 'status' => 'planned']);
        app(CurrentTenant::class)->set($this->tenantB);
        TotSession::create(['tenant_id' => $this->tenantB->id, 'year' => 2026, 'month' => 8, 'title' => 'Beta TOT', 'status' => 'planned']);
        app(CurrentTenant::class)->set(null);

        $response = $this->callTool(TotSessionsTool::class, ['year' => 2026, 'month' => 8], $this->bearer($this->hrA, $this->tenantA, ['tot:read']));

        $topics = collect($this->toolData($response)['sessions'])->pluck('topic')->all();
        $this->assertContains('Alpha TOT', $topics);
        $this->assertNotContains('Beta TOT', $topics);
    }

    public function test_tot_sessions_tool_defaults_to_current_year(): void
    {
        $this->travelTo('2026-08-26');

        app(CurrentTenant::class)->set($this->tenantA);
        TotSession::create(['tenant_id' => $this->tenantA->id, 'year' => 2026, 'month' => 8, 'title' => 'This year', 'status' => 'planned']);
        TotSession::create(['tenant_id' => $this->tenantA->id, 'year' => 2025, 'month' => 8, 'title' => 'Last year', 'status' => 'done']);
        app(CurrentTenant::class)->set(null);

        $response = $this->callTool(TotSessionsTool::class, [], $this->bearer($this->hrA, $this->tenantA, ['tot:read']));

        $topics = collect($this->toolData($response)['sessions'])->pluck('topic')->all();
        $this->assertContains('This year', $topics);
        $this->assertNotContains('Last year', $topics);
    }
}
