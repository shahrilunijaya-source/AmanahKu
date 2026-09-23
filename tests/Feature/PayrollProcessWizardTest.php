<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\IndividualTransaction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The Process Payroll wizard on Pay & Benefits → Process → Monthly (spec 2026-09-24). */
class PayrollProcessWizardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme Sdn Bhd', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->employee('Boss', ['user_id' => $this->hr->id]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function employee(string $name, array $attributes = [], bool $withStructure = true): Employee
    {
        $emp = Employee::create(array_merge(['tenant_id' => $this->tenant->id, 'name' => $name, 'staff_id' => 'AC-'.$name, 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => 3000], $attributes));
        if ($withStructure) {
            SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 3000,
                'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1']);
        }

        return $emp;
    }

    private function asHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_hr_sees_the_four_step_wizard_and_its_fields(): void
    {
        $this->asHr()->get('/app/payroll-process')->assertOk()
            ->assertSee('x-data="payrollWizard(', false)
            ->assertSee('id="create-run-form"', false)
            ->assertSeeInOrder(['Period', 'Condition', 'Selected Employees', 'Results'])
            ->assertSeeInOrder(['Select Payroll Cycle', 'Bonus (Bonus)', 'Mid Month (MM)', 'Month End (ME)', 'Final Pay'])
            ->assertSeeInOrder(['Pull Attendance Data', 'Pull Monthly Allowance/Deduction', 'Pull Monthly Overtime Allowance', 'Pull Claim Data', 'Pull Unpaid Data', 'Pull Absent As Unpaid', 'Pull None-Shift As Unpaid', 'Overwrite Pulled Transactions'])
            ->assertSee('Payroll Policies')
            ->assertSee('Choose one of the selection method below:')
            ->assertSee('Your Selection Summary')
            ->assertSee('Additional Selections')
            ->assertSee('Process Payroll')
            ->assertSee('Payroll runs')
            ->assertSee('name="mid_month_basis"', false)
            ->assertSee('name="mid_month_value"', false)
            ->assertSee('name="remarks"', false)
            ->assertSee('name="include_employee_ids[]"', false);
    }

    public function test_the_pull_ticks_default_to_unticked_like_worksy(): void
    {
        $html = $this->asHr()->get('/app/payroll-process')->getContent();

        foreach (['pull_fixed', 'pull_claims', 'pull_overtime', 'pull_unpaid'] as $name) {
            $this->assertStringContainsString('<input type="hidden" name="'.$name.'" value="0">', $html);
            $this->assertMatchesRegularExpression('/<input type="checkbox" name="'.$name.'" value="1"\s+style=/', $html);
        }
    }

    public function test_the_wizard_gets_every_payable_employee_with_names_resolved_and_readiness(): void
    {
        $dept = Department::forceCreate(['tenant_id' => $this->tenant->id, 'name' => 'Finance']);
        $ready = $this->employee('Aina', ['department_id' => $dept->id, 'gender' => 'female', 'photo' => '/storage/photos/aina.jpg']);
        $this->employee('Gap', [], true)->salaryStructure->update(['epf_no' => null]);
        $this->employee('NoStructure', [], false);
        $this->employee('Archived', ['archived_at' => now()]);
        $paidOut = $this->employee('Leaver', ['last_working_day' => now()->toDateString()]);
        PayrollItem::seedFor($this->tenant);
        IndividualTransaction::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $ready->id,
            'payroll_item_id' => PayrollItem::where('tenant_id', $this->tenant->id)->value('id'),
            'period' => '2026-12', 'amount' => 500, 'for_bonus_run' => true]);

        $this->asHr()->get('/app/payroll-process')->assertOk()->assertViewHas('payrollWizard', function (array $w) use ($ready, $paidOut) {
            $people = collect($w['people'])->keyBy('name');
            $this->assertSame(['Aina', 'Boss', 'Gap', 'Leaver'], $people->keys()->sort()->values()->all());
            $this->assertSame('Finance', $people['Aina']['department']);
            $this->assertSame('female', $people['Aina']['gender']);
            $this->assertSame('/storage/photos/aina.jpg', $people['Aina']['photo']);
            $this->assertNull($people['Gap']['photo']);
            $this->assertSame('AC-Aina', $people['Aina']['staff_id']);
            $this->assertSame([], $people['Aina']['blocking']);
            $this->assertSame(['EPF number'], $people['Gap']['blocking']);
            $this->assertSame([['name' => 'NoStructure', 'blocking' => ['Salary structure']]], $w['outside']);
            $this->assertSame([$ready->id], $w['bonusByPeriod']['2026-12']);
            $this->assertSame([$paidOut->id], array_column($w['leavers'], 'id'));
            $this->assertSame('Acme Sdn Bhd', $w['company']);

            return true;
        });
    }

    public function test_the_results_step_shows_the_created_run(): void
    {
        $this->asHr()->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'monthly'])->assertSessionHasNoErrors();
        $run = PayrollRun::firstOrFail();

        $this->asHr()->get('/app/payroll-process?tab=monthly&step=results&run='.$run->id)->assertOk()
            ->assertViewHas('wizardResultRun', fn ($r) => $r?->is($run))
            ->assertDontSee('id="create-run-form"', false)
            ->assertSee($run->label)
            ->assertSee('Review payroll')
            ->assertSee('Process another')
            ->assertSee(e(route('app.screen', ['screen' => 'payroll-review', 'tab' => 'individual', 'run' => $run->id])), false)
            ->assertSee('RM '.number_format((float) ($run->totals['net'] ?? 0), 2));
    }

    public function test_a_validation_error_reopens_the_wizard_with_the_old_input(): void
    {
        // Seeded straight into the session in its stored (json) shape: a POST-then-GET in one
        // test keeps the live ViewErrorBag in memory, which the json session loader then empties.
        $this->asHr()->withSession([
            '_old_input' => ['period' => '2026-06', 'kind' => 'monthly', 'remarks' => 'Keep me'],
            'errors' => ['default' => ['format' => ':message', 'messages' => [
                'period' => ['A payroll run already exists for 2026-06.'],
                'readiness' => ['Not ready to run payroll. Gap: EPF number'],
            ]]],
        ])->get('/app/payroll-process')->assertOk()
            ->assertSee('The run was not created.')
            ->assertSee('A payroll run already exists for 2026-06.')
            ->assertSee('Not ready to run payroll. Gap: EPF number')
            ->assertSee('Keep me');
    }

    public function test_an_employee_gets_no_wizard(): void
    {
        $user = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee('Worker', ['user_id' => $user->id]);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        $this->get('/app/payroll-process')->assertForbidden();
        $this->get('/app/payroll-my')->assertOk()->assertDontSee('payrollWizard(', false);
    }
}
