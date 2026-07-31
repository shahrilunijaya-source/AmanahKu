<?php

namespace Tests\Feature;

use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the confidential Disciplinary & grievance cases module.
 * Harness (setUp / actingInTenant / hrActor) copied from CoreWritePathsTest.
 */
class CaseTest extends TestCase
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

    // ── Open a case ───────────────────────────────────────────────

    public function test_privileged_user_opens_a_case(): void
    {
        // Arrange
        $hr = $this->hrActor();

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/cases', [
                'employee_id' => $this->employee->id,
                'type' => 'warning',
                'severity' => 'medium',
                'subject' => 'Repeated lateness',
                'details' => 'Late five times this month without notice.',
            ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('disciplinary_cases', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'type' => 'warning',
            'severity' => 'medium',
            'subject' => 'Repeated lateness',
            'status' => 'open',
        ]);
    }

    public function test_plain_employee_cannot_open_a_case(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/cases', [
            'employee_id' => $this->employee->id,
            'type' => 'grievance',
            'severity' => 'high',
            'subject' => 'Sneaky case',
            'details' => 'Should never be created.',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('disciplinary_cases', ['subject' => 'Sneaky case']);
    }

    // ── Move status / record outcome ──────────────────────────────

    public function test_privileged_user_updates_status_and_outcome(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $case = DisciplinaryCase::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'type' => 'investigation',
            'severity' => 'high',
            'subject' => 'Asset misuse',
            'details' => 'Under review.',
            'status' => 'open',
        ]);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/cases/{$case->id}", [
                'status' => 'resolved',
                'outcome' => 'Cleared after investigation.',
            ]);

        // Assert
        $response->assertRedirect();
        $fresh = $case->fresh();
        $this->assertSame('resolved', $fresh->status);
        $this->assertSame('Cleared after investigation.', $fresh->outcome);
    }

    public function test_plain_employee_cannot_update_a_case(): void
    {
        // Arrange
        $case = DisciplinaryCase::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'type' => 'warning',
            'severity' => 'low',
            'subject' => 'First warning',
            'details' => 'Details.',
            'status' => 'open',
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/cases/{$case->id}", [
            'status' => 'closed',
            'outcome' => 'Trying to tamper.',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertSame('open', $case->fresh()->status);
    }
}
