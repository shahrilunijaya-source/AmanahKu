<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Services\Payroll\Statutory\HrdCorpLevyFile;
use App\Services\Payroll\Statutory\KwspFormA;
use App\Services\Payroll\Statutory\PerkesoBorang8A;
use App\Services\Payroll\Statutory\StatutoryFileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class StatutoryAgencyFilesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '9123456708', 'epf_employer_no' => '012345678', 'socso_employer_code' => 'B3200012345Z', 'hrdf_registration_no' => 'HRD-7788']);
        $this->run = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => '2026-06', 'label' => 'June 2026', 'status' => 'finalized', 'finalized_at' => now()]);
        $this->slip('Aminah binti Ali', '880101-14-5500', 'EPF11112222', '880101145500', 5000, 0, 550, 650, 24.75, 86.65, 9.90, 9.90, 50.00);
        $this->slip('Tan Wei Ming', '900202-10-5511', 'EPF33334444', '900202105511', 8000, 500, 880, 960, 29.75, 104.15, 11.90, 11.90, 80.00);
    }

    private function slip(string $name, string $nric, string $epfNo, string $socsoNo, float $gross, float $ot, float $epfEe, float $epfEr, float $socEe, float $socEr, float $eisEe, float $eisEr, float $hrdf): void
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'staff_id' => 'AC-'.substr($nric, 0, 4), 'status' => 'active', 'workload' => 'green', 'nric' => $nric]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => $gross - $ot, 'epf_no' => $epfNo, 'socso_no' => $socsoNo]);
        Payslip::forceCreate(['tenant_id' => $this->tenant->id, 'payroll_run_id' => $this->run->id, 'employee_id' => $emp->id,
            'basic' => $gross - $ot, 'overtime_amount' => $ot, 'gross' => $gross,
            'epf_employee' => $epfEe, 'epf_employer' => $epfEr, 'socso_employee' => $socEe, 'socso_employer' => $socEr,
            'eis_employee' => $eisEe, 'eis_employer' => $eisEr, 'hrdf_levy' => $hrdf, 'net_pay' => 1]);
    }

    /** @return Collection<int, Payslip> */
    private function slips(): Collection
    {
        return $this->run->payslips()->with('employee.salaryStructure')->get()->sortBy(fn (Payslip $p) => $p->employee?->name)->values();
    }

    public function test_kwsp_form_a_matches_golden_and_uses_next_month(): void
    {
        $out = (new KwspFormA)->build($this->run, $this->tenant, $this->slips());
        $this->assertSame(file_get_contents(base_path('tests/Fixtures/statutory/kwsp-form-a-2026-06.csv')), $out);
        $this->assertStringContainsString('012345678,072026,1610.00,1430.00,2', $out);
        $this->assertStringContainsString('EPF33334444,900202105511,TAN WEI MING,7500.00,960.00,880.00', $out);   // wages exclude overtime
    }

    public function test_perkeso_file_matches_v2_1_golden_with_skbbk_and_uncapped_wage(): void
    {
        $this->slips()->first()->forceFill(['skbbk_employee' => 37.50])->save();   // Aminah

        $out = (new PerkesoBorang8A)->build($this->run, $this->tenant, $this->slips());
        $this->assertSame(file_get_contents(base_path('tests/Fixtures/statutory/perkeso-8a-2026-06.txt')), $out);
        foreach (explode("\r\n", $out) as $line) {
            $this->assertSame(278, strlen($line));
        }
        [$aminah, $tan] = explode("\r\n", $out);
        $this->assertSame('062026', substr($aminah, 194, 6));              // the wage month, not the month after
        $this->assertSame('  3750', substr($aminah, 238, 6));              // SKBBK in its own field 11
        $this->assertSame('        800000', substr($tan, 200, 14));        // actual wage, not capped at RM6,000
        $this->assertSame('  0000', substr($tan, 238, 6));                 // no SKBBK written as 0000
        $this->assertSame('PERKESO-8A-B3200012345Z-062026.txt', (new PerkesoBorang8A)->filename($this->run, $this->tenant));
    }

    public function test_perkeso_file_uses_socso_number_for_a_worker_without_nric(): void
    {
        $slip = $this->slips()->first();
        $slip->employee->update(['nric' => null]);
        $slip->employee->salaryStructure->update(['socso_no' => 'SSFW-1234 5678']);

        $out = (new PerkesoBorang8A)->build($this->run, $this->tenant, $this->slips());
        $this->assertSame('SSFW12345678', substr(explode("\r\n", $out)[0], 32, 12));
    }

    public function test_hrd_corp_file_matches_golden_with_total(): void
    {
        $out = (new HrdCorpLevyFile)->build($this->run, $this->tenant, $this->slips());
        $this->assertSame(file_get_contents(base_path('tests/Fixtures/statutory/hrdcorp-2026-06.csv')), $out);
        $this->assertStringContainsString('TOTAL,,,,12500.00,130.00', $out);
    }

    public function test_registry_lists_all_four_and_flags_unverified_layouts(): void
    {
        $all = StatutoryFileRegistry::all();
        $this->assertSame(['kwsp-form-a', 'perkeso-8a', 'cp39', 'hrdcorp'], array_keys($all));
        $this->assertTrue($all['cp39']->verified());
        $this->assertTrue($all['perkeso-8a']->verified());
        $this->assertFalse($all['kwsp-form-a']->verified());
        $this->assertNull(StatutoryFileRegistry::find('nope'));
    }
}
