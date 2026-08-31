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
use App\Models\User;
use App\Models\WorkItem;
use App\Timesheet\WeekWriter;
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

    private TimesheetCategory $otherCategory;

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
        // A second standalone category, so a test can put two DIFFERENT lines on one day.
        $this->otherCategory = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Study & Research', 'requires_project' => false,
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

    public function test_store_with_submit_now_is_blocked_before_the_week_ends(): void
    {
        Carbon::setTestNow('2026-06-17 09:00:00'); // Wednesday of that week

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'submit_now' => 1,
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertSessionHasErrors('submit');

        $this->assertNull(Timesheet::where('employee_id', $this->employee->id)->where('status', 'submitted')->first());

        Carbon::setTestNow('2026-06-19 12:00:00');
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

    /**
     * Every day that does not add up, in one refusal. The check used to throw inside its
     * loop, so a week with three short days cost three submits to find out about all
     * three — fix one, be told about the next, repeat.
     */
    public function test_store_with_submit_now_names_every_incomplete_day_at_once(): void
    {
        $response = $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'submit_now' => 1,
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 60],
                ['entry_date' => '2026-06-16', 'category_id' => $this->category->id, 'percentage' => 40],
                ['entry_date' => '2026-06-17', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ]);

        $response->assertSessionHasErrors('submit');
        $messages = session('errors')->get('submit');

        $this->assertCount(2, $messages);
        $this->assertStringContainsString('Mon, 15 Jun', $messages[0]);
        $this->assertStringContainsString('Tue, 16 Jun', $messages[1]);
        // The day that was fine is not named.
        $this->assertStringNotContainsString('17 Jun', implode(' ', $messages));
    }

    /**
     * Same again for the rows themselves: two days each missing the project their category
     * demands are one refusal naming both, not one save each.
     */
    public function test_store_names_every_row_missing_its_project_at_once(): void
    {
        $needsProject = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Delivery',
            'requires_project' => true,
        ]);

        $response = $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $needsProject->id, 'percentage' => 100],
                ['entry_date' => '2026-06-16', 'category_id' => $needsProject->id, 'percentage' => 100],
            ],
        ]);

        $response->assertSessionHasErrors(['entries.0.project_id', 'entries.1.project_id']);
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

    /**
     * A line the staffer has added but not yet costed must survive a reload. It used to be
     * dropped client-side before the POST, so refreshing the page silently removed it.
     */
    public function test_a_draft_keeps_a_line_that_has_no_percentage_yet(): void
    {
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 60],
                ['entry_date' => '2026-06-15', 'category_id' => $this->otherCategory->id, 'percentage' => 0, 'description' => 'Read the spec'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, TimesheetEntry::count());
        $this->assertSame('0.00', (string) TimesheetEntry::where('description', 'Read the spec')->first()->percentage);
    }

    public function test_submitting_a_week_with_an_uncosted_line_is_refused(): void
    {
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'submit_now' => true,
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
                ['entry_date' => '2026-06-15', 'category_id' => $this->otherCategory->id, 'percentage' => 0],
            ],
        ])->assertSessionHasErrors('submit');

        $this->assertNull(Timesheet::where('employee_id', $this->employee->id)->first());
    }

    /**
     * Two identical lines on one day are impossible to tell apart once saved, and the day
     * total silently doubles. The picker greys out what a day already carries, but a stale
     * tab can still post the pair.
     */
    public function test_the_same_work_twice_on_one_day_is_refused(): void
    {
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 50],
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 50],
            ],
        ])->assertSessionHasErrors('entries');

        $this->assertNull(Timesheet::where('employee_id', $this->employee->id)->first());
    }

    public function test_the_same_work_on_two_different_days_is_fine(): void
    {
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
                ['entry_date' => '2026-06-16', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, TimesheetEntry::count());
    }

    public function test_a_percentage_above_100_is_refused(): void
    {
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 999],
            ],
        ])->assertSessionHasErrors('entries.0.percentage');
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
        Carbon::setTestNow('2026-08-05 09:00:00'); // seven weeks after the target week, past the six-week window

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

    /**
     * Dismissals: the capture screen strikes a board card off a day, and it has to stay
     * struck off — the prefill would otherwise rebuild the row from the card's stints on
     * the next load.
     */
    public function test_a_save_stores_the_cards_struck_off_a_day(): void
    {
        $card = WorkItem::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'Struck off', 'type' => 'task', 'status' => 'prog',
        ]);

        $this->actingInTenant()->postJson('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [[
                'entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100,
            ]],
            'dismissed' => ['2026-06-16' => [$card->id]],
        ])->assertOk();

        $this->assertSame(
            ['2026-06-16' => [$card->id]],
            Timesheet::where('employee_id', $this->employee->id)->first()->dismissed_suggestions,
        );
    }

    public function test_a_dismissal_naming_someone_elses_card_is_dropped(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Someone Else',
            'status' => 'active', 'workload' => 'green',
        ]);
        $theirs = WorkItem::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $other->id,
            'title' => 'Not mine', 'type' => 'task', 'status' => 'prog',
        ]);

        $this->actingInTenant()->postJson('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [[
                'entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100,
            ]],
            'dismissed' => ['2026-06-16' => [$theirs->id], '2026-07-20' => [$theirs->id]],
        ])->assertOk();

        $this->assertNull(Timesheet::where('employee_id', $this->employee->id)->first()->dismissed_suggestions);
    }

    /** A save that says nothing about dismissals (the MCP tool, leave reconcile) keeps them. */
    public function test_a_save_without_a_dismissed_key_leaves_stored_dismissals_alone(): void
    {
        $card = WorkItem::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'Struck off', 'type' => 'task', 'status' => 'prog',
        ]);

        $this->actingInTenant()->postJson('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [[
                'entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100,
            ]],
            'dismissed' => ['2026-06-16' => [$card->id]],
        ])->assertOk();

        app(WeekWriter::class)->save($this->employee, '2026-06-15', [[
            'entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 50,
        ]], null, false);

        $this->assertSame(
            ['2026-06-16' => [$card->id]],
            Timesheet::where('employee_id', $this->employee->id)->first()->fresh()->dismissed_suggestions,
        );
    }
}
