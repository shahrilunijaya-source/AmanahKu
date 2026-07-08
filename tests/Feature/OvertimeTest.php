<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Overtime (OT) requests module.
 * Harness (setUp / actingInTenant / hrActor) copied from LoanTest.
 */
class OvertimeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

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
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function hrActor(): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $hr->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $hr;
    }

    public function test_employee_submits_an_overtime_request(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/overtime', [
            'ot_date' => '2026-06-20',
            'hours' => 4,
            'rate_multiplier' => '1.50',
            'reason' => 'Month-end closing',
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('overtime_requests', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'rate_multiplier' => '1.50',
            'status' => 'submitted',
        ]);
    }

    public function test_validation_rejects_a_non_positive_hours_value(): void
    {
        $this->actingInTenant()->post('/app/overtime', [
            'ot_date' => '2026-06-20',
            'hours' => 0,
            'rate_multiplier' => '1.50',
            'reason' => 'Nope',
        ])->assertSessionHasErrors('hours');
    }

    public function test_management_approves_a_verified_request(): void
    {
        // Arrange — a manager verified it, now management gives final approval.
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $overtime = OvertimeRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'ot_date' => '2026-06-20', 'hours' => 4, 'rate_multiplier' => '1.50', 'reason' => 'Backlog',
            'status' => 'verified', 'verified_by_id' => $manager->id,
        ]);

        // Act
        $response = $this->actingAs($mgmt->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/overtime/{$overtime->id}/approve");

        // Assert
        $response->assertRedirect();
        $fresh = $overtime->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($mgmt->id, $fresh->decided_by_id);
        $this->assertNotNull($fresh->decided_at);
    }

    public function test_management_rejects_a_verified_request(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $overtime = OvertimeRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'ot_date' => '2026-06-20', 'hours' => 3, 'rate_multiplier' => '2.00', 'reason' => 'Weekend',
            'status' => 'verified', 'verified_by_id' => $manager->id,
        ]);

        $this->actingAs($mgmt->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/overtime/{$overtime->id}/reject")->assertRedirect();

        $this->assertSame('rejected', $overtime->fresh()->status);
    }

    public function test_immediate_superior_can_verify(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $overtime = OvertimeRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'ot_date' => '2026-06-20', 'hours' => 3, 'rate_multiplier' => '1.50', 'reason' => 'Backlog', 'status' => 'submitted',
        ]);

        $this->actingAs($manager->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/overtime/{$overtime->id}/verify")->assertRedirect();

        $this->assertSame('verified', $overtime->fresh()->status);
    }

    public function test_plain_employee_cannot_approve_a_request(): void
    {
        // Arrange
        $overtime = OvertimeRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'ot_date' => '2026-06-20', 'hours' => 4, 'rate_multiplier' => '1.50', 'reason' => 'Backlog', 'status' => 'submitted',
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/overtime/{$overtime->id}/approve");

        // Assert
        $response->assertForbidden();
        $this->assertSame('submitted', $overtime->fresh()->status);
    }

    public function test_approver_cannot_decide_their_own_request(): void
    {
        // Arrange — the HR actor submits their own overtime, then tries to self-approve.
        $hr = $this->hrActor();
        $hrEmployee = Employee::where('user_id', $hr->id)->firstOrFail();
        $overtime = OvertimeRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $hrEmployee->id,
            'ot_date' => '2026-06-20', 'hours' => 4, 'rate_multiplier' => '1.50', 'reason' => 'Own OT', 'status' => 'submitted',
        ]);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/overtime/{$overtime->id}/approve");

        // Assert — segregation of duties blocks self-approval.
        $response->assertForbidden();
        $this->assertSame('submitted', $overtime->fresh()->status);
    }

    public function test_cannot_approve_an_already_decided_request(): void
    {
        // Arrange — already approved; management tries to approve again.
        $mgmt = $this->member('management', 'Director');
        $overtime = OvertimeRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'ot_date' => '2026-06-20', 'hours' => 4, 'rate_multiplier' => '1.50', 'reason' => 'Backlog', 'status' => 'approved',
        ]);

        // Act — status is no longer 'verified', so approval is rejected with 422.
        $this->actingAs($mgmt->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/overtime/{$overtime->id}/approve")->assertStatus(422);
    }

    // --- Reporting-line routing ---------------------------------------------

    /** A tenant member with a linked employee record and optional manager. */
    private function member(string $role, string $name, ?int $reportsToId = null): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId,
        ]);
    }

    public function test_a_manager_cannot_give_final_approval(): void
    {
        // The manager verifies; only management approves. A verified request handed to the
        // verifying manager for approval is refused.
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $ot = OvertimeRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'ot_date' => '2026-06-20', 'hours' => 3, 'rate_multiplier' => '1.50', 'reason' => 'Backlog',
            'status' => 'verified', 'verified_by_id' => $manager->id,
        ]);

        $this->actingAs($manager->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/overtime/{$ot->id}/approve")->assertForbidden();

        $this->assertSame('verified', $ot->fresh()->status);
    }

    public function test_a_non_superior_cannot_verify(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $otherManager = $this->member('manager', 'Other Manager');
        $ot = OvertimeRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'ot_date' => '2026-06-20', 'hours' => 3, 'rate_multiplier' => '1.50', 'reason' => 'Backlog', 'status' => 'submitted',
        ]);

        $this->actingAs($otherManager->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/overtime/{$ot->id}/verify")->assertForbidden();

        $this->assertSame('submitted', $ot->fresh()->status);
    }

    public function test_submitting_overtime_notifies_the_superior_to_verify(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);

        $this->actingAs($report->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/overtime', [
                'ot_date' => '2026-06-20', 'hours' => 4, 'rate_multiplier' => '1.50', 'reason' => 'Month-end',
            ])->assertRedirect();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $manager->user_id,
            'title' => 'Overtime awaiting your verification',
        ]);
    }
}
