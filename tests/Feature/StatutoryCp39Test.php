<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Services\Payroll\Statutory\LhdnCp39;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatutoryCp39Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'employer_tin' => '9123456708']);
        $this->run = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => '2026-06', 'label' => 'June 2026', 'status' => 'finalized', 'finalized_at' => now()]);
        $this->slip('Aminah binti Ali', 'AC-0001', '880101-14-5500', 'IG 531367080', 120.50, 15.00, 0);
        $this->slip('Tan Wei Ming', 'AC-0002', '900202-10-5511', null, 0, 0, 50.00);
    }

    private function slip(string $name, string $staffId, string $nric, ?string $tin, float $pcb, float $pcbAdd, float $cp38): void
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'staff_id' => $staffId, 'status' => 'active', 'workload' => 'green', 'nric' => $nric]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 5000, 'tax_no' => $tin, 'nationality' => 'citizen']);
        Payslip::forceCreate(['tenant_id' => $this->tenant->id, 'payroll_run_id' => $this->run->id, 'employee_id' => $emp->id,
            'basic' => 5000, 'gross' => 5000, 'pcb' => $pcb, 'pcb_additional' => $pcbAdd, 'cp38' => $cp38, 'net_pay' => 4000]);
    }

    private function build(): string
    {
        $slips = $this->run->payslips()->with('employee.salaryStructure')->get()->sortBy(fn (Payslip $p) => $p->employee?->name)->values();

        return (new LhdnCp39)->build($this->run, $this->tenant, $slips);
    }

    public function test_matches_the_golden_file(): void
    {
        $this->assertSame(file_get_contents(base_path('tests/Fixtures/statutory/cp39-2026-06.txt')), $this->build());
    }

    public function test_field_offsets_follow_exhibit_4(): void
    {
        [$h, $d1, $d2] = explode("\r\n", $this->build());
        $this->assertSame(57, strlen($h));
        $this->assertSame('H', $h[0]);
        $this->assertSame('9123456708', substr($h, 1, 10));
        $this->assertSame('9123456708', substr($h, 11, 10));
        $this->assertSame('2026', substr($h, 21, 4));
        $this->assertSame('06', substr($h, 25, 2));
        $this->assertSame('0000013550', substr($h, 27, 10));   // 120.50 + 15.00
        $this->assertSame('00001', substr($h, 37, 5));
        $this->assertSame('0000005000', substr($h, 42, 10));
        $this->assertSame('00001', substr($h, 52, 5));

        $this->assertSame(136, strlen($d1));
        $this->assertSame('D', $d1[0]);
        $this->assertSame('00531367080', substr($d1, 1, 11));
        $this->assertSame(str_pad('AMINAH BINTI ALI', 60), substr($d1, 12, 60));
        $this->assertSame(str_repeat(' ', 12), substr($d1, 72, 12));
        $this->assertSame('880101145500', substr($d1, 84, 12));
        $this->assertSame('MY', substr($d1, 108, 2));
        $this->assertSame('00013550', substr($d1, 110, 8));
        $this->assertSame('00000000', substr($d1, 118, 8));
        $this->assertSame(str_pad('AC-0001', 10), substr($d1, 126, 10));

        // No TIN: zeros in the TIN field, NRIC in the IC field.
        $this->assertSame('00000000000', substr($d2, 1, 11));
        $this->assertSame('900202105511', substr($d2, 84, 12));
        $this->assertSame('00005000', substr($d2, 118, 8));
    }

    public function test_filename_is_employer_number_month_year(): void
    {
        $this->assertSame('912345670806_2026.txt', (new LhdnCp39)->filename($this->run, $this->tenant));
    }

    public function test_employees_with_no_mtd_and_no_cp38_are_left_out(): void
    {
        $this->slip('Zero Tax', 'AC-0003', '950303-10-5522', 'SG1', 0, 0, 0);
        $this->assertCount(3, explode("\r\n", $this->build()));
    }
}
