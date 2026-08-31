<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use App\Services\Payroll\FormEData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormEDataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private FormEData $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug' => 'acme', 'name' => 'Acme Sdn Bhd', 'initials' => 'AC', 'address' => '1 Jalan Test',
            'employer_tin' => '2900030000', 'registration_number' => '199901012345',
            'contact_number' => '03-12345678', 'email' => 'hr@acme.test',
        ]);
        $this->service = app(FormEData::class);
    }

    private function employee(string $status, ?string $joinedAt, ?string $archivedAt): Employee
    {
        return Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Worker '.uniqid(), 'staff_id' => uniqid(),
            'status' => $status, 'workload' => 'green', 'joined_at' => $joinedAt, 'archived_at' => $archivedAt,
        ]);
    }

    private function finalizedPayslip(Employee $employee, string $period, float $pcb = 0.0): void
    {
        $run = PayrollRun::where('tenant_id', $this->tenant->id)->where('period', $period)->first()
            ?? PayrollRun::forceCreate([
                'tenant_id' => $this->tenant->id, 'period' => $period, 'status' => 'finalized', 'finalized_at' => now(),
            ]);
        Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'basic' => 5000, 'gross' => 5000, 'pcb' => $pcb, 'total_deductions' => $pcb, 'net_pay' => 5000 - $pcb,
            'employer_cost' => 5000,
        ]);
    }

    public function test_a1_counts_employees_employed_through_31_december(): void
    {
        // Employed all year.
        $this->employee('active', '2020-01-01', null);
        // Joined mid-year, still employed.
        $this->employee('active', '2026-06-01', null);
        // Left before year-end — excluded from A1.
        $this->employee('resigned', '2020-01-01', '2026-05-01');
        // Left AFTER year-end — still counted as employed at 31/12.
        $this->employee('resigned', '2020-01-01', '2027-01-15');

        $data = $this->service->build($this->tenant, 2026);

        $this->assertSame(3, $data['part_a']['a1']);
    }

    public function test_a2_counts_only_employees_with_finalized_mtd_this_year(): void
    {
        $withMtd = $this->employee('active', '2020-01-01', null);
        $this->finalizedPayslip($withMtd, '2026-03', pcb: 120.0);

        $withoutMtd = $this->employee('active', '2020-01-01', null);
        $this->finalizedPayslip($withoutMtd, '2026-03', pcb: 0.0);

        $data = $this->service->build($this->tenant, 2026);

        $this->assertSame(1, $data['part_a']['a2']);
    }

    public function test_a3_counts_new_employees_joined_within_the_year(): void
    {
        $this->employee('active', '2026-03-01', null); // new this year
        $this->employee('active', '2020-01-01', null); // long-serving

        $data = $this->service->build($this->tenant, 2026);

        $this->assertSame(1, $data['part_a']['a3']);
    }

    public function test_a4_counts_leavers_within_the_year(): void
    {
        $this->employee('resigned', '2020-01-01', '2026-08-15'); // left this year
        $this->employee('resigned', '2020-01-01', '2025-08-15'); // left last year
        $this->employee('active', '2020-01-01', null); // still employed

        $data = $this->service->build($this->tenant, 2026);

        $this->assertSame(1, $data['part_a']['a4']);
    }

    public function test_a5_and_a6_have_no_data_and_stay_null(): void
    {
        $data = $this->service->build($this->tenant, 2026);

        $this->assertNull($data['part_a']['a5']);
        $this->assertNull($data['part_a']['a6']);
    }

    public function test_basic_particulars_pulled_from_tenant(): void
    {
        $data = $this->service->build($this->tenant, 2026);

        $this->assertSame('Acme Sdn Bhd', $data['basic_particulars']['name']);
        $this->assertSame('2900030000', $data['basic_particulars']['employer_tin']);
        $this->assertSame('199901012345', $data['basic_particulars']['ssm_registration_no']);
        $this->assertSame(1, $data['basic_particulars']['furnish_of_cp8d']);
        $this->assertNull($data['basic_particulars']['category_of_employer']);
    }

    public function test_unfilled_employer_tin_is_flagged(): void
    {
        $this->tenant->update(['employer_tin' => null]);

        $data = $this->service->build($this->tenant, 2026);

        $this->assertTrue(collect($data['incomplete'])->contains('label', "Employer's TIN"));
    }
}
