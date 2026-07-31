<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftSwap;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Shift swap & cover module.
 * Harness (setUp / actingInTenant / hrActor) copied from LoanTest.
 */
class ShiftSwapTest extends TestCase
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

    /** Roster owns shift creation; tests build a fixture shift to swap. */
    private function shiftFor(Employee $employee): Shift
    {
        return Shift::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '18:00',
            'location' => 'PJ HQ',
            'status' => 'scheduled',
        ]);
    }

    public function test_employee_requests_a_swap_on_their_own_shift(): void
    {
        // Arrange
        $shift = $this->shiftFor($this->employee);

        // Act
        $response = $this->actingInTenant()->post('/app/shiftswap', [
            'shift_id' => $shift->id,
            'reason' => 'Doctor appointment',
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('shift_swaps', [
            'tenant_id' => $this->tenant->id,
            'shift_id' => $shift->id,
            'requester_employee_id' => $this->employee->id,
            'status' => 'requested',
        ]);
    }

    public function test_employee_cannot_request_a_swap_on_a_shift_they_do_not_own(): void
    {
        // Arrange — a colleague's shift.
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);
        $shift = $this->shiftFor($colleague);

        // Act
        $response = $this->actingInTenant()->post('/app/shiftswap', [
            'shift_id' => $shift->id,
            'reason' => 'Not mine',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('shift_swaps', ['shift_id' => $shift->id]);
    }

    public function test_named_counterpart_accepts_a_swap(): void
    {
        // Arrange
        $shift = $this->shiftFor($this->employee);
        $colleagueUser = User::create(['name' => 'Mate', 'email' => 'mate@example.com', 'password' => Hash::make('password')]);
        $colleagueUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $colleagueUser->id,
            'name' => 'Mate', 'status' => 'active', 'workload' => 'green',
        ]);
        $swap = ShiftSwap::create([
            'tenant_id' => $this->tenant->id, 'shift_id' => $shift->id,
            'requester_employee_id' => $this->employee->id,
            'counterpart_employee_id' => $colleague->id, 'status' => 'requested',
        ]);

        // Act
        $response = $this->actingAs($colleagueUser)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/shiftswap/{$swap->id}/accept");

        // Assert
        $response->assertRedirect();
        $this->assertSame('accepted', $swap->fresh()->status);
    }

    public function test_privileged_approval_reassigns_the_shift(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $shift = $this->shiftFor($this->employee);
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Mate', 'status' => 'active', 'workload' => 'green',
        ]);
        $swap = ShiftSwap::create([
            'tenant_id' => $this->tenant->id, 'shift_id' => $shift->id,
            'requester_employee_id' => $this->employee->id,
            'counterpart_employee_id' => $colleague->id, 'status' => 'accepted',
        ]);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/shiftswap/{$swap->id}/approve");

        // Assert
        $response->assertRedirect();
        $this->assertSame('approved', $swap->fresh()->status);
        // The shift's assignee column (employee_id) now points at the counterpart.
        $this->assertSame($colleague->id, $shift->fresh()->employee_id);
    }

    public function test_swap_cannot_be_approved_before_the_counterpart_accepts(): void
    {
        // A named counterpart has not accepted yet (status still 'requested'). A manager
        // must not be able to reassign the shift to someone who never consented (AK-REL-02).
        $hr = $this->hrActor();
        $shift = $this->shiftFor($this->employee);
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Mate', 'status' => 'active', 'workload' => 'green',
        ]);
        $swap = ShiftSwap::create([
            'tenant_id' => $this->tenant->id, 'shift_id' => $shift->id,
            'requester_employee_id' => $this->employee->id,
            'counterpart_employee_id' => $colleague->id, 'status' => 'requested',
        ]);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/shiftswap/{$swap->id}/approve")
            ->assertStatus(422);

        // Neither the swap nor the shift moved.
        $this->assertSame('requested', $swap->fresh()->status);
        $this->assertSame($this->employee->id, $shift->fresh()->employee_id);
    }

    public function test_plain_employee_cannot_approve_a_swap(): void
    {
        // Arrange
        $shift = $this->shiftFor($this->employee);
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Mate', 'status' => 'active', 'workload' => 'green',
        ]);
        $swap = ShiftSwap::create([
            'tenant_id' => $this->tenant->id, 'shift_id' => $shift->id,
            'requester_employee_id' => $colleague->id,
            'counterpart_employee_id' => $this->employee->id, 'status' => 'accepted',
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/shiftswap/{$swap->id}/approve");

        // Assert
        $response->assertForbidden();
        $this->assertSame('accepted', $swap->fresh()->status);
    }

    public function test_requester_cannot_approve_their_own_swap(): void
    {
        // Arrange — give the requester a privileged role, then have them try to self-approve.
        $this->user->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'manager']);
        $shift = $this->shiftFor($this->employee);
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Mate', 'status' => 'active', 'workload' => 'green',
        ]);
        $swap = ShiftSwap::create([
            'tenant_id' => $this->tenant->id, 'shift_id' => $shift->id,
            'requester_employee_id' => $this->employee->id,
            'counterpart_employee_id' => $colleague->id, 'status' => 'accepted',
        ]);

        // Act
        $response = $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/shiftswap/{$swap->id}/approve");

        // Assert
        $response->assertForbidden();
        $this->assertSame('accepted', $swap->fresh()->status);
    }

    public function test_privileged_user_rejects_a_swap(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $shift = $this->shiftFor($this->employee);
        $swap = ShiftSwap::create([
            'tenant_id' => $this->tenant->id, 'shift_id' => $shift->id,
            'requester_employee_id' => $this->employee->id,
            'counterpart_employee_id' => null, 'status' => 'requested',
        ]);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/shiftswap/{$swap->id}/reject");

        // Assert
        $response->assertRedirect();
        $this->assertSame('rejected', $swap->fresh()->status);
    }
}
