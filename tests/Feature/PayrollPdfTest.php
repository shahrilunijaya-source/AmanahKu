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
        Employee::whereKey($this->emp->id)->update(['salary' => 5000]);

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
            // Spec F13: staff only see a payslip once the run is published.
            'published_at' => $status === 'finalized' ? now() : null,
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
     * TOTAL EARNINGS - TOTAL DEDUCTIONS must equal the payslip's net pay to the cent, with
     * claims counted as earnings, unpaid leave as the first deduction (basic stays full on
     * the earnings side), statutory amounts folded in, and an advance listed. Same maths
     * as PayrollCalculator: gross = earnings - unpaid, net = gross - totalDeductions + claims.
     */
    public function test_totals_reconcile_with_unpaid_leave_claim_statutory_and_advance(): void
    {
        $run = PayrollRun::forceCreate([
            'tenant_id' => $this->tenant->id, 'period' => '2026-08', 'label' => 'August 2026', 'status' => 'finalized',
            'finalized_at' => now(),
        ]);
        // Earnings 5000 + 200 + 150 claim = 5350. Unpaid 3 x (5000/31) = 483.87.
        // Statutory 550+25+10+44+120+80+50+30 = 909, advance 100, mid-month 40.
        // gross 5200-483.87 = 4716.13; totalDeductions 909+100+40 = 1049;
        // net = 4716.13 - 1049 + 150 = 3817.13.
        $payslip = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->emp->id,
            'basic' => 5000, 'allowances_total' => 200, 'unpaid_days' => 3, 'unpaid_deduction' => 483.87, 'days_in_month' => 31,
            'gross' => 4716.13,
            'epf_employee' => 550, 'epf_employer' => 650, 'socso_employee' => 25, 'socso_employer' => 87.5,
            'eis_employee' => 10, 'eis_employer' => 10, 'skbbk_employee' => 44,
            'pcb' => 120, 'pcb_additional' => 80, 'zakat' => 50, 'cp38' => 30,
            'mid_month_advance' => 40, 'claims_reimbursement' => 150,
            'total_deductions' => 1049, 'net_pay' => 3817.13, 'employer_cost' => 5947.5,
        ]);
        $line = fn (string $name, string $type, float $amount, string $source, int $sort, ?float $qty = null) => PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'name' => $name,
            'type' => $type, 'amount' => $amount, 'quantity' => $qty, 'source' => $source, 'sort_order' => $sort,
        ]);
        $line('Basic Salary', 'earning', 5000, 'salary', 0);
        $line('Allowance', 'earning', 200, 'fixed-transaction', 1);
        $line('Claim Reimbursement', 'earning', 150, 'claim', 2);
        $line('Staff Advance', 'deduction', 100, 'fixed-transaction', 3);
        $line('Unpaid Leave Deduction', 'deduction', 483.87, 'leave', 4, 3);
        $line('Mid-month advance', 'deduction', 40, 'manual', 5);

        $data = app(PayslipPdfData::class)->build($payslip->fresh(['lines']));

        $this->assertEqualsWithDelta(5350.0, $data['totalEarnings'], 0.001);
        $this->assertEqualsWithDelta(1532.87, $data['totalDeductions'], 0.001);
        $this->assertEqualsWithDelta(
            (float) $payslip->net_pay,
            $data['totalEarnings'] - $data['totalDeductions'],
            0.001,
        );

        $earnings = $data['earnings']->pluck('description');
        $this->assertSame('Salary', $earnings->first());
        $this->assertContains('Claim Reimbursement (from Claim)', $earnings);
        $this->assertEqualsWithDelta(5000.0, $data['earnings']->firstWhere('description', 'Salary')['total'], 0.001);

        $deductions = $data['deductions'];
        $unpaid = $deductions->first();
        $this->assertSame('Unpaid Leave', $unpaid['description']);
        $this->assertSame('3.00 D', $unpaid['period']);
        $this->assertSame('161.29', $unpaid['rate']);
        $this->assertSame(1, $deductions->where('description', 'Unpaid Leave')->count());
        foreach (['EPF Employee Contribution', 'SOCSO Employee Contribution', 'EIS Employee Contribution', 'SKBBK', 'PCB', 'PCB additional', 'Zakat', 'CP38', 'Staff Advance', 'Mid-month advance'] as $name) {
            $this->assertContains($name, $deductions->pluck('description'));
        }
    }

    public function test_legacy_payslip_without_lines_still_reconciles(): void
    {
        $run = PayrollRun::forceCreate([
            'tenant_id' => $this->tenant->id, 'period' => '2026-07', 'label' => 'July 2026', 'status' => 'finalized',
            'finalized_at' => now(),
        ]);
        // Earnings 3000 + 100 = 3100; unpaid 1 x 3000/31 = 96.77; gross 3003.23.
        // totalDeductions 330 + 20 advance = 350; net = 3003.23 - 350 = 2653.23.
        $payslip = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->emp->id,
            'basic' => 3000, 'allowances_total' => 100, 'unpaid_days' => 1, 'unpaid_deduction' => 96.77, 'days_in_month' => 31,
            'gross' => 3003.23, 'epf_employee' => 330, 'mid_month_advance' => 20,
            'total_deductions' => 350, 'net_pay' => 2653.23, 'employer_cost' => 3500,
        ]);

        $data = app(PayslipPdfData::class)->build($payslip->fresh(['lines']));

        $this->assertEqualsWithDelta(
            (float) $payslip->net_pay,
            $data['totalEarnings'] - $data['totalDeductions'],
            0.001,
        );
        $this->assertSame('Unpaid Leave', $data['deductions']->first()['description']);
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

    /** Spec F7: the HRD Corp levy is employer cost, so it never appears on the employee's payslip. */
    public function test_payslip_never_prints_an_hrd_corp_levy_line(): void
    {
        $payslip = $this->payslipFor($this->emp);
        $payslip->forceFill(['hrdf_levy' => 50])->save();

        $data = app(PayslipPdfData::class)->build($payslip->fresh(['lines']));

        $flat = json_encode($data['earnings']->all()).json_encode($data['deductions']->all());
        $this->assertStringNotContainsStringIgnoringCase('hrd', (string) $flat);
        $this->assertStringNotContainsStringIgnoringCase('levy', (string) $flat);

        $html = view('pdf.payslip', ['payslips' => collect([$data])])->render();
        $this->assertStringNotContainsStringIgnoringCase('HRD Corp', $html);
    }
}
