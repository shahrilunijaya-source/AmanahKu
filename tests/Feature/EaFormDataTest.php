<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollOpeningFigure;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Services\Payroll\EaFormData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EaFormDataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    private EaFormData $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug' => 'acme', 'name' => 'Acme Sdn Bhd', 'initials' => 'AC', 'address' => '1 Jalan Test',
        ]);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Worker', 'staff_id' => 'AC-0007',
            'status' => 'active', 'workload' => 'green', 'nric' => '880101-14-5500',
        ]);
        SalaryStructure::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'basic_salary' => 5000, 'tax_no' => 'SG12345678',
        ]);
        PayrollItem::seedFor($this->tenant);

        $this->service = app(EaFormData::class);
    }

    private function finalizedRun(string $period, string $status = 'finalized'): PayrollRun
    {
        return PayrollRun::forceCreate([
            'tenant_id' => $this->tenant->id, 'period' => $period, 'status' => $status,
            'finalized_at' => $status === 'finalized' ? now() : null,
        ]);
    }

    public function test_employee_with_no_payslips_returns_a_zeroed_structure(): void
    {
        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertSame(2026, $data['year']);
        $this->assertSame([], $data['employment_income']['by_category']);
        $this->assertSame(0.0, $data['employment_income']['overtime_total']);
        $this->assertSame(0.0, $data['employment_income']['taxable_total']);
        $this->assertSame(0.0, $data['employment_income']['tax_exempt_total']);
        $this->assertSame(0.0, $data['employment_income']['exempt_cap_candidates_total']);
        $this->assertSame(0.0, $data['deductions']['epf_employee']);
        $this->assertSame(0.0, $data['deductions']['pcb_total']);
        $this->assertNull($data['previous_employment']);
    }

    public function test_totals_reconcile_against_the_underlying_payslips(): void
    {
        $run = $this->finalizedRun('2026-03');
        $item = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'basic-salary')->first();
        $payslip = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
            'basic' => 5000, 'gross' => 5000, 'epf_employee' => 550, 'socso_employee' => 25, 'eis_employee' => 10,
            'pcb' => 120, 'pcb_additional' => 40, 'zakat' => 15, 'cp38' => 30,
            'total_deductions' => 750, 'net_pay' => 4250, 'employer_cost' => 5675,
        ]);
        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'payroll_item_id' => $item->id,
            'name' => 'Basic Salary', 'type' => 'earning', 'amount' => 5000, 'source' => 'salary', 'sort_order' => 0,
        ]);

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertSame(5000.0, array_sum($data['employment_income']['by_category']));
        $this->assertSame(550.0, $data['deductions']['epf_employee']);
        $this->assertSame(25.0, $data['deductions']['socso_employee']);
        $this->assertSame(10.0, $data['deductions']['eis_employee']);
        $this->assertSame(15.0, $data['deductions']['zakat']);
        // Normal (120) + additional/bonus (40) PCB reported as a single total.
        $this->assertSame(160.0, $data['deductions']['pcb_total']);
        // CP38 never folded into pcb_total.
        $this->assertSame(30.0, $data['deductions']['cp38']);
    }

    public function test_draft_runs_are_excluded(): void
    {
        $run = $this->finalizedRun('2026-05', 'draft');
        Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
            'basic' => 9000, 'gross' => 9000, 'epf_employee' => 900,
            'total_deductions' => 900, 'net_pay' => 8100, 'employer_cost' => 9900,
        ]);

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertSame([], $data['employment_income']['by_category']);
        $this->assertSame(0.0, $data['deductions']['epf_employee']);
    }

    public function test_a_line_whose_item_has_no_ea_box_lands_in_unclassified(): void
    {
        $run = $this->finalizedRun('2026-02');
        // A custom (non-system) item never gets an ea_box assigned automatically.
        $item = PayrollItem::forceCreate([
            'tenant_id' => $this->tenant->id, 'code' => 'test-no-box-item', 'name' => 'Custom Perk',
            'type' => 'earning', 'epf_liable' => false, 'perkeso_liable' => false, 'pcb_taxable' => true,
            'source' => 'manual', 'is_system' => false, 'active' => true, 'sort_order' => 99,
        ]);
        $payslip = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
            'gross' => 300, 'total_deductions' => 0, 'net_pay' => 300, 'employer_cost' => 300,
        ]);
        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'payroll_item_id' => $item->id,
            'name' => 'Custom Perk', 'type' => 'earning', 'amount' => 300, 'source' => 'fixed-transaction', 'sort_order' => 0,
        ]);

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertSame(300.0, $data['employment_income']['by_category']['unclassified']);
        $this->assertArrayNotHasKey('B1(a)', $data['employment_income']['by_category']);
    }

    public function test_ea_box_from_the_catalogue_item_is_used_when_present(): void
    {
        $run = $this->finalizedRun('2026-02');
        // 'basic-salary' is seeded with ea_box 'B1(a)'.
        $item = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'basic-salary')->first();
        $payslip = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
            'gross' => 4000, 'total_deductions' => 0, 'net_pay' => 4000, 'employer_cost' => 4000,
        ]);
        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'payroll_item_id' => $item->id,
            'name' => 'Basic Salary', 'type' => 'earning', 'amount' => 4000, 'source' => 'salary', 'sort_order' => 0,
        ]);

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertSame(4000.0, $data['employment_income']['by_category']['B1(a)']);
    }

    public function test_overtime_is_separately_identifiable_but_not_double_counted(): void
    {
        $run = $this->finalizedRun('2026-02');
        $basicItem = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'basic-salary')->first();
        $overtimeItem = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'overtime')->first();
        $payslip = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
            'gross' => 4200, 'total_deductions' => 0, 'net_pay' => 4200, 'employer_cost' => 4200,
        ]);
        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'payroll_item_id' => $basicItem->id,
            'name' => 'Basic Salary', 'type' => 'earning', 'amount' => 4000, 'source' => 'salary', 'sort_order' => 0,
        ]);
        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'payroll_item_id' => $overtimeItem->id,
            'name' => 'Overtime 1.5×', 'type' => 'earning', 'amount' => 200, 'quantity' => 10, 'source' => 'overtime', 'sort_order' => 1,
        ]);

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertSame(200.0, $data['employment_income']['overtime_total']);
        // Overtime's catalogue item is B1(a), same box as basic salary — LHDN wants
        // overtime folded into "gross salary ... including overtime pay", not reported
        // separately. The overtime total (200) is a subset of B1(a) (4200), not additive.
        $this->assertSame(4200.0, $data['employment_income']['by_category']['B1(a)']);
        $this->assertSame(4200.0, array_sum($data['employment_income']['by_category']));
    }

    public function test_exempt_and_taxable_allowances_are_reported_separately(): void
    {
        $run = $this->finalizedRun('2026-02');
        // 'travel-allowance' is pcb_taxable = true in the catalogue (LHDN's RM6,000 cap is
        // recorded but not applied), so use a manually flagged exempt item to exercise the
        // pcb_taxable = false branch directly.
        $exemptItem = PayrollItem::forceCreate([
            'tenant_id' => $this->tenant->id, 'code' => 'test-exempt-item', 'name' => 'Test Exempt Perk',
            'type' => 'earning', 'epf_liable' => false, 'perkeso_liable' => false, 'pcb_taxable' => false,
            'source' => 'manual', 'is_system' => false, 'active' => true, 'sort_order' => 99,
        ]);
        $taxableItem = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'basic-salary')->first();

        $payslip = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
            'gross' => 5100, 'total_deductions' => 0, 'net_pay' => 5100, 'employer_cost' => 5100,
        ]);
        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'payroll_item_id' => $taxableItem->id,
            'name' => 'Basic Salary', 'type' => 'earning', 'amount' => 5000, 'source' => 'salary', 'sort_order' => 0,
        ]);
        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'payroll_item_id' => $exemptItem->id,
            'name' => 'Test Exempt Perk', 'type' => 'earning', 'amount' => 100, 'source' => 'manual', 'sort_order' => 1,
        ]);

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertSame(5000.0, $data['employment_income']['taxable_total']);
        $this->assertSame(100.0, $data['employment_income']['tax_exempt_total']);
    }

    public function test_previous_employer_figures_are_returned_separately_not_merged(): void
    {
        PayrollOpeningFigure::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'year' => 2026,
            'gross' => 12000, 'epf' => 1320, 'socso' => 60, 'eis' => 20, 'zakat_paid' => 100, 'pcb_paid' => 300,
            'previous_employer' => 'Old Co Sdn Bhd', 'previous_employer_tin' => 'C1234567890',
        ]);
        $run = $this->finalizedRun('2026-06');
        Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
            'gross' => 5000, 'epf_employee' => 550, 'total_deductions' => 550, 'net_pay' => 4450, 'employer_cost' => 5650,
        ]);

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertNotNull($data['previous_employment']);
        $this->assertSame('Old Co Sdn Bhd', $data['previous_employment']['previous_employer']);
        $this->assertSame(12000.0, $data['previous_employment']['gross']);
        // Never merged into this employer's own deductions total for the year.
        $this->assertSame(550.0, $data['deductions']['epf_employee']);
    }

    public function test_previous_employer_exempt_allowances_are_included_in_the_component(): void
    {
        PayrollOpeningFigure::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'year' => 2026,
            'gross' => 12000, 'exempt_allowances' => 500,
        ]);

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertSame(500.0, $data['previous_employment']['exempt_allowances']);
    }

    public function test_a_payslip_issued_before_payslip_lines_existed_falls_back_to_lumped_columns(): void
    {
        $run = $this->finalizedRun('2026-04');
        // No PayslipLine rows at all — a legacy payslip.
        Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
            'basic' => 3000, 'allowances_total' => 200, 'bonus' => 100,
            'overtime_amount' => 50, 'additions' => [['name' => 'Ad-hoc', 'amount' => 25]],
            'gross' => 3375, 'total_deductions' => 0, 'net_pay' => 3375, 'employer_cost' => 3375,
        ]);

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        // 3000 + 200 + 100 + 50 + 25 = 3375, all landing in "unclassified" since no
        // per-line ea_box survives a legacy payslip.
        $this->assertSame(3375.0, $data['employment_income']['by_category']['unclassified']);
        $this->assertSame(50.0, $data['employment_income']['overtime_total']);
        $this->assertSame(3375.0, $data['employment_income']['taxable_total']);
        $this->assertSame(0.0, $data['employment_income']['tax_exempt_total']);
    }

    public function test_missing_employer_tax_reference_number_is_surfaced_as_null(): void
    {
        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertSame('Acme Sdn Bhd', $data['employer']['name']);
        $this->assertSame('1 Jalan Test', $data['employer']['address']);
        $this->assertNull($data['employer']['income_tax_reference_no']);
    }
}
