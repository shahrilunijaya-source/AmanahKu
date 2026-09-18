<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollHrdfLevyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123', 'hrdf_registration_no' => 'H-1']);
        PayrollItem::seedFor($this->tenant);
        app(FeatureManager::class)->setTenant($this->tenant, 'payroll.hrdf', '1');
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    /** @param  array<string, mixed>  $structure */
    private function employee(string $name, array $structure = []): Employee
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'staff_id' => 'AC-'.$name, 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => 3000]);
        SalaryStructure::forceCreate(array_merge(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 3000, 'nationality' => 'citizen',
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1'], $structure));

        return $emp;
    }

    public function test_citizen_gets_one_percent_foreigner_and_exempt_get_zero(): void
    {
        $citizen = $this->employee('Citizen');
        $foreign = $this->employee('Foreign', ['nationality' => 'foreign']);
        $exempt = $this->employee('Exempt', ['hrdf_exempt' => true]);

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])->assertSessionHasNoErrors();

        $this->assertSame(30.00, (float) Payslip::where('employee_id', $citizen->id)->value('hrdf_levy'));
        $this->assertSame(0.0, (float) Payslip::where('employee_id', $foreign->id)->value('hrdf_levy'));
        $this->assertSame(0.0, (float) Payslip::where('employee_id', $exempt->id)->value('hrdf_levy'));
    }

    public function test_setting_off_means_no_levy(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'payroll.hrdf', 'off');
        $this->employee('Citizen');
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) Payslip::firstOrFail()->hrdf_levy);
    }

    public function test_levy_on_requires_the_registration_number(): void
    {
        $this->tenant->update(['hrdf_registration_no' => null]);
        $this->employee('Citizen');
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])->assertSessionHasErrors('readiness');
    }
}
