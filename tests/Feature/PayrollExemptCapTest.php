<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollOpeningFigure;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\EaFormData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Spec F8: a Payroll Item's yearly tax-exempt cap (the seeded travel allowance carries
 * LHDN's RM6,000/year official-duties exemption) keeps that pay out of the PCB base
 * until the cap is used up.
 */
class PayrollExemptCapTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        PayrollItem::seedFor($this->tenant);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    private function employee(string $name): Employee
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'staff_id' => 'AC-'.$name, 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => 6000]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 6000, 'nationality' => 'citizen',
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1']);

        return $emp;
    }

    private function giveTravelAllowance(Employee $emp, float $amount = 500): void
    {
        $item = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'travel-allowance')->firstOrFail();
        $this->post(route('payroll.fixed-transactions.store'), [
            'employee_id' => $emp->id, 'payroll_item_id' => $item->id, 'amount' => $amount, 'start_period' => '2026-01',
        ])->assertSessionHasNoErrors();
    }

    private function payslipFor(Employee $emp, string $period = '2026-01'): Payslip
    {
        $run = PayrollRun::where('period', $period)->first();
        if ($run === null) {
            $this->post(route('payroll.runs.create'), ['period' => $period, 'payment_date' => $period.'-28'])->assertSessionHasNoErrors();
            $run = PayrollRun::where('period', $period)->firstOrFail();
        }

        return Payslip::where('payroll_run_id', $run->id)->where('employee_id', $emp->id)->firstOrFail();
    }

    public function test_an_allowance_under_the_cap_does_not_raise_pcb_and_is_recorded_as_exempt(): void
    {
        $plain = $this->employee('Plain');
        $withAllowance = $this->employee('Traveller');
        $this->giveTravelAllowance($withAllowance);

        $a = $this->payslipFor($plain);
        $b = $this->payslipFor($withAllowance);

        $this->assertSame(0.0, (float) $a->pcb_exempt_amount);
        $this->assertSame(500.0, (float) $b->pcb_exempt_amount);
        $this->assertSame((float) $a->pcb, (float) $b->pcb);
        // The allowance is still paid — only the PCB base ignores it.
        $this->assertSame(round((float) $a->gross + 500, 2), round((float) $b->gross, 2));
    }

    public function test_a_used_up_cap_makes_the_allowance_fully_taxable(): void
    {
        $plain = $this->employee('Plain');
        $spent = $this->employee('Spent');
        $this->giveTravelAllowance($spent);
        PayrollOpeningFigure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $spent->id,
            'year' => 2026, 'exempt_allowances' => 6000]);

        $a = $this->payslipFor($plain);
        $b = $this->payslipFor($spent);

        $this->assertSame(0.0, (float) $b->pcb_exempt_amount);
        $this->assertGreaterThan((float) $a->pcb, (float) $b->pcb);
    }

    public function test_form_ea_part_f_reports_the_capped_exempt_amount(): void
    {
        $emp = $this->employee('Traveller');
        $this->giveTravelAllowance($emp);
        $this->post(route('payroll.runs.create'), ['period' => '2026-01', 'payment_date' => '2026-01-28'])->assertSessionHasNoErrors();
        $run = PayrollRun::where('period', '2026-01')->firstOrFail();
        $this->post(route('payroll.runs.finalize', $run), ['payment_date' => '2026-01-28'])->assertSessionHasNoErrors();

        $data = app(EaFormData::class)->forEmployee($this->tenant, $emp->fresh(), 2026);

        $this->assertSame(500.0, (float) $data['employment_income']['tax_exempt_total']);
        $this->assertSame(500.0, (float) $data['employment_income']['exempt_cap_candidates_total']);
    }
}
