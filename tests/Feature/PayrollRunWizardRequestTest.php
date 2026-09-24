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

/**
 * The Process Payroll wizard's extra request fields on payroll.runs.create:
 * include_employee_ids, remarks, and the results-step redirect.
 */
class PayrollRunWizardRequestTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
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

    public function test_include_list_narrows_the_run_and_stores_everyone_else_as_excluded(): void
    {
        $a = $this->employee('Alice');
        $b = $this->employee('Bob');
        $c = $this->employee('Cara');

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'include_employee_ids' => [$a->id, $c->id]])
            ->assertSessionHasNoErrors();

        $run = PayrollRun::firstOrFail();
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $run->payslips()->pluck('employee_id')->all());
        $this->assertSame([$b->id], $run->excluded_employee_ids);
    }

    public function test_included_person_with_a_gap_still_blocks_the_run(): void
    {
        $ready = $this->employee('Ready');
        $gap = $this->employee('Gap', ['epf_no' => null]);

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'include_employee_ids' => [$ready->id, $gap->id]])
            ->assertSessionHasErrors('readiness');
        $this->assertStringContainsString('Gap: EPF number', session('errors')->first('readiness'));
        $this->assertSame(0, PayrollRun::count());
    }

    public function test_person_left_out_of_the_include_list_does_not_block(): void
    {
        $ready = $this->employee('Ready');
        $this->employee('Gap', ['epf_no' => null]);

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'include_employee_ids' => [$ready->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, PayrollRun::firstOrFail()->payslips()->count());
    }

    public function test_staff_without_a_salary_structure_do_not_block_a_wizard_run(): void
    {
        $ready = $this->employee('Ready');
        Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'NoStructure', 'staff_id' => 'AC-NS', 'status' => 'active', 'workload' => 'green']);

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'include_employee_ids' => [$ready->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, PayrollRun::firstOrFail()->payslips()->count());
    }

    public function test_remarks_are_saved_and_success_redirects_to_the_results_step(): void
    {
        $this->employee('Alice');

        $response = $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'remarks' => 'June run, two new joiners.']);

        $run = PayrollRun::firstOrFail();
        $this->assertSame('June run, two new joiners.', $run->remarks);
        $response->assertRedirect(route('app.screen', ['screen' => 'payroll-process', 'tab' => 'monthly', 'step' => 'results', 'run' => $run->id]))
            ->assertSessionHas('ok');
    }

    public function test_validation_failure_goes_back_with_input(): void
    {
        $this->from('/app/payroll-process')
            ->post(route('payroll.runs.create'), ['period' => 'nope', 'remarks' => 'x'])
            ->assertRedirect('/app/payroll-process')
            ->assertSessionHasErrors('period');
    }
}
