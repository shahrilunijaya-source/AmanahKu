<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FixedTransaction;
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

class PayrollDeductionGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        PayrollItem::seedFor($this->tenant);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Worker', 'staff_id' => 'AC-1', 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => 2000]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'basic_salary' => 2000,
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    private function loan(float $amount): void
    {
        $item = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'staff-loan')->firstOrFail();
        FixedTransaction::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'payroll_item_id' => $item->id,
            'amount' => $amount, 'start_period' => '2026-01', 'prorate' => false]);
    }

    private function makeRun(): PayrollRun
    {
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30']);

        return PayrollRun::firstOrFail();
    }

    public function test_cap_exceeded_blocks_finalize_until_consent_is_confirmed(): void
    {
        $this->loan(1100);   // 55% of 2,000
        $run = $this->makeRun();
        $p = Payslip::firstOrFail();
        $this->assertTrue($p->deduction_cap_exceeded);

        $this->post(route('payroll.runs.finalize', $run))->assertStatus(422);

        $this->post(route('payroll.payslips.consent', $p))->assertSessionHasNoErrors();
        $this->assertTrue($p->fresh()->deduction_consent_confirmed);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Confirmed deduction consent']);

        $this->post(route('payroll.runs.finalize', $run))->assertSessionHasNoErrors();
        $this->assertSame('finalized', $run->fresh()->status);
    }

    public function test_negative_net_blocks_finalize_and_carry_forward_books_next_month(): void
    {
        $this->loan(2500);
        $run = $this->makeRun();
        $p = Payslip::firstOrFail();
        $this->assertLessThan(0, $p->net_pay);
        $shortfall = abs($p->net_pay);

        $this->post(route('payroll.runs.finalize', $run))->assertStatus(422);

        $this->post(route('payroll.payslips.carry-forward', $p))->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame(0.0, $p->net_pay);
        $this->assertSame($shortfall, (float) $p->carried_forward_amount);

        $it = IndividualTransaction::where('employee_id', $this->emp->id)->where('period', '2026-07')->firstOrFail();
        $this->assertSame($shortfall, (float) $it->amount);
        $this->assertStringContainsString('June 2026', (string) $it->remarks);
    }
}
