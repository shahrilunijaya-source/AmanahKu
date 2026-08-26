<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Services\Payroll\Cp8dData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Cp8dDataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    private Cp8dData $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug' => 'acme', 'name' => 'Acme Sdn Bhd', 'initials' => 'AC', 'address' => '1 Jalan Test',
            'employer_tin' => '2900030000',
        ]);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Worker', 'staff_id' => 'AC-0007',
            'status' => 'active', 'workload' => 'green', 'nric' => '880101-14-5500',
            'marital_status' => 'single',
        ]);
        SalaryStructure::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'basic_salary' => 5000, 'tax_no' => 'SG12345678', 'children_relief_count' => 1,
        ]);
        PayrollItem::seedFor($this->tenant);

        $this->service = app(Cp8dData::class);
    }

    private function finalizedPayslip(): void
    {
        $run = PayrollRun::forceCreate([
            'tenant_id' => $this->tenant->id, 'period' => '2026-03', 'status' => 'finalized', 'finalized_at' => now(),
        ]);
        $item = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'basic-salary')->first();
        $payslip = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
            'basic' => 5000, 'gross' => 5000, 'epf_employee' => 550, 'socso_employee' => 25, 'eis_employee' => 10,
            'pcb' => 120.7, 'pcb_additional' => 0, 'zakat' => 15, 'cp38' => 30,
            'total_deductions' => 750, 'net_pay' => 4250, 'employer_cost' => 5675,
        ]);
        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'payroll_item_id' => $item->id,
            'name' => 'Basic Salary', 'type' => 'earning', 'amount' => 5000, 'source' => 'salary', 'sort_order' => 0,
        ]);
    }

    public function test_figures_come_from_the_underlying_payslips(): void
    {
        $this->finalizedPayslip();

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertSame('Worker', $data['name']);
        $this->assertSame('880101145500', $data['identification']); // dashes stripped
        $this->assertSame('12345678', $data['tin']); // SG prefix stripped
        $this->assertSame(5000.0, $data['total_gross_remuneration']);
        $this->assertSame(550.0, $data['epf_contribution']);
        $this->assertSame(25.0, $data['socso_contribution']);
        $this->assertSame(120.7, $data['mtd']);
        $this->assertSame(30.0, $data['cp38']);
        $this->assertSame(2000.0, $data['total_qualifying_child_relief']);
    }

    public function test_identification_falls_back_to_twelve_zeros_when_missing(): void
    {
        $this->employee->update(['nric' => null]);

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertSame('000000000000', $data['identification']);
    }

    public function test_category_reuses_the_pcb_category_derivation(): void
    {
        $this->employee->update(['marital_status' => 'married']);
        $this->employee->salaryStructure->update(['spouse_working' => false]);

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertSame(2, $data['category']); // married, spouse not working
    }

    public function test_tax_borne_by_employer_is_always_a_compulsory_gap(): void
    {
        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertNull($data['tax_borne_by_employer']);
        $this->assertContains(['field' => 'Field 7', 'label' => 'Tax borne by employer'], $data['incomplete']);
    }

    public function test_employment_type_maps_cleanly_to_employee_status(): void
    {
        $type = EmploymentType::create(['tenant_id' => $this->tenant->id, 'name' => 'Permanent']);
        $this->employee->update(['employment_type_id' => $type->id]);

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertSame(2, $data['employee_status']);
        $this->assertFalse(collect($data['incomplete'])->contains('field', 'Field 5'));
    }

    public function test_employment_type_with_no_clean_mapping_is_flagged_not_guessed(): void
    {
        $type = EmploymentType::create(['tenant_id' => $this->tenant->id, 'name' => 'Probation']);
        $this->employee->update(['employment_type_id' => $type->id]);

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertNull($data['employee_status']);
        $this->assertTrue(collect($data['incomplete'])->contains('field', 'Field 5'));
    }

    public function test_retirement_or_end_date_derives_from_archived_at_for_a_leaver(): void
    {
        $this->employee->update(['status' => 'resigned', 'archived_at' => '2026-06-15 00:00:00']);

        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertSame('2026-06-15', $data['retirement_or_end_date']->format('Y-m-d'));
        $this->assertFalse(collect($data['incomplete'])->contains('field', 'Field 6'));
    }

    public function test_retirement_or_end_date_is_a_compulsory_gap_for_a_continuing_employee(): void
    {
        $data = $this->service->forEmployee($this->tenant, $this->employee, 2026);

        $this->assertNull($data['retirement_or_end_date']);
        $this->assertTrue(collect($data['incomplete'])->contains('field', 'Field 6'));
    }

    public function test_employee_from_another_tenant_is_rejected(): void
    {
        $other = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'initials' => 'RV']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->forEmployee($other, $this->employee, 2026);
    }
}
