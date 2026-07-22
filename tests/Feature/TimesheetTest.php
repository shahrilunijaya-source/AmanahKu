<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Timesheets module.
 * Harness (setUp / actingInTenant / hrActor) copied from ExpenseTest.
 */
class TimesheetTest extends TestCase
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

    public function test_employee_creates_a_timesheet_with_entries_and_total_is_computed(): void
    {
        // Act — two full days at 100% each. Hours derive from percentage (100% = 8h).
        $response = $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'week_label' => 'Week 25 · 15–21 Jun',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100, 'description' => 'Endpoints'],
                ['entry_date' => '2026-06-16', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ]);

        // Assert
        $response->assertRedirect();
        $timesheet = Timesheet::where('employee_id', $this->employee->id)->first();
        $this->assertNotNull($timesheet);
        $this->assertSame('draft', $timesheet->status);
        $this->assertSame(2, $timesheet->entries()->count());
        $this->assertSame('16.00', (string) $timesheet->total_hours);
    }

    public function test_submit_transitions_draft_to_submitted(): void
    {
        // Arrange
        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 0,
        ]);
        $timesheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->category->id, 'percentage' => 100, 'hours' => 8.00,
        ]);

        // Act — the single day totals 100%, so submission is allowed.
        $response = $this->actingInTenant()->post("/app/timesheets/{$timesheet->id}/submit");

        // Assert
        $response->assertRedirect();
        $fresh = $timesheet->fresh();
        $this->assertSame('submitted', $fresh->status);
        $this->assertNotNull($fresh->submitted_at);
    }

    public function test_store_does_not_reopen_or_double_count_an_already_submitted_week(): void
    {
        // Arrange — a submitted timesheet already exists for this week.
        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 8,
        ]);
        $timesheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->category->id, 'percentage' => 100, 'hours' => 8.00,
        ]);

        // Act — replay store() for the same week (double-click / back-button re-post).
        $response = $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-16', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ]);

        // Assert — rejected; status preserved, no duplicate entries, no double count.
        $response->assertStatus(422);
        $fresh = $timesheet->fresh();
        $this->assertSame('submitted', $fresh->status);
        $this->assertSame(1, $fresh->entries()->count());
        $this->assertSame('8.00', (string) $fresh->total_hours);
    }

    public function test_submit_is_blocked_when_a_day_does_not_total_100_percent(): void
    {
        // Arrange — a draft whose only day sums to 50%.
        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 0,
        ]);
        $timesheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->category->id, 'percentage' => 50, 'hours' => 4.00,
        ]);

        // Act + Assert — submission rejected; the day is not yet 100%.
        $this->actingInTenant()->post("/app/timesheets/{$timesheet->id}/submit")
            ->assertSessionHasErrors('submit');
        $this->assertSame('draft', $timesheet->fresh()->status);
    }

    public function test_store_with_submit_now_rejects_an_incomplete_day(): void
    {
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'submit_now' => 1,
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 60],
            ],
        ])->assertSessionHasErrors('submit');

        // The week must not have been submitted.
        $this->assertNull(Timesheet::where('employee_id', $this->employee->id)->where('status', 'submitted')->first());
    }

    public function test_store_with_submit_now_submits_when_every_day_is_100(): void
    {
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'submit_now' => 1,
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertRedirect();

        $this->assertSame('submitted', Timesheet::where('employee_id', $this->employee->id)->first()->status);
    }

    public function test_submitting_an_empty_week_through_store_is_refused(): void
    {
        // No user rows, and no approved leave or public holiday to generate locked rows,
        // so the week is genuinely empty. Submitting it must be refused, not silently
        // create a submitted timesheet with zero entries.
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'submit_now' => true,
            'entries' => [],
        ])->assertStatus(422);

        $this->assertSame(0, Timesheet::where('employee_id', $this->employee->id)->count());
    }

    public function test_category_that_requires_a_project_rejects_a_missing_project(): void
    {
        $needsProject = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true,
        ]);

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $needsProject->id, 'percentage' => 100],
            ],
        ])->assertSessionHasErrors('entries.0.project_id');

        $this->assertNull(Timesheet::where('employee_id', $this->employee->id)->first());
    }

    public function test_a_public_holiday_is_persisted_as_a_locked_entry(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Public Holiday', 'requires_project' => false,
        ]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertRedirect();

        $locked = TimesheetEntry::whereDate('entry_date', '2026-06-17')->first();
        $this->assertNotNull($locked);
        $this->assertSame('holiday', $locked->source);
        $this->assertSame('100.00', (string) $locked->percentage);
    }

    public function test_approved_leave_replaces_work_rows_on_that_day_in_a_draft(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'On Leave', 'requires_project' => false,
        ]);
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-06-17', 'date_to' => '2026-06-17',
            'days' => 1, 'status' => 'approved',
        ]);

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-17', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertRedirect();

        $rows = TimesheetEntry::whereDate('entry_date', '2026-06-17')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('leave', $rows[0]->source);
    }

    public function test_the_date_error_key_matches_the_original_submitted_index(): void
    {
        // Wednesday 2026-06-17 is an approved-leave day, so entry 0 gets dropped by the D4
        // filter. Entry 1 carries a future date. Its error must be keyed to its ORIGINAL
        // index (1), not reindexed to 0 after the drop.
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'On Leave', 'requires_project' => false,
        ]);
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-06-17', 'date_to' => '2026-06-17',
            'days' => 1, 'status' => 'approved',
        ]);

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-17', 'category_id' => $this->category->id, 'percentage' => 100], // dropped by D4
                ['entry_date' => '2026-06-30', 'category_id' => $this->category->id, 'percentage' => 100], // future -> rejected
            ],
        ])->assertSessionHasErrors('entries.1.entry_date');
    }

    public function test_a_draft_may_be_saved_with_no_user_rows(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Public Holiday', 'requires_project' => false,
        ]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, TimesheetEntry::count());
    }

    public function test_the_json_response_carries_the_locked_days(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Public Holiday', 'requires_project' => false,
        ]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);

        $this->actingInTenant()->postJson('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertOk()->assertJsonPath('locked.2026-06-17.source', 'holiday');
    }

    public function test_a_submitted_week_is_never_rewritten_by_later_leave_approval(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'On Leave', 'requires_project' => false,
        ]);
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 8,
        ]);
        TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $sheet->id, 'entry_date' => '2026-06-17',
            'category_id' => $this->category->id, 'percentage' => 100, 'project' => 'Others', 'hours' => 8,
        ]);

        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-06-17', 'date_to' => '2026-06-17',
            'days' => 1, 'status' => 'approved',
        ]);

        // The week is already finalised, so the save is refused outright rather than merged.
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertStatus(422);

        $rows = TimesheetEntry::whereDate('entry_date', '2026-06-17')->get();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->source);
    }

    public function test_cancelling_approved_leave_clears_the_locked_row_on_the_next_save(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'On Leave', 'requires_project' => false,
        ]);
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        $leave = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-06-17', 'date_to' => '2026-06-17',
            'days' => 1, 'status' => 'approved',
        ]);

        $payload = [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ];

        $this->actingInTenant()->post('/app/timesheets', $payload)->assertRedirect();
        $this->assertSame(1, TimesheetEntry::where('source', 'leave')->count());

        $leave->update(['status' => 'rejected']);

        $this->actingInTenant()->post('/app/timesheets', $payload)->assertRedirect();
        $this->assertSame(0, TimesheetEntry::where('source', 'leave')->count());
    }

    public function test_an_entry_dated_after_today_is_rejected(): void
    {
        Carbon::setTestNow('2026-06-17 09:00:00'); // Wednesday of that week

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-19', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertSessionHasErrors('entries.0.entry_date');

        Carbon::setTestNow();
    }

    public function test_an_entry_older_than_the_backfill_window_is_rejected(): void
    {
        Carbon::setTestNow('2026-07-22 09:00:00'); // five weeks after the target week

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertSessionHasErrors('entries.0.entry_date');

        Carbon::setTestNow();
    }

    public function test_an_entry_inside_the_backfill_window_is_accepted(): void
    {
        Carbon::setTestNow('2026-07-01 09:00:00'); // two weeks after the target week

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertSessionHasNoErrors();

        Carbon::setTestNow();
    }
}
