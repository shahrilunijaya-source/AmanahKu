<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\FeatureManager;
use App\Timesheet\BoardSuggestions;
use App\Timesheet\LockedDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for TimesheetController::screenData() — the data fed to the
 * day-first Alpine screen (Tasks 7-8): locked days, the flat recent-combinations
 * work-item list, today, the earliest editable week, and the trimmed category picker.
 * Harness copied from TimesheetTest.
 */
class TimesheetScreenDataTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    private TimesheetCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
        // A standalone category (no project required) keeps allocation payloads simple.
        $this->category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Others', 'requires_project' => false,
        ]);

        // The suite's fixtures all sit in the week of Mon 2026-06-15. Pin "now" to that
        // week's Friday so those dates are in the past and inside the backfill window.
        Carbon::setTestNow('2026-06-19 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_the_picker_excludes_the_generated_categories(): void
    {
        TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'On Leave', 'requires_project' => false]);
        TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Public Holiday', 'requires_project' => false]);

        $response = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $response->assertOk();
        $names = collect($response->viewData('tsCategories'))->pluck('name');
        $this->assertFalse($names->contains('On Leave'));
        $this->assertFalse($names->contains('Public Holiday'));
        $this->assertTrue($names->contains('Others'));
    }

    public function test_the_picker_includes_leave_categories_when_the_leave_module_is_off(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'module.leave', false);
        TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'On Leave', 'requires_project' => false]);
        TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Public Holiday', 'requires_project' => false]);

        $response = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $response->assertOk();
        $names = collect($response->viewData('tsCategories'))->pluck('name');
        $this->assertTrue($names->contains('On Leave'));
        $this->assertTrue($names->contains('Public Holiday'));
    }

    public function test_ts_projects_carry_their_category_ids(): void
    {
        $dev = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true]);
        $categorized = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS', 'is_active' => true]);
        $categorized->categories()->attach($dev->id);
        $uncategorized = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'Legacy Project', 'is_active' => true]);

        $response = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $projects = collect($response->viewData('tsProjects'))->keyBy('name');
        $this->assertSame([$dev->id], $projects['KPT: RMS']['category_ids']->all());
        // Uncategorized projects carry an empty list — the picker treats that as "show under every category".
        $this->assertSame([], $projects['Legacy Project']['category_ids']->all());
    }

    public function test_locked_days_reach_the_view(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);

        $response = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $this->assertSame('holiday', $response->viewData('tsLocked')['2026-06-17']['source']);
    }

    /**
     * Regression for the "existingGrid re-seeds stale locked rows" finding: a source-tagged
     * row (generated from approved leave/holiday) must never be seeded back into the editable
     * grid. Without the `source !== null` skip, a cancelled leave day whose row survives on
     * the timesheet would resurface as a blank, editable 100% row — and re-saving it would
     * re-post as a manual `source=null` entry, exactly what D5 (categoryOptions' exclusion of
     * On Leave / Public Holiday from the picker) is meant to prevent.
     */
    /**
     * `tsDismissed` feeds the capture screen's "Removed: <card> · Restore" line. It
     * carries enough of the card to offer the row back, so a mis-tapped remove is one
     * click to undo rather than a trip to the board.
     */
    public function test_struck_off_cards_reach_the_view(): void
    {
        $card = WorkItem::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'Tender ISCAF', 'type' => 'task', 'status' => 'prog',
            'timesheet_category_id' => $this->category->id,
        ]);
        Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 0,
            'dismissed_suggestions' => ['2026-06-16' => [$card->id]],
        ]);

        $response = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $dismissed = $response->viewData('tsDismissed');
        $this->assertSame('Tender ISCAF', $dismissed['2026-06-16'][0]['title']);
        $this->assertSame($this->category->id, $dismissed['2026-06-16'][0]['category_id']);
    }

    public function test_existing_grid_excludes_source_tagged_rows(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 8,
        ]);
        TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $sheet->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->category->id, 'percentage' => 100, 'project' => 'Others', 'hours' => 8,
            'source' => null,
        ]);
        TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $sheet->id, 'entry_date' => '2026-06-16',
            'category_id' => $this->category->id, 'percentage' => 100, 'project' => 'On Leave — Annual Leave', 'hours' => 8,
            'source' => 'leave',
        ]);

        $response = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $grid = $response->viewData('existingGrid');
        $this->assertArrayHasKey('2026-06-15', $grid);
        $this->assertArrayNotHasKey('2026-06-16', $grid);
    }

    /**
     * `tsSuggested` feeds the capture screen's per-day board prefill (Task 4): the week's
     * In Progress board cards, one row per card per day it was worked, keyed by ISO date.
     * The observer that opens a WorkItemProgressStint when a card lands on 'prog' is what
     * gives BoardSuggestions a stint to work from.
     */
    public function test_the_capture_payload_carries_the_days_board_suggestions(): void
    {
        $card = $this->employee->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => 'Build the thing', 'type' => 'task',
            'priority' => 'low', 'status' => 'prog', 'progress' => 0,
        ]);

        $response = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $response->assertOk();
        $suggested = $response->viewData('tsSuggested');
        $today = Carbon::now()->toDateString();

        $this->assertArrayHasKey($today, $suggested);
        $this->assertSame($card->id, $suggested[$today][0]['work_item_id']);
    }

    public function test_a_failure_building_suggestions_does_not_take_the_screen_down(): void
    {
        // BoardSuggestions is final, so it cannot be mocked as a subclass — Mockery only
        // supports partial-mocking an already-instantiated object of a final class.
        $real = new BoardSuggestions(app(LockedDays::class));
        $mock = \Mockery::mock($real);
        $mock->shouldReceive('forWeek')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(BoardSuggestions::class, $mock);

        $response = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $response->assertOk();
        $this->assertSame([], $response->viewData('tsSuggested'));
    }
}
