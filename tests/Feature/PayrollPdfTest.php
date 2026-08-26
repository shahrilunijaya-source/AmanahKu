<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollOpeningFigure;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\PayslipPdfData;
use App\Services\Payroll\PayslipYearToDate;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollPdfTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private User $empUser;

    private Employee $emp;

    private User $otherEmpUser;

    private Employee $otherEmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->empUser = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $this->empUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->empUser->id,
            'name' => 'Worker', 'staff_id' => 'AC-0007', 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500',
        ]);
        SalaryStructure::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'basic_salary' => 5000,
            'bank_name' => 'Maybank', 'bank_account_no' => '514999001122',
            'epf_no' => 'EPF12345678', 'socso_no' => 'SOC99001122',
        ]);

        $this->otherEmpUser = User::create(['name' => 'Someone Else', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $this->otherEmpUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->otherEmp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->otherEmpUser->id,
            'name' => 'Someone Else', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function payslipFor(Employee $employee, string $status = 'finalized'): Payslip
    {
        $run = PayrollRun::forceCreate([
            'tenant_id' => $this->tenant->id, 'period' => '2026-06', 'label' => 'June 2026', 'status' => $status,
            'finalized_at' => $status === 'finalized' ? now() : null,
        ]);

        $payslip = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'basic' => 5000, 'gross' => 5000,
            'epf_employee' => 550, 'epf_employer' => 650,
            'socso_employee' => 25, 'socso_employer' => 87.5,
            'eis_employee' => 10, 'eis_employer' => 10, 'pcb' => 120,
            'overtime_hours' => 10, 'overtime_amount' => 187.5, 'overtime_multiplier' => 1.5,
            'total_deductions' => 705, 'net_pay' => 4482.5, 'employer_cost' => 5747.5,
        ]);

        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'name' => 'Basic Salary',
            'type' => 'earning', 'amount' => 5000, 'source' => 'salary', 'sort_order' => 0,
        ]);
        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'name' => 'Overtime 1.5×',
            'type' => 'earning', 'amount' => 187.5, 'quantity' => 10, 'source' => 'overtime', 'sort_order' => 1,
        ]);

        return $payslip;
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function actingEmployee(): self
    {
        $this->actingAs($this->empUser)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function actingOtherEmployee(): self
    {
        $this->actingAs($this->otherEmpUser)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_hr_downloads_a_finalized_payslip_pdf(): void
    {
        $payslip = $this->payslipFor($this->emp);

        $response = $this->actingHr()->get(route('payroll.payslips.pdf', $payslip));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertGreaterThan(1000, strlen($response->getContent()));
    }

    public function test_employee_downloads_own_finalized_payslip_pdf(): void
    {
        $payslip = $this->payslipFor($this->emp);

        $response = $this->actingEmployee()->get(route('payroll.payslips.pdf', $payslip));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_employee_cannot_download_someone_elses_payslip(): void
    {
        $payslip = $this->payslipFor($this->emp);

        $this->actingOtherEmployee()->get(route('payroll.payslips.pdf', $payslip))->assertForbidden();
    }

    public function test_draft_payslip_cannot_be_downloaded_by_anyone(): void
    {
        $payslip = $this->payslipFor($this->emp, 'draft');

        $this->actingHr()->get(route('payroll.payslips.pdf', $payslip))->assertStatus(422);
        $this->actingEmployee()->get(route('payroll.payslips.pdf', $payslip))->assertStatus(422);
    }

    public function test_cannot_download_a_payslip_from_another_tenant(): void
    {
        $other = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'initials' => 'RV']);
        $otherRun = PayrollRun::forceCreate(['tenant_id' => $other->id, 'period' => '2026-06', 'status' => 'finalized', 'finalized_at' => now()]);
        $otherEmployee = Employee::create(['tenant_id' => $other->id, 'name' => 'Foreigner', 'status' => 'active', 'workload' => 'green']);
        $foreign = Payslip::forceCreate([
            'tenant_id' => $other->id, 'payroll_run_id' => $otherRun->id, 'employee_id' => $otherEmployee->id,
            'basic' => 3000, 'gross' => 3000, 'net_pay' => 3000, 'employer_cost' => 3000,
        ]);

        $this->actingHr()->get(route('payroll.payslips.pdf', $foreign))->assertForbidden();
    }

    public function test_bulk_pdf_contains_every_employee_in_the_run(): void
    {
        $run = PayrollRun::forceCreate([
            'tenant_id' => $this->tenant->id, 'period' => '2026-07', 'label' => 'July 2026', 'status' => 'finalized',
            'finalized_at' => now(),
        ]);
        Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->emp->id,
            'basic' => 5000, 'gross' => 5000, 'net_pay' => 4500, 'employer_cost' => 5500,
        ]);
        Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->otherEmp->id,
            'basic' => 4000, 'gross' => 4000, 'net_pay' => 3600, 'employer_cost' => 4400,
        ]);

        $response = $this->actingHr()->get(route('payroll.export.payslips-pdf', $run));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_employee_cannot_download_the_bulk_pdf(): void
    {
        $run = PayrollRun::forceCreate([
            'tenant_id' => $this->tenant->id, 'period' => '2026-07', 'label' => 'July 2026', 'status' => 'finalized',
            'finalized_at' => now(),
        ]);

        $this->actingEmployee()->get(route('payroll.export.payslips-pdf', $run))->assertForbidden();
    }

    public function test_overtime_line_shows_hours_and_multiplier_not_a_flattened_figure(): void
    {
        $payslip = $this->payslipFor($this->emp);

        $data = app(PayslipPdfData::class)->build($payslip->fresh(['lines']));

        $overtime = $data['earnings']->firstWhere('description', 'Overtime 1.5×');
        $this->assertNotNull($overtime);
        $this->assertSame('10 hrs', $overtime['period']);
        $this->assertSame('1.5×', $overtime['rate']);
        $this->assertEqualsWithDelta(187.5, $overtime['total'], 0.001);
    }

    /**
     * The payslip's three printed totals must agree: TOTAL EARNINGS − TOTAL DEDUCTIONS +
     * reimbursement === the NETT WAGE banner. Deductions must fold in EPF/SOCSO/EIS/PCB
     * (normal + bonus), zakat and CP38 — none of those are PayslipLine rows (see
     * PayslipPdfData::statutoryRows) — alongside an ordinary line-item deduction, all at
     * once, exactly the combination that previously left the deductions table at 0.00.
     */
    public function test_payslip_totals_reconcile_with_statutory_bonus_pcb_zakat_cp38_and_reimbursement(): void
    {
        $run = PayrollRun::forceCreate([
            'tenant_id' => $this->tenant->id, 'period' => '2026-08', 'label' => 'August 2026', 'status' => 'finalized',
            'finalized_at' => now(),
        ]);

        // Earnings lines: Basic 5000 + Allowance 200 = 5200 TOTAL EARNINGS.
        // Deductions: statutory 550+25+10+120+80+50+30=865, plus a Staff Loan line of 100
        // = 965 TOTAL DEDUCTIONS. Reimbursement 150. Nett = 5200 - 965 + 150 = 4385.
        $payslip = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->emp->id,
            'basic' => 5000, 'gross' => 5200,
            'epf_employee' => 550, 'epf_employer' => 650,
            'socso_employee' => 25, 'socso_employer' => 87.5,
            'eis_employee' => 10, 'eis_employer' => 10,
            'pcb' => 120, 'pcb_additional' => 80, 'zakat' => 50, 'cp38' => 30,
            'claims_reimbursement' => 150,
            'total_deductions' => 965, 'net_pay' => 4385, 'employer_cost' => 5947.5,
        ]);
        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'name' => 'Basic Salary',
            'type' => 'earning', 'amount' => 5000, 'source' => 'salary', 'sort_order' => 0,
        ]);
        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'name' => 'Allowance',
            'type' => 'earning', 'amount' => 200, 'source' => 'fixed-transaction', 'sort_order' => 1,
        ]);
        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'name' => 'Staff Loan',
            'type' => 'deduction', 'amount' => 100, 'source' => 'fixed-transaction', 'sort_order' => 2,
        ]);
        // Claim reimbursement must never render as an earning line — it is added after
        // deductions, not folded into gross (PayrollCalculator::compute).
        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'name' => 'Claim Reimbursement',
            'type' => 'earning', 'amount' => 150, 'source' => 'claim', 'sort_order' => 3,
        ]);

        $data = app(PayslipPdfData::class)->build($payslip->fresh(['lines']));

        $this->assertEqualsWithDelta(5200.0, $data['totalEarnings'], 0.001);
        $this->assertEqualsWithDelta(965.0, $data['totalDeductions'], 0.001);
        $this->assertEqualsWithDelta(150.0, $data['reimbursement'], 0.001);
        $this->assertEqualsWithDelta(
            (float) $payslip->net_pay,
            $data['totalEarnings'] - $data['totalDeductions'] + $data['reimbursement'],
            0.001,
        );

        $descriptions = $data['deductions']->pluck('description');
        $this->assertContains('EPF Employee Contribution', $descriptions);
        $this->assertContains('SOCSO Employee Contribution', $descriptions);
        $this->assertContains('EIS Employee Contribution', $descriptions);
        $this->assertContains('PCB (Income Tax)', $descriptions);
        $this->assertContains('PCB (Bonus / Additional)', $descriptions);
        $this->assertContains('Zakat', $descriptions);
        $this->assertContains('CP38', $descriptions);
        $this->assertContains('Staff Loan', $descriptions);
        $this->assertNotContains('Claim Reimbursement', $data['earnings']->pluck('description'));
    }

    public function test_single_payslip_pdf_is_exactly_one_page(): void
    {
        $payslip = $this->payslipFor($this->emp)->fresh(['employee.salaryStructure', 'employee.department', 'employee.employmentType', 'employee.leaveBalances.leaveType', 'payrollRun', 'lines']);

        $this->assertSame(1, $this->renderedPageCount([$payslip]));
    }

    public function test_bulk_pdf_has_one_page_per_payslip(): void
    {
        $run = PayrollRun::forceCreate([
            'tenant_id' => $this->tenant->id, 'period' => '2026-09', 'label' => 'September 2026', 'status' => 'finalized',
            'finalized_at' => now(),
        ]);
        $a = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->emp->id,
            'basic' => 5000, 'gross' => 5000, 'net_pay' => 4500, 'employer_cost' => 5500,
        ]);
        $b = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->otherEmp->id,
            'basic' => 4000, 'gross' => 4000, 'net_pay' => 3600, 'employer_cost' => 4400,
        ]);
        $payslips = collect([$a, $b])->map(fn (Payslip $p) => $p->fresh(['employee.salaryStructure', 'employee.department', 'employee.employmentType', 'employee.leaveBalances.leaveType', 'payrollRun', 'lines']));

        $this->assertSame(2, $this->renderedPageCount($payslips->all()));
    }

    /** @param  array<int, Payslip>  $payslips */
    private function renderedPageCount(array $payslips): int
    {
        $data = collect($payslips)->map(fn (Payslip $p) => app(PayslipPdfData::class)->build($p));
        $html = view('pdf.payslip', ['payslips' => $data])->render();

        $dompdf = new Dompdf;
        $dompdf->loadHtml($html);
        $dompdf->setPaper('a4');
        $dompdf->render();

        return $dompdf->getCanvas()->get_page_count();
    }

    public function test_year_to_date_includes_opening_figures_and_excludes_drafts(): void
    {
        PayrollOpeningFigure::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'year' => 2026,
            'epf' => 300, 'socso' => 20, 'eis' => 5, 'pcb_paid' => 60,
        ]);

        // An earlier finalized payslip in the same year — must be counted.
        $earlierRun = PayrollRun::forceCreate([
            'tenant_id' => $this->tenant->id, 'period' => '2026-04', 'status' => 'finalized', 'finalized_at' => now(),
        ]);
        Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $earlierRun->id, 'employee_id' => $this->emp->id,
            'epf_employee' => 500, 'epf_employer' => 600, 'socso_employee' => 25, 'socso_employer' => 87.5,
            'eis_employee' => 10, 'eis_employer' => 10, 'pcb' => 100, 'gross' => 5000, 'net_pay' => 4000, 'employer_cost' => 5500,
        ]);

        // A draft payslip in the same year, later period — must be excluded.
        $draftRun = PayrollRun::forceCreate([
            'tenant_id' => $this->tenant->id, 'period' => '2026-05', 'status' => 'draft',
        ]);
        Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $draftRun->id, 'employee_id' => $this->emp->id,
            'epf_employee' => 999, 'gross' => 5000, 'net_pay' => 4000, 'employer_cost' => 5500,
        ]);

        $payslip = $this->payslipFor($this->emp); // period 2026-06

        $ytd = app(PayslipYearToDate::class)->forPayslip($payslip);

        // opening 300 + earlier 500 + current 550 = 1350; draft 999 excluded.
        $this->assertEqualsWithDelta(1350.0, $ytd['epf']['employee']['ytd'], 0.001);
        $this->assertEqualsWithDelta(550.0, $ytd['epf']['employee']['month'], 0.001);
        // opening 20 + earlier 25 = 45 (current payslip's own socso_employee is 25 too)
        $this->assertEqualsWithDelta(70.0, $ytd['socso']['employee']['ytd'], 0.001);
    }
}
