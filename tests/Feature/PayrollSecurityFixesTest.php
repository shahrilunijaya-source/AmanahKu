<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExpenseReport;
use App\Models\LoanRequest;
use App\Models\Tenant;
use App\Models\TravelRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression guards for the segregation-of-duties fixes: an approver-role user who is
 * ALSO the requester must not be able to approve their own loan/travel request.
 */
class PayrollSecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $manager;

    private Employee $managerEmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->manager = User::create(['name' => 'Mgr', 'email' => 'mgr@example.com', 'password' => Hash::make('password')]);
        $this->manager->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        $this->managerEmp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->manager->id,
            'name' => 'Mgr', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingManager(): self
    {
        $this->actingAs($this->manager)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_user_cannot_approve_their_own_loan(): void
    {
        $loan = LoanRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->managerEmp->id,
            'type' => 'loan', 'amount' => 5000, 'reason' => 'Personal', 'installments' => 12, 'status' => 'submitted',
        ]);

        $this->actingManager()->post("/app/loans/{$loan->id}/approve")->assertForbidden();
        $this->assertSame('submitted', $loan->fresh()->status);
    }

    public function test_user_cannot_approve_their_own_travel(): void
    {
        $travel = TravelRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->managerEmp->id,
            'destination' => 'KL', 'purpose' => 'Client', 'depart_date' => '2026-07-01',
            'return_date' => '2026-07-03', 'transport' => 'flight', 'status' => 'submitted',
        ]);

        $this->actingManager()->post("/app/travel/{$travel->id}/approve")->assertForbidden();
        $this->assertSame('submitted', $travel->fresh()->status);
    }

    public function test_user_cannot_approve_their_own_expense_report(): void
    {
        $report = ExpenseReport::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->managerEmp->id,
            'title' => 'Client trip', 'status' => 'submitted', 'total' => 250,
        ]);

        $this->actingManager()->post("/app/expenses/{$report->id}/approve")->assertForbidden();
        $this->assertSame('submitted', $report->fresh()->status);
    }
}
