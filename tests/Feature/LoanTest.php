<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LoanRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Loans & salary advances module.
 * Harness (setUp / actingInTenant / hrActor) copied from CoreWritePathsTest.
 */
class LoanTest extends TestCase
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

    public function test_employee_submits_a_loan_request(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/loans', [
            'type' => 'loan',
            'amount' => 3000,
            'reason' => 'Home repair',
            'installments' => 6,
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('loan_requests', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'type' => 'loan',
            'installments' => 6,
            'status' => 'submitted',
        ]);
    }

    public function test_submitted_request_is_bound_to_the_submitting_employee(): void
    {
        // Arrange — a colleague the attacker might try to impersonate.
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);

        // Act — plain employee submits, also passing a colleague's id (ignored server-side).
        $this->actingInTenant()->post('/app/loans', [
            'type' => 'advance',
            'amount' => 800,
            'reason' => 'Cash-flow',
            'installments' => 1,
            'employee_id' => $colleague->id,
        ])->assertRedirect();

        // Assert — owner is the uploader, never the spoofed id.
        $this->assertDatabaseHas('loan_requests', [
            'employee_id' => $this->employee->id,
            'reason' => 'Cash-flow',
        ]);
        $this->assertDatabaseMissing('loan_requests', [
            'employee_id' => $colleague->id,
        ]);
    }

    public function test_validation_rejects_a_non_positive_amount(): void
    {
        $this->actingInTenant()->post('/app/loans', [
            'type' => 'loan',
            'amount' => 0,
            'reason' => 'Nope',
            'installments' => 1,
        ])->assertSessionHasErrors('amount');
    }

    public function test_privileged_user_approves_a_request(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $loan = LoanRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'type' => 'loan', 'amount' => 5000, 'reason' => 'House', 'installments' => 12, 'status' => 'submitted',
        ]);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/loans/{$loan->id}/approve");

        // Assert
        $response->assertRedirect();
        $fresh = $loan->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertNotNull($fresh->approved_by_employee_id);
        $this->assertNotNull($fresh->decided_at);
    }

    public function test_privileged_user_rejects_a_request(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $loan = LoanRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'type' => 'advance', 'amount' => 1200, 'reason' => 'Gap', 'installments' => 1, 'status' => 'submitted',
        ]);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/loans/{$loan->id}/reject");

        // Assert
        $response->assertRedirect();
        $this->assertSame('rejected', $loan->fresh()->status);
    }

    public function test_plain_employee_cannot_approve_a_request(): void
    {
        // Arrange
        $loan = LoanRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'type' => 'loan', 'amount' => 5000, 'reason' => 'House', 'installments' => 12, 'status' => 'submitted',
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/loans/{$loan->id}/approve");

        // Assert
        $response->assertForbidden();
        $this->assertSame('submitted', $loan->fresh()->status);
    }

    public function test_cannot_approve_an_already_decided_request(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $loan = LoanRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'type' => 'loan', 'amount' => 5000, 'reason' => 'House', 'installments' => 12, 'status' => 'approved',
        ]);

        // Act
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/loans/{$loan->id}/approve")->assertStatus(422);
    }
}
