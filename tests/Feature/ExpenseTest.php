<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExpenseReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Expense Reports module.
 * Harness (setUp / actingInTenant / hrActor) copied from LoanTest / CoreWritePathsTest.
 */
class ExpenseTest extends TestCase
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

    public function test_employee_creates_a_report_with_lines_and_total_is_computed(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/expenses', [
            'title' => 'June client visits',
            'period_label' => 'June 2026',
            'lines' => [
                ['expense_date' => '2026-06-03', 'category' => 'Travel', 'description' => 'Flight', 'amount' => 480.00],
                ['expense_date' => '2026-06-04', 'category' => 'Meals', 'description' => 'Lunch', 'amount' => 95.50],
            ],
        ]);

        // Assert
        $response->assertRedirect();
        $report = ExpenseReport::where('employee_id', $this->employee->id)->first();
        $this->assertNotNull($report);
        $this->assertSame('draft', $report->status);
        $this->assertSame(2, $report->lines()->count());
        $this->assertSame('575.50', (string) $report->total);
    }

    public function test_validation_rejects_a_report_with_no_lines(): void
    {
        $this->actingInTenant()->post('/app/expenses', [
            'title' => 'Empty',
            'lines' => [],
        ])->assertSessionHasErrors('lines');
    }

    public function test_owner_appends_a_line_to_a_draft_and_total_recomputes(): void
    {
        // Arrange
        $report = ExpenseReport::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'Draft', 'period_label' => 'June 2026', 'status' => 'draft', 'total' => 0,
        ]);
        $report->lines()->create([
            'tenant_id' => $this->tenant->id, 'expense_date' => '2026-06-01',
            'category' => 'Travel', 'description' => 'Taxi', 'amount' => 30.00,
        ]);
        $report->recomputeTotal();

        // Act
        $this->actingInTenant()->post("/app/expenses/{$report->id}/lines", [
            'expense_date' => '2026-06-02', 'category' => 'Meals', 'description' => 'Dinner', 'amount' => 70.00,
        ])->assertRedirect();

        // Assert
        $this->assertSame(2, $report->fresh()->lines()->count());
        $this->assertSame('100.00', (string) $report->fresh()->total);
    }

    public function test_submit_transitions_draft_to_submitted(): void
    {
        // Arrange
        $report = ExpenseReport::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'Trip', 'status' => 'draft', 'total' => 0,
        ]);
        $report->lines()->create([
            'tenant_id' => $this->tenant->id, 'expense_date' => '2026-06-01',
            'category' => 'Travel', 'description' => 'Bus', 'amount' => 12.00,
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/expenses/{$report->id}/submit");

        // Assert
        $response->assertRedirect();
        $fresh = $report->fresh();
        $this->assertSame('submitted', $fresh->status);
        $this->assertNotNull($fresh->submitted_at);
    }

    public function test_privileged_user_approves_a_submitted_report(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $report = ExpenseReport::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'Trip', 'status' => 'submitted', 'total' => 200,
        ]);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/expenses/{$report->id}/approve");

        // Assert
        $response->assertRedirect();
        $fresh = $report->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertNotNull($fresh->decided_by_id);
        $this->assertNotNull($fresh->decided_at);
    }

    public function test_privileged_user_rejects_a_submitted_report(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $report = ExpenseReport::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'Trip', 'status' => 'submitted', 'total' => 200,
        ]);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/expenses/{$report->id}/reject");

        // Assert
        $response->assertRedirect();
        $this->assertSame('rejected', $report->fresh()->status);
    }

    public function test_plain_employee_cannot_approve_a_report(): void
    {
        // Arrange
        $report = ExpenseReport::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'Trip', 'status' => 'submitted', 'total' => 200,
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/expenses/{$report->id}/approve");

        // Assert
        $response->assertForbidden();
        $this->assertSame('submitted', $report->fresh()->status);
    }
}
