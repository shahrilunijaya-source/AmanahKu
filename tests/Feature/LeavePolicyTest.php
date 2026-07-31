<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Leave policy: Annual is planned leave that needs advance notice; Emergency is
 * unplanned, is not its own entitlement, and deducts from the Annual balance.
 * Plus the management/HR leave report and its access gate.
 */
class LeavePolicyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private LeaveType $annual;

    private LeaveType $emergency;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->annual = LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 16, 'min_notice_days' => 3,
        ]);
        $this->emergency = LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Emergency', 'entitlement' => 0,
            'is_unplanned' => true, 'deducts_from_leave_type_id' => $this->annual->id,
        ]);
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

    // --- Emergency deducts Annual -------------------------------------------

    public function test_approving_emergency_leave_deducts_the_annual_balance(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        LeaveBalance::create(['employee_id' => $report->id, 'leave_type_id' => $this->annual->id, 'balance' => 10]);

        $req = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id, 'leave_type_id' => $this->emergency->id,
            'date_from' => '2026-07-01', 'date_to' => '2026-07-02', 'days' => 2,
            'status' => 'verified', 'verified_by_id' => $manager->id,
        ]);

        $this->actingAsEmployee($mgmt)->post("/app/leave/{$req->id}/approve")->assertRedirect();

        // Annual dropped 10 → 8; no separate Emergency balance was created or touched.
        $this->assertEqualsWithDelta(8.0, (float) LeaveBalance::where('leave_type_id', $this->annual->id)->value('balance'), 0.001);
        $this->assertDatabaseMissing('leave_balances', ['leave_type_id' => $this->emergency->id]);
    }

    // --- Decision chronology ------------------------------------------------

    public function test_verify_then_approve_records_the_full_decision_trail(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        LeaveBalance::create(['employee_id' => $report->id, 'leave_type_id' => $this->annual->id, 'balance' => 10]);
        $req = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id, 'leave_type_id' => $this->annual->id,
            'date_from' => now()->addDays(5)->toDateString(), 'date_to' => now()->addDays(6)->toDateString(),
            'days' => 2, 'status' => 'submitted',
        ]);

        $this->actingAsEmployee($manager)->post("/app/leave/{$req->id}/verify")->assertRedirect();
        $this->actingAsEmployee($mgmt)->post("/app/leave/{$req->id}/approve")->assertRedirect();

        $fresh = $req->fresh();
        $this->assertSame($manager->id, $fresh->verified_by_id);
        $this->assertNotNull($fresh->verified_at);
        $this->assertSame($mgmt->id, $fresh->approved_by_id);
        $this->assertNotNull($fresh->approved_at);
    }

    public function test_rejecting_records_who_and_when(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id, 'leave_type_id' => $this->annual->id,
            'date_from' => now()->addDays(5)->toDateString(), 'date_to' => now()->addDays(6)->toDateString(),
            'days' => 2, 'status' => 'submitted',
        ]);

        $this->actingAsEmployee($manager)->post("/app/leave/{$req->id}/reject")->assertRedirect();

        $fresh = $req->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame($manager->id, $fresh->rejected_by_id);
        $this->assertNotNull($fresh->rejected_at);
    }

    // --- Advance notice on planned leave ------------------------------------

    public function test_annual_leave_requires_three_days_notice(): void
    {
        $report = $this->member('employee', 'Reportee', $this->member('manager', 'Mgr')->id);

        // Starting tomorrow is inside the 3-day window → rejected.
        $this->actingAsEmployee($report)->post('/app/leave', [
            'leave_type_id' => $this->annual->id,
            'date_from' => now()->addDay()->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('date_from');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_annual_leave_with_enough_notice_is_accepted(): void
    {
        $report = $this->member('employee', 'Reportee', $this->member('manager', 'Mgr')->id);

        $this->actingAsEmployee($report)->post('/app/leave', [
            'leave_type_id' => $this->annual->id,
            'date_from' => now()->addDays(4)->toDateString(),
            'date_to' => now()->addDays(5)->toDateString(),
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertDatabaseCount('leave_requests', 1);
    }

    public function test_emergency_leave_bypasses_the_notice_rule(): void
    {
        $report = $this->member('employee', 'Reportee', $this->member('manager', 'Mgr')->id);

        // Same-day start is fine for unplanned leave — that is its purpose.
        $this->actingAsEmployee($report)->post('/app/leave', [
            'leave_type_id' => $this->emergency->id,
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertDatabaseCount('leave_requests', 1);
    }

    // --- Bulk verify / approve ----------------------------------------------

    private function submittedFor(Employee $e, string $status = 'submitted', ?int $verifiedById = null): LeaveRequest
    {
        return LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'leave_type_id' => $this->annual->id,
            'date_from' => now()->addDays(5)->toDateString(), 'date_to' => now()->addDays(6)->toDateString(),
            'days' => 2, 'status' => $status, 'verified_by_id' => $verifiedById,
        ]);
    }

    public function test_bulk_verify_only_touches_the_actors_own_reports(): void
    {
        $manager = $this->member('manager', 'Manager');
        $a = $this->member('employee', 'Report A', $manager->id);
        $b = $this->member('employee', 'Report B', $manager->id);
        $stranger = $this->member('employee', 'Stranger'); // reports to nobody
        $ra = $this->submittedFor($a);
        $rb = $this->submittedFor($b);
        $rs = $this->submittedFor($stranger);

        $this->actingAsEmployee($manager)->post('/app/leave/bulk-verify', ['ids' => [$ra->id, $rb->id, $rs->id]])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('verified', $ra->fresh()->status);
        $this->assertSame('verified', $rb->fresh()->status);
        $this->assertSame('submitted', $rs->fresh()->status); // outside the manager's queue
    }

    public function test_bulk_approve_respects_segregation_of_duties(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        LeaveBalance::create(['employee_id' => $report->id, 'leave_type_id' => $this->annual->id, 'balance' => 20]);

        $clean = $this->submittedFor($report, 'verified', $manager->id);          // ok to approve
        $selfVerified = $this->submittedFor($report, 'verified', $mgmt->id);       // mgmt verified it → cannot approve
        $ownRequest = $this->submittedFor($mgmt, 'verified', $manager->id);        // mgmt's own request → cannot approve

        $this->actingAsEmployee($mgmt)->post('/app/leave/bulk-approve', ['ids' => [$clean->id, $selfVerified->id, $ownRequest->id]])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('approved', $clean->fresh()->status);
        $this->assertSame('verified', $selfVerified->fresh()->status);
        $this->assertSame('verified', $ownRequest->fresh()->status);
        // Only the clean request drew down the balance.
        $this->assertEqualsWithDelta(18.0, (float) LeaveBalance::where('employee_id', $report->id)->value('balance'), 0.001);
    }

    public function test_bulk_verify_requires_at_least_one_id(): void
    {
        $manager = $this->member('manager', 'Manager');
        $this->actingAsEmployee($manager)->post('/app/leave/bulk-verify', ['ids' => []])->assertStatus(422);
    }

    // --- Report + access gate -----------------------------------------------

    public function test_management_can_view_the_leave_report(): void
    {
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee');
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id, 'leave_type_id' => $this->emergency->id,
            'date_from' => now()->startOfYear()->addDays(10)->toDateString(),
            'date_to' => now()->startOfYear()->addDays(11)->toDateString(),
            'days' => 2, 'status' => 'approved',
        ]);

        $this->actingAsEmployee($mgmt)->get('/app/leave-report')->assertOk()
            ->assertSee('By staff')
            ->assertSee('Reportee');
    }

    public function test_a_plain_employee_cannot_view_the_leave_report(): void
    {
        $loner = $this->member('employee', 'Loner'); // no direct reports

        $this->actingAsEmployee($loner)->get('/app/leave-report')->assertForbidden();
    }
}
