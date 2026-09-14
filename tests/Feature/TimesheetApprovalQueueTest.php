<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetDay;
use App\Tenancy\CurrentTenant;
use App\Timesheet\ApprovalQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Acceptance\AlwaysChecks;
use Tests\TestCase;

/**
 * The manager's timesheet approval queue: who lands in it, and that the "To approve"
 * tab on Timesheet Reports and the dashboard's "Waiting on you" row both read it.
 */
class TimesheetApprovalQueueTest extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private Employee $manager;

    private Employee $staff;

    private TimesheetCategory $others;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 09:00:00');
        $this->manager = $this->person('Kussairi', 'manager');
        $this->staff = $this->person('Shazwan', 'employee', ['reports_to_id' => $this->manager->id]);
        $this->others = TimesheetCategory::create(['tenant_id' => $this->tenant()->id, 'name' => 'Others', 'requires_project' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** One day of $employee's in the given state, with a single 100% line. */
    private function day(Employee $employee, string $iso, string $status = TimesheetDay::STATUS_SUBMITTED, array $attrs = []): TimesheetDay
    {
        $weekStart = Carbon::parse($iso)->startOfWeek();
        $sheet = Timesheet::where('employee_id', $employee->id)->forWeek($weekStart)->first()
            ?? Timesheet::create(['tenant_id' => $this->tenant()->id, 'employee_id' => $employee->id, 'week_start' => $weekStart, 'status' => 'draft']);
        $sheet->entries()->create([
            'tenant_id' => $this->tenant()->id, 'entry_date' => $iso,
            'category_id' => $this->others->id, 'percentage' => 100, 'hours' => 8,
        ]);

        return TimesheetDay::create(array_merge([
            'tenant_id' => $this->tenant()->id, 'timesheet_id' => $sheet->id,
            'entry_date' => $iso, 'status' => $status,
        ], $attrs));
    }

    private function queueFor(Employee $manager): array
    {
        app(CurrentTenant::class)->set($this->tenant());

        return app(ApprovalQueue::class)->forManager($manager);
    }

    public function test_lists_submitted_days_of_direct_reports_oldest_first(): void
    {
        $this->day($this->staff, '2026-09-10');
        $this->day($this->staff, '2026-09-08');
        $this->day($this->staff, '2026-09-09', TimesheetDay::STATUS_APPROVED);
        $this->day($this->staff, '2026-09-11', TimesheetDay::STATUS_RETURNED);

        $queue = $this->queueFor($this->manager);

        $this->assertCount(1, $queue);
        $this->assertSame($this->staff->id, $queue[0]['employee']->id);
        $this->assertSame(['2026-09-08', '2026-09-10'], array_column($queue[0]['days'], 'iso'));
        $this->assertSame(100.0, $queue[0]['days'][0]['percent']);
        $this->assertSame('Others', $queue[0]['days'][0]['lines'][0]['category']);
    }

    public function test_includes_people_on_an_additional_manager_line(): void
    {
        $dotted = $this->person('Aina', 'employee');
        $dotted->additionalManagers()->attach($this->manager->id);
        $this->day($dotted, '2026-09-10');

        $this->assertSame([$dotted->id], array_map(fn ($p) => $p['employee']->id, $this->queueFor($this->manager)));
    }

    public function test_leaves_out_peers_archived_staff_and_the_manager_themself(): void
    {
        $peer = $this->person('Farid', 'employee');
        $this->day($peer, '2026-09-10');
        $archived = $this->person('Nazri', 'employee', ['reports_to_id' => $this->manager->id, 'archived_at' => now()]);
        $this->day($archived, '2026-09-10');
        $this->day($this->manager, '2026-09-10');

        $this->assertSame([], $this->queueFor($this->manager));
    }

    public function test_hr_without_reports_gets_an_empty_queue(): void
    {
        $hr = $this->person('Hidayah', 'hr');
        $this->day($this->staff, '2026-09-10');

        $this->assertSame([], $this->queueFor($hr));
        $this->assertSame(['days' => 0, 'people' => 0, 'oldest' => null], app(ApprovalQueue::class)->summary($hr));
    }

    public function test_resubmitted_day_carries_the_earlier_return_reason(): void
    {
        $this->day($this->staff, '2026-09-10', TimesheetDay::STATUS_SUBMITTED, ['resubmitted' => true, 'return_reason' => 'Split it']);
        $this->day($this->staff, '2026-09-11', TimesheetDay::STATUS_SUBMITTED, ['late' => true]);

        $days = $this->queueFor($this->manager)[0]['days'];

        $this->assertSame('Split it', $days[0]['returnReason']);
        $this->assertTrue($days[1]['late']);
        $this->assertNull($days[1]['returnReason']);
    }

    public function test_to_approve_tab_renders_waiting_days_and_opens_by_default(): void
    {
        $this->day($this->staff, '2026-09-08');
        $this->day($this->staff, '2026-09-09');

        $this->actingInTenantAs($this->manager)->get('/app/timesheet-reports')
            ->assertOk()
            ->assertSee('id="tr-tab-approve"', false)
            ->assertSee("tab: 'approve'", false)
            ->assertSee('data-approval-day="'.$this->staff->id.'|2026-09-08"', false)
            ->assertSee('data-approval-day="'.$this->staff->id.'|2026-09-09"', false);
    }

    public function test_to_approve_tab_is_hidden_when_nothing_is_waiting(): void
    {
        $this->actingInTenantAs($this->manager)->get('/app/timesheet-reports')
            ->assertOk()
            ->assertDontSee('id="tr-tab-approve"', false)
            ->assertSee("tab: 'week'", false);
    }

    public function test_dashboard_shows_a_timesheet_row_while_days_are_waiting(): void
    {
        $this->day($this->staff, '2026-09-08');
        $this->day($this->staff, '2026-09-09');

        $this->actingInTenantAs($this->manager)->get('/app/dash')
            ->assertOk()
            ->assertSee('2 timesheet days from 1 person')
            ->assertSee('tab=approve', false);
    }

    public function test_dashboard_has_no_timesheet_row_when_nothing_is_waiting(): void
    {
        $this->day($this->staff, '2026-09-08', TimesheetDay::STATUS_APPROVED);

        $this->actingInTenantAs($this->manager)->get('/app/dash')
            ->assertOk()
            ->assertDontSee('tab=approve', false);
    }
}
