<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Timesheet\LockedDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Half-day leave end-to-end: a single-day request may cover only the morning or the
 * afternoon. It counts as 0.5 against the balance and locks only 50% of the timesheet
 * day, leaving the staffer to fill the other half with real work.
 */
class HalfDayLeaveTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private LeaveType $annual;

    private TimesheetCategory $onLeave;

    private TimesheetCategory $work;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Friday of the week starting Mon 2026-07-20, so a Wed leave day is in the past
        // and inside the timesheet backfill window.
        Carbon::setTestNow('2026-07-24 12:00:00');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        // min_notice_days 0 so the apply endpoint accepts a same-week date for the test.
        $this->annual = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 16]);
        // LockedDays files its generated rows under these fixed category names.
        $this->onLeave = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'On Leave', 'requires_project' => false]);
        $this->work = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Others', 'requires_project' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $role, string $name, ?int $reportsToId = null): Employee
    {
        $this->seq++;
        $user = User::create(['name' => $name, 'email' => "user{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId,
        ]);
    }

    private function actingAsEmployee(Employee $e): self
    {
        $this->actingAs($e->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    /**
     * The whole pipeline: apply a morning half day, verify, approve, and confirm the
     * balance drops by exactly 0.5 while LockedDays emits a 50% locked row that a real
     * work half can complete to 100%.
     */
    public function test_half_day_request_decrements_half_and_locks_half(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        LeaveBalance::create(['employee_id' => $report->id, 'leave_type_id' => $this->annual->id, 'balance' => 10]);

        // --- apply (morning of Wed 2026-07-22) ---
        $this->actingAsEmployee($report)->post('/app/leave', [
            'leave_type_id' => $this->annual->id,
            'date_from' => '2026-07-22',
            'date_to' => '2026-07-22',
            'half_day_period' => 'am',
        ])->assertSessionHasNoErrors();

        $req = LeaveRequest::firstWhere('employee_id', $report->id);
        $this->assertNotNull($req);
        $this->assertSame('am', $req->half_day_period);
        $this->assertEqualsWithDelta(0.5, (float) $req->days, 0.001);
        $this->assertTrue($req->isHalfDay());

        // --- verify + approve ---
        $this->actingAsEmployee($manager)->post("/app/leave/{$req->id}/verify")->assertRedirect();
        $this->actingAsEmployee($mgmt)->post("/app/leave/{$req->id}/approve")->assertRedirect();
        $this->assertSame('approved', $req->fresh()->status);

        // --- balance dropped by 0.5, not 1.0 ---
        $this->assertEqualsWithDelta(9.5, (float) LeaveBalance::first()->balance, 0.001);

        // --- LockedDays emits a 50% row for that day ---
        $locked = app(LockedDays::class)->forWeek($report, '2026-07-20');
        $this->assertArrayHasKey('2026-07-22', $locked);
        $this->assertSame('leave', $locked['2026-07-22']['source']);
        $this->assertEqualsWithDelta(50.0, $locked['2026-07-22']['percentage'], 0.001);
        $this->assertSame('am', $locked['2026-07-22']['period']);

        $rows = app(LockedDays::class)->entryRows($report, '2026-07-20');
        $leaveRow = collect($rows)->firstWhere('entry_date', '2026-07-22');
        $this->assertEqualsWithDelta(50.0, (float) $leaveRow['percentage'], 0.001);

        // --- the staffer fills the other 50% and the day submits cleanly ---
        $this->actingAsEmployee($report)->post('/app/timesheets', [
            'week_start' => '2026-07-20',
            'submit_now' => true,
            'entries' => [
                ['entry_date' => '2026-07-22', 'category_id' => $this->work->id, 'percentage' => 50],
            ],
        ])->assertRedirect()->assertSessionHas('ok');

        $sheet = Timesheet::firstWhere('employee_id', $report->id);
        $this->assertNotNull($sheet);
        $this->assertSame('submitted', $sheet->status);

        $dayEntries = TimesheetEntry::where('timesheet_id', $sheet->id)->whereDate('entry_date', '2026-07-22')->get();
        // Exactly two rows: the staffer's 50% work + the generated 50% leave.
        $this->assertEqualsWithDelta(100.0, (float) $dayEntries->sum('percentage'), 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $dayEntries->firstWhere('source', 'leave')->percentage, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $dayEntries->firstWhere('source', null)->percentage, 0.001);
    }

    /** A whole-day leave still locks the full day at 100% — the pre-existing behaviour. */
    public function test_full_day_leave_still_locks_the_whole_day(): void
    {
        $report = $this->member('employee', 'Reportee');
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id, 'leave_type_id' => $this->annual->id,
            'date_from' => '2026-07-22', 'date_to' => '2026-07-22', 'days' => 1, 'status' => 'approved',
        ]);

        $locked = app(LockedDays::class)->forWeek($report, '2026-07-20');
        $this->assertEqualsWithDelta(100.0, $locked['2026-07-22']['percentage'], 0.001);
        $this->assertNull($locked['2026-07-22']['period']);

        // A staffer-typed row on a fully locked day is dropped; only the 100% leave row survives.
        $this->actingAsEmployee($report)->post('/app/timesheets', [
            'week_start' => '2026-07-20',
            'entries' => [
                ['entry_date' => '2026-07-22', 'category_id' => $this->work->id, 'percentage' => 100],
            ],
        ])->assertRedirect();

        $sheet = Timesheet::firstWhere('employee_id', $report->id);
        $dayEntries = TimesheetEntry::where('timesheet_id', $sheet->id)->whereDate('entry_date', '2026-07-22')->get();
        $this->assertCount(1, $dayEntries);
        $this->assertSame('leave', $dayEntries->first()->source);
        $this->assertEqualsWithDelta(100.0, (float) $dayEntries->first()->percentage, 0.001);
    }

    /**
     * Composition with the leave→timesheet back-fill (WeekReconciler): a week saved BEFORE
     * a half-day leave is approved gains the 50% "On Leave" row on approval, and the
     * staffer's already-typed work-half is preserved rather than dropped.
     */
    public function test_approving_half_day_backfills_a_saved_week_and_keeps_the_work_half(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        LeaveBalance::create(['employee_id' => $report->id, 'leave_type_id' => $this->annual->id, 'balance' => 10]);

        // Verified (not yet approved) half day for Wed 2026-07-22, so the day is not locked
        // when the staffer saves — their 50% work stands alone as a draft.
        $req = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id, 'leave_type_id' => $this->annual->id,
            'date_from' => '2026-07-22', 'date_to' => '2026-07-22', 'half_day_period' => 'am',
            'days' => 0.5, 'status' => 'verified', 'verified_by_id' => $manager->id,
        ]);

        $this->actingAsEmployee($report)->post('/app/timesheets', [
            'week_start' => '2026-07-20',
            'entries' => [
                ['entry_date' => '2026-07-22', 'category_id' => $this->work->id, 'percentage' => 50],
            ],
        ])->assertRedirect();

        // Before approval: just the 50% work row, no leave row.
        $sheet = Timesheet::firstWhere('employee_id', $report->id);
        $this->assertSame(1, TimesheetEntry::where('timesheet_id', $sheet->id)->whereDate('entry_date', '2026-07-22')->count());

        // Approval back-fills the stored week with the 50% leave row.
        $this->actingAsEmployee($mgmt)->post("/app/leave/{$req->id}/approve")->assertRedirect();
        $this->assertEqualsWithDelta(9.5, (float) LeaveBalance::first()->balance, 0.001);

        $dayEntries = TimesheetEntry::where('timesheet_id', $sheet->id)->whereDate('entry_date', '2026-07-22')->get();
        $this->assertEqualsWithDelta(100.0, (float) $dayEntries->sum('percentage'), 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $dayEntries->firstWhere('source', 'leave')->percentage, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $dayEntries->firstWhere('source', null)->percentage, 0.001);
    }

    /** A half-day marker is rejected on a multi-day range — you cannot half-day a span. */
    public function test_half_day_rejected_for_multi_day_range(): void
    {
        $report = $this->member('employee', 'Reportee');

        $this->actingAsEmployee($report)->post('/app/leave', [
            'leave_type_id' => $this->annual->id,
            'date_from' => '2026-07-22',
            'date_to' => '2026-07-23',
            'half_day_period' => 'am',
        ])->assertSessionHasErrors('half_day_period');

        $this->assertDatabaseCount('leave_requests', 0);
    }
}
