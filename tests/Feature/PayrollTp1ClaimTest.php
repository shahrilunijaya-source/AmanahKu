<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollTp1Claim;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\Cp8dData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollTp1ClaimTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $plain;

    private Employee $claimer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        PayrollItem::seedFor($this->tenant);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->plain = $this->employee('Plain');
        $this->claimer = $this->employee('Claimer');
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    private function employee(string $name): Employee
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'staff_id' => 'AC-'.$name, 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => 8000]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 8000, 'nationality' => 'citizen',
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1']);

        return $emp;
    }

    /** @param  array<string, mixed>  $extra */
    private function claim(array $extra = []): void
    {
        $this->post(route('payroll.tp1.store'), array_merge([
            'employee_id' => $this->claimer->id, 'year' => 2026, 'month' => 1,
            'relief_code' => 'serious-disease', 'amount' => 10000,
        ], $extra))->assertSessionHasNoErrors();
    }

    private function runJanuary(): PayrollRun
    {
        $this->post(route('payroll.runs.create'), ['period' => '2026-01', 'payment_date' => '2026-01-28'])->assertSessionHasNoErrors();

        return PayrollRun::where('period', '2026-01')->firstOrFail();
    }

    private function slip(PayrollRun $run, Employee $emp): Payslip
    {
        return Payslip::where('payroll_run_id', $run->id)->where('employee_id', $emp->id)->firstOrFail();
    }

    public function test_a_relief_claim_lowers_that_months_pcb(): void
    {
        $this->claim();
        $run = $this->runJanuary();

        $this->assertLessThan(
            (float) $this->slip($run, $this->plain)->pcb,
            (float) $this->slip($run, $this->claimer)->pcb,
        );
    }

    public function test_tp1_zakat_lowers_the_net_mtd_without_appearing_as_a_payslip_deduction(): void
    {
        $this->claim(['relief_code' => null, 'amount' => 0, 'zakat_amount' => 40]);
        $run = $this->runJanuary();
        $slip = $this->slip($run, $this->claimer);

        $this->assertSame(round((float) $this->slip($run, $this->plain)->pcb - 40, 2), round((float) $slip->pcb, 2));
        // Paid straight to Pusat Zakat — never deducted from pay (LHDN MTD spec p.35).
        $this->assertSame(0.0, (float) $slip->zakat);
    }

    public function test_a_claim_over_the_cap_is_trimmed_before_it_reaches_pcb(): void
    {
        $this->claim(['relief_code' => 'lifestyle', 'amount' => 2500]);
        $capped = $this->runJanuary();
        $trimmedPcb = (float) $this->slip($capped, $this->claimer)->pcb;

        PayrollTp1Claim::query()->delete();
        $capped->payslips()->delete();
        $capped->delete();

        $this->claim(['relief_code' => 'lifestyle', 'amount' => 9000]);
        $run = $this->runJanuary();

        $this->assertSame($trimmedPcb, (float) $this->slip($run, $this->claimer)->pcb);
    }

    public function test_a_claim_for_a_finalized_month_is_refused(): void
    {
        $run = $this->runJanuary();
        $this->post(route('payroll.runs.finalize', $run), ['payment_date' => '2026-01-28'])->assertSessionHasNoErrors();

        $this->post(route('payroll.tp1.store'), ['employee_id' => $this->claimer->id, 'year' => 2026, 'month' => 1,
            'relief_code' => 'lifestyle', 'amount' => 100])->assertStatus(422);
    }

    public function test_an_employee_of_another_tenant_is_refused(): void
    {
        $other = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'initials' => 'RV']);
        $theirs = Employee::create(['tenant_id' => $other->id, 'name' => 'Theirs', 'status' => 'active', 'workload' => 'green']);

        $this->post(route('payroll.tp1.store'), ['employee_id' => $theirs->id, 'year' => 2026, 'month' => 1,
            'relief_code' => 'lifestyle', 'amount' => 100])->assertSessionHasErrors('employee_id');
    }

    public function test_a_claim_added_after_the_draft_run_is_picked_up_on_recompute(): void
    {
        $run = $this->runJanuary();
        $before = (float) $this->slip($run, $this->claimer)->pcb;

        $this->claim();
        $slip = $this->slip($run, $this->claimer);
        $this->post(route('payroll.payslips.update', $slip), [])->assertSessionHasNoErrors();

        $this->assertLessThan($before, (float) $slip->fresh()->pcb);
    }

    public function test_cp8d_reports_the_years_tp1_relief_and_zakat(): void
    {
        $this->claim(['relief_code' => 'lifestyle', 'amount' => 9000, 'zakat_amount' => 25]);
        $run = $this->runJanuary();
        $this->post(route('payroll.runs.finalize', $run), ['payment_date' => '2026-01-28'])->assertSessionHasNoErrors();

        $row = app(Cp8dData::class)->forEmployee($this->tenant, $this->claimer->fresh(), 2026);

        $this->assertSame(2500.0, (float) $row['tp1_relief']);
        $this->assertSame(25.0, (float) $row['tp1_zakat']);
    }

    public function test_another_tenants_claim_cannot_be_deleted(): void
    {
        $other = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'initials' => 'RV']);
        $theirs = Employee::create(['tenant_id' => $other->id, 'name' => 'Theirs', 'status' => 'active', 'workload' => 'green']);
        $foreign = PayrollTp1Claim::forceCreate(['tenant_id' => $other->id, 'employee_id' => $theirs->id,
            'year' => 2026, 'month' => 1, 'relief_code' => 'lifestyle', 'amount' => 100]);

        $this->post(route('payroll.tp1.delete', $foreign))->assertForbidden();
    }
}
