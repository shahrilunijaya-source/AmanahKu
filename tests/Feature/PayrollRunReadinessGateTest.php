<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollRunReadinessGateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    /** @param  array<string, mixed>  $structure */
    private function employee(string $name, array $structure = []): Employee
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'staff_id' => 'AC-'.$name, 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => 3000]);
        SalaryStructure::forceCreate(array_merge(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 3000,
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1'], $structure));

        return $emp;
    }

    public function test_unready_employee_blocks_and_names_the_field(): void
    {
        $this->employee('Ready');
        $this->employee('Gap', ['epf_no' => null]);
        $this->post(route('payroll.runs.create'), ['period' => '2026-06'])
            ->assertSessionHasErrors('readiness');
        $this->assertStringContainsString('Gap: EPF number', session('errors')->first('readiness'));
        $this->assertSame(0, PayrollRun::count());
    }

    public function test_excluding_the_employee_lets_the_run_through_and_stores_it(): void
    {
        $this->employee('Ready');
        $gap = $this->employee('Gap', ['epf_no' => null]);
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'exclude_employee_ids' => [$gap->id]])
            ->assertSessionHasNoErrors();
        $run = PayrollRun::firstOrFail();
        $this->assertSame([$gap->id], $run->excluded_employee_ids);
        $this->assertSame(1, $run->payslips()->count());
    }

    public function test_missing_tin_alone_does_not_block(): void
    {
        $this->employee('NoTin', ['tax_no' => null]);
        $this->post(route('payroll.runs.create'), ['period' => '2026-06'])->assertSessionHasNoErrors();
    }

    public function test_blank_employer_epf_number_blocks(): void
    {
        $this->tenant->update(['epf_employer_no' => null]);
        $this->employee('Ready');
        $this->post(route('payroll.runs.create'), ['period' => '2026-06'])->assertSessionHasErrors('readiness');
        $this->assertStringContainsString('EPF employer number', session('errors')->first('readiness'));
    }
}
