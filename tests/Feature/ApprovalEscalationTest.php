<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Approval shortcut: a leave or claim request the manager has not verified after the
 * company's `approval_escalation_days` (default 3) may be approved directly by HR or a
 * director. Overtime is not covered, and the requester's own manager must still verify.
 */
class ApprovalEscalationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    private Employee $manager;

    private Employee $director;

    private Employee $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->director = $this->member('management', 'Director');
        $this->manager = $this->member('manager', 'Busy Manager', $this->director->id);
        $this->report = $this->member('employee', 'Reportee', $this->manager->id);
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

    private function claim(Employee $employee): Claim
    {
        return Claim::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'type' => 'expense', 'title' => 'Mileage', 'amount' => 120.50, 'date' => '2026-06-20',
            'status' => 'submitted',
        ]);
    }

    public function test_director_approves_an_overdue_claim_directly(): void
    {
        $claim = $this->claim($this->report);
        $this->travel(4)->days();

        $this->actingAsEmployee($this->director)->post(route('claims.approve', $claim))->assertRedirect();

        $fresh = $claim->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertNull($fresh->verified_by_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Approved claim without verification (manager did not verify in time)']);
    }

    public function test_director_approves_overdue_leave_and_the_balance_goes_down(): void
    {
        $annual = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 16]);
        $balance = $this->report->leaveBalances()->create(['leave_type_id' => $annual->id, 'balance' => 10]);
        $leave = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->report->id, 'leave_type_id' => $annual->id,
            'date_from' => '2026-10-20', 'date_to' => '2026-10-21', 'days' => 2, 'status' => 'submitted',
        ]);
        $this->travel(4)->days();

        $this->actingAsEmployee($this->director)->post(route('leave.approve', $leave))->assertRedirect();

        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertEqualsWithDelta(8.0, (float) $balance->fresh()->balance, 0.001);
    }

    public function test_overdue_leave_shows_as_approvable_on_the_leave_screen(): void
    {
        $annual = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 16]);
        $leave = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->report->id, 'leave_type_id' => $annual->id,
            'date_from' => '2026-10-20', 'date_to' => '2026-10-20', 'days' => 1, 'status' => 'submitted',
        ]);
        $this->travel(4)->days();

        $this->actingAsEmployee($this->director)->get('/app/leave?tab=approvals')->assertOk()
            ->assertSee('Manager has not verified')
            ->assertSee(route('leave.approve', $leave), false);
    }

    public function test_bulk_approve_picks_up_overdue_leave(): void
    {
        $annual = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 16]);
        $this->report->leaveBalances()->create(['leave_type_id' => $annual->id, 'balance' => 10]);
        $leave = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->report->id, 'leave_type_id' => $annual->id,
            'date_from' => '2026-10-20', 'date_to' => '2026-10-20', 'days' => 1, 'status' => 'submitted',
        ]);
        $this->travel(4)->days();

        $this->actingAsEmployee($this->director)->post(route('leave.bulk-approve'), ['ids' => [$leave->id]])->assertRedirect();

        $this->assertSame('approved', $leave->fresh()->status);
    }

    public function test_before_the_wait_is_up_the_director_cannot_skip_the_manager(): void
    {
        $claim = $this->claim($this->report);
        $this->travel(2)->days();

        $this->actingAsEmployee($this->director)->post(route('claims.approve', $claim))->assertStatus(422);
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_the_shortcut_can_be_turned_off(): void
    {
        $this->tenant->update(['approval_escalation_days' => null]);
        $claim = $this->claim($this->report);
        $this->travel(30)->days();

        $this->actingAsEmployee($this->director)->post(route('claims.approve', $claim))->assertStatus(422);
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_the_requesters_own_manager_must_verify_not_approve(): void
    {
        // The busy manager's own claim sits with the director (their manager) to verify.
        $claim = $this->claim($this->manager);
        $this->travel(4)->days();

        $this->actingAsEmployee($this->director)->post(route('claims.approve', $claim))->assertStatus(422);
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_a_plain_manager_still_cannot_give_final_approval(): void
    {
        $other = $this->member('employee', 'Other', $this->director->id);
        $claim = $this->claim($other);
        $this->travel(4)->days();

        $this->actingAsEmployee($this->manager)->post(route('claims.approve', $claim))->assertForbidden();
    }

    public function test_overdue_claim_shows_in_the_approve_queue_but_overtime_does_not(): void
    {
        $this->claim($this->report);
        $overtime = OvertimeRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->report->id,
            'ot_date' => '2026-06-20', 'hours' => 4, 'rate_multiplier' => '1.50', 'reason' => 'Backlog',
            'status' => 'submitted',
        ]);
        $this->travel(4)->days();

        $this->actingAsEmployee($this->director)->get('/app/claim-approvals')->assertOk()
            ->assertSee('Waiting for final approval')->assertSee('manager has not verified');

        $this->actingAsEmployee($this->director)->post(route('overtime.approve', $overtime))->assertStatus(422);
        $this->assertSame('submitted', $overtime->fresh()->status);
    }

    public function test_hr_sets_the_wait_on_company_settings(): void
    {
        $hr = $this->member('hr', 'HR');

        $this->actingAsEmployee($hr)->post(route('admin.approval-escalation.update'), ['approval_escalation_days' => 5])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(5, $this->tenant->fresh()->approval_escalation_days);

        $this->actingAsEmployee($hr)->post(route('admin.approval-escalation.update'), ['approval_escalation_days' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->tenant->fresh()->approval_escalation_days);

        $this->actingAsEmployee($hr)->post(route('admin.approval-escalation.update'), ['approval_escalation_days' => 0])
            ->assertSessionHasErrors('approval_escalation_days');
    }

    public function test_staff_cannot_change_the_wait(): void
    {
        $this->actingAsEmployee($this->report)->post(route('admin.approval-escalation.update'), ['approval_escalation_days' => 1])
            ->assertForbidden();
        $this->assertSame(3, $this->tenant->fresh()->approval_escalation_days);
    }
}
