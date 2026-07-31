<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\TimesheetTemplate;
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

    /** A tenant member with a role and its own user, for driving the approval chain. */
    private function member(string $role, string $name): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
        ]);
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

    public function test_approving_leave_backfills_a_week_already_submitted_before_the_approval(): void
    {
        // The ordering bug: a week is filled and submitted FIRST, then leave for one of its
        // days is approved. Leave→timesheet used to be pull-based, so the stored week kept
        // the staffer's work row on the leave day until a manual re-save. Approval must now
        // reconcile it in place.
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'On Leave', 'requires_project' => false,
        ]);

        // Reporting chain: employee → manager (verifies) → management (final approval).
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $this->employee->update(['reports_to_id' => $manager->id]);

        // A submitted week with ordinary work on Tue 16th and on what becomes the leave day, Wed 17th.
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 16, 'submitted_at' => now(),
        ]);
        foreach (['2026-06-16', '2026-06-17'] as $date) {
            $sheet->entries()->create([
                'tenant_id' => $this->tenant->id, 'entry_date' => $date,
                'category_id' => $this->category->id, 'percentage' => 100, 'project' => 'Others', 'hours' => 8,
            ]);
        }

        // Leave for Wed 17th, verified and waiting on management.
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveBalance::create(['employee_id' => $this->employee->id, 'leave_type_id' => $type->id, 'balance' => 10]);
        $leave = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-06-17', 'date_to' => '2026-06-17',
            'days' => 1, 'status' => 'verified', 'verified_by_id' => $manager->id,
        ]);

        // Act — management approves through the real route (drives applyApproval).
        $this->actingAs($mgmt->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/leave/{$leave->id}/approve")->assertRedirect();

        // The leave day is now a single locked "On Leave" row at 100%.
        $leaveDay = TimesheetEntry::whereDate('entry_date', '2026-06-17')->get();
        $this->assertCount(1, $leaveDay);
        $this->assertSame('leave', $leaveDay[0]->source);
        $this->assertSame('100.00', (string) $leaveDay[0]->percentage);

        // The untouched work day survives, and the week stays submitted (a valid submission
        // stays valid — every populated day still totals 100%).
        $workDay = TimesheetEntry::whereDate('entry_date', '2026-06-16')->get();
        $this->assertCount(1, $workDay);
        $this->assertNull($workDay[0]->source);
        $this->assertSame('submitted', $sheet->fresh()->status);

        // The pre-existing happy path is unchanged: status flips and the balance decrements.
        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertEqualsWithDelta(9.0, (float) LeaveBalance::first()->balance, 0.001);
    }

    public function test_approving_leave_backfills_a_draft_week_saved_before_the_approval(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'On Leave', 'requires_project' => false,
        ]);
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $this->employee->update(['reports_to_id' => $manager->id]);

        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 8,
        ]);
        $sheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-17',
            'category_id' => $this->category->id, 'percentage' => 100, 'project' => 'Others', 'hours' => 8,
        ]);

        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        $leave = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-06-17', 'date_to' => '2026-06-17',
            'days' => 1, 'status' => 'verified', 'verified_by_id' => $manager->id,
        ]);

        $this->actingAs($mgmt->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/leave/{$leave->id}/approve")->assertRedirect();

        $rows = TimesheetEntry::whereDate('entry_date', '2026-06-17')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('leave', $rows[0]->source);
        $this->assertSame('draft', $sheet->fresh()->status);
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

    public function test_the_owner_can_recall_a_submitted_week(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 8,
            'submitted_at' => now(),
        ]);

        $this->actingInTenant()->post("/app/timesheets/{$sheet->id}/recall")->assertRedirect();

        $sheet->refresh();
        $this->assertSame('draft', $sheet->status);
        $this->assertNull($sheet->submitted_at);
    }

    public function test_recalling_a_draft_is_refused(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 0,
        ]);

        $this->actingInTenant()->post("/app/timesheets/{$sheet->id}/recall")->assertStatus(422);
    }

    public function test_a_non_owner_cannot_recall_someone_elses_week(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Someone Else',
            'status' => 'active', 'workload' => 'green',
        ]);
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $other->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 8,
            'submitted_at' => now(),
        ]);

        $this->actingInTenant()->post("/app/timesheets/{$sheet->id}/recall")->assertForbidden();

        $this->assertSame('submitted', $sheet->refresh()->status);
    }

    public function test_the_capture_screen_renders_for_an_employee(): void
    {
        $this->actingInTenant()->get('/app/timesheets?week=2026-06-15')
            ->assertOk()
            ->assertSee('timesheetCapture', false);
    }

    // ---- Per-staff allocation templates -----------------------------------

    public function test_an_owner_saves_a_new_template(): void
    {
        $this->actingInTenant()->post('/app/timesheets/templates', [
            'name' => 'Full-time KDN dev',
            'category_id' => $this->category->id,
            'percentage' => 100,
            'description' => 'Core build work',
        ])->assertRedirect();

        $this->assertDatabaseHas('timesheet_templates', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'name' => 'Full-time KDN dev',
            'category_id' => $this->category->id,
        ]);
    }

    public function test_a_template_without_a_project_or_sub_pillar_saves(): void
    {
        // project_id / sub_pillar_id are nullable — a standalone category needs neither.
        $this->actingInTenant()->post('/app/timesheets/templates', [
            'name' => 'Admin time',
            'category_id' => $this->category->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('timesheet_templates', [
            'employee_id' => $this->employee->id,
            'name' => 'Admin time',
            'project_id' => null,
            'sub_pillar_id' => null,
        ]);
    }

    public function test_saving_a_template_under_an_existing_name_updates_it_in_place(): void
    {
        // updateOrCreate keys on (employee_id, name), backed by that unique index: re-saving
        // the same name must overwrite the existing row, not insert a second one.
        $other = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Meetings', 'requires_project' => false,
        ]);

        $this->actingInTenant()->post('/app/timesheets/templates', [
            'name' => 'My preset', 'category_id' => $this->category->id, 'percentage' => 50,
        ])->assertRedirect();
        $this->actingInTenant()->post('/app/timesheets/templates', [
            'name' => 'My preset', 'category_id' => $other->id, 'percentage' => 80,
        ])->assertRedirect();

        $this->assertSame(1, TimesheetTemplate::where('employee_id', $this->employee->id)->count());
        $this->assertDatabaseHas('timesheet_templates', [
            'employee_id' => $this->employee->id, 'name' => 'My preset', 'category_id' => $other->id,
        ]);
    }

    public function test_store_template_rejects_a_missing_name(): void
    {
        $this->actingInTenant()->post('/app/timesheets/templates', [
            'category_id' => $this->category->id,
        ])->assertSessionHasErrors('name');

        $this->assertSame(0, TimesheetTemplate::count());
    }

    public function test_store_template_rejects_an_unknown_category(): void
    {
        $this->actingInTenant()->post('/app/timesheets/templates', [
            'name' => 'Bad category',
            'category_id' => 999999,
        ])->assertSessionHasErrors('category_id');

        $this->assertSame(0, TimesheetTemplate::count());
    }

    public function test_an_owner_deletes_their_own_template(): void
    {
        $template = TimesheetTemplate::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'name' => 'Scrap me', 'category_id' => $this->category->id,
        ]);

        $this->actingInTenant()->delete("/app/timesheets/templates/{$template->id}")->assertRedirect();

        $this->assertDatabaseMissing('timesheet_templates', ['id' => $template->id]);
    }

    public function test_a_non_owner_cannot_delete_someone_elses_template(): void
    {
        // Same tenant, different owner: the row resolves, then the ownership guard 403s.
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Someone Else',
            'status' => 'active', 'workload' => 'green',
        ]);
        $template = TimesheetTemplate::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $other->id,
            'name' => 'Not yours', 'category_id' => $this->category->id,
        ]);

        $this->actingInTenant()->delete("/app/timesheets/templates/{$template->id}")->assertForbidden();

        $this->assertDatabaseHas('timesheet_templates', ['id' => $template->id]);
    }

    public function test_a_cross_tenant_actor_cannot_delete_another_tenants_template(): void
    {
        // The template lives in tenant A; the actor's active tenant is B. Route-model binding
        // (SubstituteBindings) resolves the row by id before the `tenant` middleware sets the
        // context, so the bind is not tenant-scoped and the row resolves. The ownership guard
        // is what refuses the delete: the intruder's employee is not the template's owner (403).
        $template = TimesheetTemplate::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'name' => 'Tenant A preset', 'category_id' => $this->category->id,
        ]);

        $otherTenant = Tenant::create(['slug' => 'globex', 'name' => 'Globex', 'initials' => 'GX']);
        $intruder = User::create(['name' => 'Intruder', 'email' => 'intruder@example.com', 'password' => Hash::make('password')]);
        $intruder->tenants()->attach($otherTenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $otherTenant->id, 'user_id' => $intruder->id,
            'name' => 'Intruder', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($intruder)->withSession(['current_tenant' => $otherTenant->id])
            ->delete("/app/timesheets/templates/{$template->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('timesheet_templates', ['id' => $template->id]);
    }
}
