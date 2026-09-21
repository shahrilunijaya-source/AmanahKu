<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollCp38Notice;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollCp38NoticeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        PayrollItem::seedFor($this->tenant);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->emp = $this->employee('Worker');
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    private function employee(string $name): Employee
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'staff_id' => 'AC-'.$name, 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => 3000]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 3000, 'nationality' => 'citizen',
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1']);

        return $emp;
    }

    /** Create and finalize one monthly run, and give back the employee's payslip. */
    private function runMonth(string $period): Payslip
    {
        $this->post(route('payroll.runs.create'), ['period' => $period, 'payment_date' => $period.'-28'])->assertSessionHasNoErrors();
        $run = PayrollRun::where('period', $period)->firstOrFail();
        $this->post(route('payroll.runs.finalize', $run), ['payment_date' => $period.'-28'])->assertSessionHasNoErrors();

        return Payslip::where('payroll_run_id', $run->id)->where('employee_id', $this->emp->id)->firstOrFail();
    }

    private function notice(float $total = 1000, float $monthly = 300): PayrollCp38Notice
    {
        $this->post(route('payroll.cp38.store'), [
            'employee_id' => $this->emp->id, 'reference' => 'CP38/1', 'notice_date' => '2026-05-20',
            'total_amount' => $total, 'monthly_instalment' => $monthly, 'first_period' => '2026-06',
        ])->assertSessionHasNoErrors();

        return PayrollCp38Notice::where('employee_id', $this->emp->id)->latest('id')->firstOrFail();
    }

    public function test_a_thousand_ringgit_notice_at_three_hundred_runs_down_and_completes(): void
    {
        $notice = $this->notice();
        $this->assertSame(1000.0, $notice->remaining_balance);

        $taken = [];
        foreach (['2026-06', '2026-07', '2026-08', '2026-09', '2026-10'] as $period) {
            $taken[] = (float) $this->runMonth($period)->cp38;
        }

        $this->assertSame([300.0, 300.0, 300.0, 100.0, 0.0], $taken);
        $notice->refresh();
        $this->assertSame(0.0, $notice->remaining_balance);
        $this->assertSame('completed', $notice->status);
    }

    public function test_deleting_the_fourth_finalized_run_restores_the_balance(): void
    {
        $notice = $this->notice();
        foreach (['2026-06', '2026-07', '2026-08', '2026-09'] as $period) {
            $this->runMonth($period);
        }
        $this->assertSame('completed', $notice->refresh()->status);

        $boss = User::create(['name' => 'Director', 'email' => 'dir@example.com', 'password' => Hash::make('password')]);
        $boss->tenants()->attach($this->tenant->id, ['role' => 'director']);
        $run = PayrollRun::where('period', '2026-09')->firstOrFail();
        $this->actingAs($boss)->withSession(['current_tenant' => $this->tenant->id])
            ->post(route('payroll.runs.delete', $run), ['confirm_period' => '2026-09'])->assertSessionHasNoErrors();

        $notice->refresh();
        $this->assertSame(100.0, $notice->remaining_balance);
        $this->assertSame('active', $notice->status);
    }

    public function test_deleting_a_draft_run_leaves_the_balance_alone(): void
    {
        $notice = $this->notice();
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-28'])->assertSessionHasNoErrors();
        $run = PayrollRun::where('period', '2026-06')->firstOrFail();
        $this->assertSame(300.0, (float) Payslip::where('payroll_run_id', $run->id)->value('cp38'));

        $this->post(route('payroll.runs.delete', $run))->assertSessionHasNoErrors();

        $this->assertSame(1000.0, $notice->refresh()->remaining_balance);
        $this->assertSame('active', $notice->status);
    }

    public function test_an_open_ended_notice_keeps_deducting(): void
    {
        PayrollCp38Notice::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id,
            'monthly_instalment' => 120, 'first_period' => '2026-06', 'status' => 'active']);

        $this->assertSame(120.0, (float) $this->runMonth('2026-06')->cp38);
        $this->assertSame(120.0, (float) $this->runMonth('2026-07')->cp38);
    }

    public function test_another_tenants_notice_cannot_be_cancelled(): void
    {
        $other = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'initials' => 'RV']);
        $otherEmp = Employee::create(['tenant_id' => $other->id, 'name' => 'Theirs', 'status' => 'active', 'workload' => 'green']);
        $foreign = PayrollCp38Notice::forceCreate(['tenant_id' => $other->id, 'employee_id' => $otherEmp->id,
            'monthly_instalment' => 50, 'first_period' => '2026-06', 'status' => 'active']);

        $this->post(route('payroll.cp38.cancel', $foreign))->assertForbidden();
        $this->assertSame('active', $foreign->refresh()->status);
    }

    public function test_a_manager_cannot_record_a_notice(): void
    {
        $manager = User::create(['name' => 'Mgr', 'email' => 'mgr@example.com', 'password' => Hash::make('password')]);
        $manager->tenants()->attach($this->tenant->id, ['role' => 'manager']);

        $this->actingAs($manager)->withSession(['current_tenant' => $this->tenant->id])
            ->post(route('payroll.cp38.store'), ['employee_id' => $this->emp->id, 'monthly_instalment' => 100, 'first_period' => '2026-06'])
            ->assertForbidden();
    }

    public function test_a_cancelled_notice_stops_deducting(): void
    {
        $notice = $this->notice();
        $this->post(route('payroll.cp38.cancel', $notice))->assertSessionHasNoErrors();

        $this->assertSame(0.0, (float) $this->runMonth('2026-06')->cp38);
    }
}
