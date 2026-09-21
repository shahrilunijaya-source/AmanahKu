<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Services\FeatureManager;
use App\Services\Payroll\PayrollReadiness;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $structure
     */
    private function readyEmployee(array $overrides = [], array $structure = []): Employee
    {
        $emp = Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => 'Worker', 'staff_id' => 'AC-'.random_int(1, 9999), 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => 3000,
        ], $overrides));
        SalaryStructure::forceCreate(array_merge([
            'tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 3000,
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '514011223344',
            'epf_no' => '12345678', 'socso_no' => '880101145500', 'tax_no' => 'SG12345678',
        ], $structure));

        return $emp;
    }

    public function test_a_complete_employee_has_no_gaps(): void
    {
        $this->readyEmployee();
        $rows = app(PayrollReadiness::class)->employeeRows($this->tenant);
        $this->assertCount(1, $rows);
        $this->assertSame([], $rows[0]['blocking']);
        $this->assertSame([], $rows[0]['warnings']);
    }

    public function test_missing_items_are_named_and_a_missing_tin_only_warns(): void
    {
        $this->readyEmployee(['nric' => null, 'salary' => 0], ['epf_no' => null, 'bank_code' => null, 'tax_no' => null]);
        $rows = app(PayrollReadiness::class)->employeeRows($this->tenant);
        $this->assertSame(['Basic pay', 'NRIC', 'EPF number', 'Bank'], $rows[0]['blocking']);
        $this->assertSame(['TIN'], $rows[0]['warnings']);
    }

    public function test_socso_exempt_skips_the_socso_number(): void
    {
        $this->readyEmployee([], ['socso_no' => null, 'socso_exempt' => true]);
        $this->assertSame([], app(PayrollReadiness::class)->employeeRows($this->tenant)[0]['blocking']);
    }

    public function test_employee_without_a_structure_blocks_and_exclusion_lifts_it(): void
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'New', 'staff_id' => 'AC-9', 'status' => 'active', 'workload' => 'green']);
        $svc = app(PayrollReadiness::class);
        $this->assertSame(['Salary structure'], $svc->blockingRows($this->tenant)[0]['blocking']);
        $this->assertSame([], $svc->blockingRows($this->tenant, [$emp->id]));
    }

    public function test_employer_gaps_name_the_blank_fields(): void
    {
        $this->tenant->update(['socso_employer_code' => null]);
        $this->assertSame(['SOCSO employer code'], app(PayrollReadiness::class)->employerGaps($this->tenant->fresh()));
    }

    public function test_no_hrdf_warning_below_ten_malaysian_employees(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->readyEmployee([], ['nationality' => 'citizen']);
        }
        $this->assertSame([], app(PayrollReadiness::class)->companyWarnings($this->tenant));
    }

    public function test_hrdf_warning_at_ten_malaysian_employees_and_none_once_the_levy_is_on(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->readyEmployee([], ['nationality' => 'citizen']);
        }
        $this->readyEmployee([], ['nationality' => 'foreign']);

        $this->assertSame(
            ['HRD Corp levy is off but the company has 10 Malaysian employees; registration is mandatory at 10.'],
            app(PayrollReadiness::class)->companyWarnings($this->tenant),
        );

        app(FeatureManager::class)->setTenant($this->tenant, 'payroll.hrdf', '1');
        $this->assertSame([], app(PayrollReadiness::class)->companyWarnings($this->tenant->fresh()));
    }
}
