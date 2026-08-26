<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A payroll run had no delete path at all — a test run, or one created for the wrong
 * month, was stuck forever. See CLAUDE.md's "payroll module polish" pass, item 1.
 */
class PayrollRunDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private User $management;

    private Employee $emp1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->management = User::create(['name' => 'Director', 'email' => 'director@example.com', 'password' => Hash::make('password')]);
        $this->management->tenants()->attach($this->tenant->id, ['role' => 'management']);

        $this->emp1 = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green']);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'basic_salary' => 5200]);
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function actingManagement(): self
    {
        $this->actingAs($this->management)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function createRun(string $period = '2026-06'): PayrollRun
    {
        $this->actingHr()->post('/app/payroll/runs', ['period' => $period])->assertRedirect();

        return PayrollRun::where('period', $period)->firstOrFail();
    }

    // ── Draft run ────────────────────────────────────────────────

    public function test_hr_can_delete_a_draft_run_and_its_payslips(): void
    {
        $run = $this->createRun();
        $payslipId = $run->payslips()->firstOrFail()->id;

        $this->actingHr()->post("/app/payroll/runs/{$run->id}/delete")->assertRedirect();

        $this->assertDatabaseMissing('payroll_runs', ['id' => $run->id]);
        $this->assertDatabaseMissing('payslips', ['id' => $payslipId]);
        $this->assertDatabaseMissing('payslip_lines', ['payslip_id' => $payslipId]);
    }

    public function test_employee_cannot_delete_a_draft_run(): void
    {
        $run = $this->createRun();

        $employeeUser = User::create(['name' => 'Rank and file', 'email' => 'rankandfile@example.com', 'password' => Hash::make('password')]);
        $employeeUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->actingAs($employeeUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/payroll/runs/{$run->id}/delete")
            ->assertForbidden();

        $this->assertDatabaseHas('payroll_runs', ['id' => $run->id]);
    }

    public function test_cannot_delete_a_run_from_another_tenant(): void
    {
        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreignRun = PayrollRun::forceCreate(['tenant_id' => $otherTenant->id, 'period' => '2026-06', 'status' => 'draft']);

        $response = $this->actingHr()->post("/app/payroll/runs/{$foreignRun->id}/delete");

        // Denied either by the explicit tenant assert (403) or the tenant scope (404).
        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseHas('payroll_runs', ['id' => $foreignRun->id]);
    }

    // ── Finalized run ────────────────────────────────────────────

    public function test_hr_alone_cannot_delete_a_finalized_run(): void
    {
        $run = $this->createRun();
        $this->actingHr()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();

        $this->actingHr()->post("/app/payroll/runs/{$run->id}/delete", ['confirm_period' => $run->period])->assertForbidden();
        $this->assertDatabaseHas('payroll_runs', ['id' => $run->id]);
    }

    public function test_management_deleting_a_finalized_run_must_type_the_exact_period(): void
    {
        $run = $this->createRun();
        $this->actingManagement()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();

        $this->actingManagement()->post("/app/payroll/runs/{$run->id}/delete", ['confirm_period' => '2026-07'])->assertStatus(422);
        $this->assertDatabaseHas('payroll_runs', ['id' => $run->id]);

        $this->actingManagement()->post("/app/payroll/runs/{$run->id}/delete", ['confirm_period' => $run->period])->assertRedirect();
        $this->assertDatabaseMissing('payroll_runs', ['id' => $run->id]);
    }

    public function test_deleting_a_finalized_run_reverses_a_paid_claim_back_to_approved(): void
    {
        $claim = Claim::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id,
            'type' => 'expense', 'title' => 'Dock', 'amount' => 120, 'status' => 'approved', 'date' => now()->toDateString(),
        ]);

        $run = $this->createRun();
        $this->actingManagement()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();
        $this->assertSame('paid', $claim->fresh()->status);
        $this->assertNotNull($claim->fresh()->paid_at);

        $this->actingManagement()->post("/app/payroll/runs/{$run->id}/delete", ['confirm_period' => $run->period])->assertRedirect();

        $fresh = $claim->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertNull($fresh->paid_at);

        // And it is picked up again by a fresh run for the same month.
        $newRun = $this->createRun();
        $newPayslip = $newRun->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertContains($claim->id, $newPayslip->claim_ids);
        $this->assertEqualsWithDelta(120.0, (float) $newPayslip->claims_reimbursement, 0.001);
    }

    public function test_deleting_a_finalized_run_reverses_pulled_overtime_and_unpaid_leave(): void
    {
        $ot = OvertimeRequest::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id,
            'ot_date' => '2026-06-15', 'hours' => 4, 'rate_multiplier' => 1.50,
            'reason' => 'Backlog', 'status' => 'approved',
        ]);
        $leaveType = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Unpaid', 'entitlement' => 0, 'is_unpaid' => true]);
        $leave = LeaveRequest::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'leave_type_id' => $leaveType->id,
            'date_from' => '2026-06-10', 'date_to' => '2026-06-12', 'days' => 3, 'status' => 'approved',
        ]);

        $run = $this->createRun();
        $this->actingManagement()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();
        $this->assertNotNull($ot->fresh()->paid_at);
        $this->assertNotNull($leave->fresh()->paid_at);

        $this->actingManagement()->post("/app/payroll/runs/{$run->id}/delete", ['confirm_period' => $run->period])->assertRedirect();

        $this->assertNull($ot->fresh()->paid_at);
        $this->assertNull($leave->fresh()->paid_at);

        // And a future run can pull them again.
        $newRun = $this->createRun();
        $newPayslip = $newRun->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertContains($ot->id, $newPayslip->overtime_request_ids);
        $this->assertContains($leave->id, $newPayslip->unpaid_leave_request_ids);
    }

    public function test_deleting_a_finalized_run_removes_its_payslips_and_lines(): void
    {
        $run = $this->createRun();
        $payslip = $run->payslips()->firstOrFail();
        $payslipId = $payslip->id;
        $this->assertGreaterThan(0, $payslip->lines()->count());

        $this->actingManagement()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();
        $this->actingManagement()->post("/app/payroll/runs/{$run->id}/delete", ['confirm_period' => $run->period])->assertRedirect();

        $this->assertDatabaseMissing('payroll_runs', ['id' => $run->id]);
        $this->assertDatabaseMissing('payslips', ['id' => $payslipId]);
        $this->assertDatabaseMissing('payslip_lines', ['payslip_id' => $payslipId]);
    }
}
