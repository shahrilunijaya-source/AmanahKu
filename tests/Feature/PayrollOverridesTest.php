<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\PayslipPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * HR can hand-type EPF/SOCSO/EIS (both sides), unpaid leave and claims on a draft payslip.
 * The figure sticks across recomputes, only a submitted blank clears it, and it lands in
 * the normal column so every export reads it.
 */
class PayrollOverridesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green',
            'nric' => '900101-14-5501', 'date_of_birth' => '1990-01-01', 'joined_at' => '2020-01-01', 'salary' => 5000]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'basic_salary' => 5000,
            'epf_no' => '1', 'socso_no' => '1', 'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'tax_no' => 'SG1']);
    }

    private function payslip(): Payslip
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);
        $this->post('/app/payroll/runs', ['period' => '2026-06', 'payment_date' => '2026-06-30'])->assertRedirect();

        return PayrollRun::where('period', '2026-06')->firstOrFail()->payslips()->firstOrFail();
    }

    private function save(Payslip $p, array $data): Payslip
    {
        $this->post("/app/payroll/payslips/{$p->id}", $data)->assertRedirect();

        return $p->fresh();
    }

    public function test_statutory_overrides_replace_computed_amounts_and_totals_follow(): void
    {
        $p = $this->payslip();
        // Computed: EPF 550, SOCSO 24.75, EIS 9.90 => deductions 584.65, net 4415.35.
        $this->assertEqualsWithDelta(4415.35, $p->net_pay, 0.001);
        $employerCost = $p->employer_cost;

        $p = $this->save($p, [
            'epf_employee_override' => 500, 'socso_employee_override' => 20, 'eis_employee_override' => 8,
            'epf_employer_override' => 700, 'socso_employer_override' => 80, 'eis_employer_override' => 8,
        ]);

        // The normal columns hold the effective figure, which is what exports read.
        $this->assertEqualsWithDelta(500.0, $p->epf_employee, 0.001);
        $this->assertEqualsWithDelta(20.0, $p->socso_employee, 0.001);
        $this->assertEqualsWithDelta(8.0, $p->eis_employee, 0.001);
        $this->assertEqualsWithDelta(700.0, $p->epf_employer, 0.001);
        $this->assertEqualsWithDelta(80.0, $p->socso_employer, 0.001);
        $this->assertEqualsWithDelta(8.0, $p->eis_employer, 0.001);
        $this->assertEqualsWithDelta(500.0, $p->epf_employee_override, 0.001);
        $this->assertEqualsWithDelta(528.0, $p->total_deductions, 0.001);
        $this->assertEqualsWithDelta(4472.0, $p->net_pay, 0.001);
        $this->assertGreaterThan($employerCost, $p->employer_cost);
        $this->assertEqualsWithDelta(5000 + 700 + 80 + 8, $p->employer_cost, 0.001);
    }

    public function test_override_sticks_across_a_recalc_that_omits_it_and_blank_clears_it(): void
    {
        $p = $this->save($this->payslip(), ['epf_employee_override' => 500]);

        // A later recalc that never mentions the field keeps it.
        $p = $this->save($p, ['bonus' => 100]);
        $this->assertEqualsWithDelta(500.0, $p->epf_employee_override, 0.001);
        $this->assertEqualsWithDelta(500.0, $p->epf_employee, 0.001);

        // A submitted blank clears it and the computed figure (on the new gross) returns.
        $p = $this->save($p, ['bonus' => 100, 'epf_employee_override' => '']);
        $this->assertNull($p->epf_employee_override);
        $this->assertGreaterThan(500.0, $p->epf_employee);
    }

    public function test_unpaid_deduction_override_still_reduces_gross(): void
    {
        $p = $this->save($this->payslip(), ['unpaid_deduction_override' => 200]);

        $this->assertEqualsWithDelta(200.0, $p->unpaid_deduction, 0.001);
        $this->assertEqualsWithDelta(4800.0, $p->gross, 0.001);

        $p = $this->save($p, ['unpaid_deduction_override' => '']);
        $this->assertNull($p->unpaid_deduction_override);
        $this->assertEqualsWithDelta(0.0, $p->unpaid_deduction, 0.001);
        $this->assertEqualsWithDelta(5000.0, $p->gross, 0.001);
    }

    public function test_claims_override_replaces_and_clearing_restores_the_linked_claims(): void
    {
        Claim::create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'type' => 'expense',
            'title' => 'Dock', 'amount' => 120, 'status' => 'approved', 'date' => now()->toDateString()]);
        $p = $this->payslip();
        $this->assertEqualsWithDelta(120.0, $p->claims_reimbursement, 0.001);

        $p = $this->save($p, ['claims_reimbursement_override' => 75]);
        $this->assertEqualsWithDelta(75.0, $p->claims_reimbursement, 0.001);
        $this->assertEqualsWithDelta(4415.35 + 75, $p->net_pay, 0.001);
        $this->assertEqualsWithDelta(75.0, (float) $p->lines()->where('source', 'claim')->value('amount'), 0.001);

        $p = $this->save($p, ['bonus' => 0, 'claims_reimbursement_override' => '']);
        $this->assertNull($p->claims_reimbursement_override);
        $this->assertEqualsWithDelta(120.0, $p->claims_reimbursement, 0.001);
    }

    public function test_basic_override_reaches_the_salary_line_so_the_pdf_has_no_adjustment_row(): void
    {
        $p = $this->save($this->payslip(), ['basic' => 4800]);

        $this->assertEqualsWithDelta(4800.0, (float) $p->lines()->where('source', 'salary')->value('amount'), 0.001);
        $data = app(PayslipPdfData::class)->build($p->fresh(['lines']));
        $this->assertEqualsWithDelta((float) $p->net_pay, $data['totalEarnings'] - $data['totalDeductions'], 0.001);
        $this->assertNotContains('Adjustment', $data['deductions']->pluck('description')->all());
    }

    public function test_negative_override_is_rejected(): void
    {
        $p = $this->payslip();
        $this->post("/app/payroll/payslips/{$p->id}", ['socso_employee_override' => -1])->assertSessionHasErrors('socso_employee_override');
    }

    public function test_finalized_payslip_rejects_override_edits(): void
    {
        $p = $this->payslip();
        $this->post("/app/payroll/runs/{$p->payroll_run_id}/finalize")->assertRedirect();

        $this->post("/app/payroll/payslips/{$p->id}", ['epf_employee_override' => 1])->assertStatus(422);
        $this->assertNull($p->fresh()->epf_employee_override);
    }
}
