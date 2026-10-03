<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\IndividualTransaction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Payslip toolbar: lock/unlock, Reset to Default and the remark. A locked payslip is
 * refused by every writer, and each toolbar action leaves an audit row.
 */
class PayrollLockTest extends TestCase
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

    public function test_lock_blocks_every_payslip_writer_and_unlock_reenables(): void
    {
        $p = $this->payslip();
        $this->post("/app/payroll/payslips/{$p->id}/lock")->assertRedirect();
        $p->refresh();
        $this->assertNotNull($p->locked_at);
        $this->assertSame($this->hr->id, $p->locked_by_id);

        $this->post("/app/payroll/payslips/{$p->id}", ['pcb_override' => 50])->assertStatus(423);
        $this->post("/app/payroll/payslips/{$p->id}/reset")->assertStatus(423);
        $this->post("/app/payroll/payslips/{$p->id}/remark", ['notes' => 'x'])->assertStatus(423);
        $this->post("/app/payroll/payslips/{$p->id}/consent")->assertStatus(423);
        $this->post("/app/payroll/payslips/{$p->id}/carry-forward")->assertStatus(423);
        $this->post("/app/payroll/payslips/{$p->id}/release-hold", ['reason' => 'ok'])->assertStatus(423);
        $this->post("/app/payroll/runs/{$p->payroll_run_id}/delete")->assertStatus(423);
        $this->assertNull($p->fresh()->pcb_override);
        $this->assertNull($p->fresh()->notes);

        $this->post("/app/payroll/payslips/{$p->id}/unlock")->assertRedirect();
        $this->assertNull($p->fresh()->locked_at);
        $this->post("/app/payroll/payslips/{$p->id}", ['pcb_override' => 50])->assertRedirect();
        $this->assertEqualsWithDelta(50.0, $p->fresh()->pcb, 0.001);
    }

    public function test_cannot_lock_a_finalized_payslip(): void
    {
        $p = $this->payslip();
        $this->post("/app/payroll/runs/{$p->payroll_run_id}/finalize")->assertRedirect();

        $this->post("/app/payroll/payslips/{$p->id}/lock")->assertStatus(422);
        $this->post("/app/payroll/payslips/{$p->id}/reset")->assertStatus(422);
    }

    public function test_reset_clears_every_override_and_keeps_individual_transactions_and_bonus(): void
    {
        PayrollItem::seedFor($this->tenant);
        $item = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'meal-allowance')->firstOrFail();
        $p = $this->payslip();
        $tx = IndividualTransaction::create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id,
            'payroll_item_id' => $item->id, 'period' => '2026-06', 'amount' => 40, 'created_by_id' => $this->hr->id]);

        $this->post("/app/payroll/payslips/{$p->id}", [
            'bonus' => 100, 'basic' => 4000, 'overtime_hours' => 2, 'unpaid_days' => 1, 'pcb_override' => 10,
            'epf_employee_override' => 1, 'epf_employer_override' => 2, 'socso_employee_override' => 3, 'socso_employer_override' => 4,
            'eis_employee_override' => 5, 'eis_employer_override' => 6, 'unpaid_deduction_override' => 7, 'claims_reimbursement_override' => 8,
        ])->assertRedirect();
        $p->refresh();
        $this->assertTrue((bool) $p->basic_overridden);
        $this->assertNotNull($p->epf_employee_override);

        $this->post("/app/payroll/payslips/{$p->id}/reset")->assertRedirect();
        $p->refresh();

        foreach (['pcb_override', 'epf_employee_override', 'epf_employer_override', 'socso_employee_override', 'socso_employer_override',
            'eis_employee_override', 'eis_employer_override', 'unpaid_deduction_override', 'claims_reimbursement_override'] as $col) {
            $this->assertNull($p->{$col}, $col);
        }
        $this->assertFalse((bool) $p->basic_overridden);
        $this->assertFalse((bool) $p->overtime_overridden);
        $this->assertFalse((bool) $p->unpaid_days_overridden);
        $this->assertEqualsWithDelta(5000.0, $p->basic, 0.001);
        $this->assertEqualsWithDelta(0.0, $p->unpaid_deduction, 0.001);
        $this->assertEqualsWithDelta(0.0, $p->overtime_amount, 0.001);
        // Real one-offs survive: the bonus and the individual transaction.
        $this->assertEqualsWithDelta(100.0, $p->bonus, 0.001);
        $this->assertNotNull(IndividualTransaction::find($tx->id));
        $this->assertEqualsWithDelta(5000 + 100 + 40, $p->gross, 0.001);
    }

    public function test_remark_saves_and_each_toolbar_action_is_audited(): void
    {
        $p = $this->payslip();

        $this->post("/app/payroll/payslips/{$p->id}/remark", ['notes' => 'Paid by cheque'])->assertRedirect();
        $this->assertSame('Paid by cheque', $p->fresh()->notes);
        $this->post("/app/payroll/payslips/{$p->id}/lock")->assertRedirect();
        $this->post("/app/payroll/payslips/{$p->id}/unlock")->assertRedirect();
        $this->post("/app/payroll/payslips/{$p->id}/reset")->assertRedirect();

        foreach (['Updated payslip remark', 'Locked payslip', 'Unlocked payslip', 'Reset payslip to default'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->exists(), $action);
        }
    }

    public function test_remark_is_validated(): void
    {
        $p = $this->payslip();
        $this->post("/app/payroll/payslips/{$p->id}/remark", ['notes' => str_repeat('a', 256)])->assertSessionHasErrors('notes');
    }
}
