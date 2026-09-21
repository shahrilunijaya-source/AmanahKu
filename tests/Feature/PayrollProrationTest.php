<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollProrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        PayrollItem::seedFor($this->tenant);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Joiner', 'staff_id' => 'AC-1', 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2028-02-08', 'salary' => 3000]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'basic_salary' => 3000,
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    public function test_joiner_basic_is_prorated_and_day_counts_stored(): void
    {
        $this->post(route('payroll.runs.create'), ['period' => '2028-02'])->assertSessionHasNoErrors();
        $p = Payslip::firstOrFail();
        $this->assertSame(2275.86, $p->basic);
        $this->assertSame(22, $p->days_employed);
        $this->assertSame(29, $p->days_in_month);
        $this->assertFalse($p->basic_overridden);
    }

    public function test_last_working_day_wins_over_full_month(): void
    {
        $this->emp->update(['joined_at' => '2020-01-01', 'last_working_day' => '2028-04-15']);
        $this->post(route('payroll.runs.create'), ['period' => '2028-04'])->assertSessionHasNoErrors();
        $this->assertSame(1500.00, Payslip::firstOrFail()->basic);
    }

    public function test_hr_can_override_the_prorated_basic(): void
    {
        $this->post(route('payroll.runs.create'), ['period' => '2028-02']);
        $p = Payslip::firstOrFail();
        $this->post(route('payroll.payslips.update', $p), ['basic' => '3000'])->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame(3000.00, $p->basic);
        $this->assertTrue($p->basic_overridden);
    }

    public function test_item_flag_off_disables_proration(): void
    {
        PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'basic-salary')->update(['prorate_on_incomplete_month' => false]);
        $this->post(route('payroll.runs.create'), ['period' => '2028-02']);
        $this->assertSame(3000.00, Payslip::firstOrFail()->basic);
    }
}
