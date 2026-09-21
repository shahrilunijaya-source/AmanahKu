<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\IndividualTransaction;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmploymentRecordService;
use App\Services\Payroll\BackPay;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Spec F15: a raise backdated into finalized months queues back pay into the next run. */
class PayrollBackPayTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
        app(CurrentTenant::class)->set($this->tenant);

        $this->emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Worker', 'staff_id' => 'AC-0007',
            'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05', 'salary' => 2300,
        ]);
        BackPay::pullNotes();
    }

    /** @param array<string, mixed> $payslip */
    private function finalized(string $period, array $payslip = [], string $kind = 'monthly', ?Tenant $tenant = null): void
    {
        $days = (int) date('t', strtotime($period.'-01'));
        $run = PayrollRun::forceCreate([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'period' => $period, 'kind' => $kind,
            'label' => $period, 'status' => 'finalized', 'finalized_at' => now(),
        ]);
        Payslip::forceCreate($payslip + [
            'tenant_id' => $run->tenant_id, 'payroll_run_id' => $run->id, 'employee_id' => $this->emp->id,
            'basic' => 2300, 'gross' => 2300, 'net_pay' => 2300, 'days_employed' => $days, 'days_in_month' => $days,
        ]);
    }

    private function raise(float $to, string $effectiveOn): void
    {
        app(EmploymentRecordService::class)->update($this->emp, $effectiveOn, ['salary' => $to], null, null);
    }

    public function test_raise_backdated_two_months_queues_one_back_pay_item_in_the_next_period(): void
    {
        $this->finalized('2026-07');
        $this->finalized('2026-08');

        $this->raise(2500, '2026-07-01');

        $tx = IndividualTransaction::sole();
        $this->assertSame('2026-09', $tx->period);
        $this->assertEqualsWithDelta(400.00, (float) $tx->amount, 0.001);
        $this->assertSame('back-pay', $tx->payrollItem->code);
        $this->assertStringContainsString('2,300.00 to RM 2,500.00', $tx->remarks);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Queued back pay']);
        $this->assertStringContainsString('RM 400.00 queued for the 2026-09 pay run', BackPay::noteSuffix());
    }

    public function test_mid_month_effective_date_pays_only_the_days_from_that_date(): void
    {
        $this->finalized('2026-08');

        $this->raise(2610, '2026-08-21'); // 11 of 31 days at RM 310 more

        $this->assertEqualsWithDelta(110.00, (float) IndividualTransaction::sole()->amount, 0.001);
    }

    public function test_a_payslip_prorated_for_a_mid_month_joiner_prorates_the_back_pay_too(): void
    {
        $this->finalized('2026-06', ['days_employed' => 15]);

        $this->raise(2600, '2026-06-01'); // 15 of 30 days at RM 300 more

        $this->assertEqualsWithDelta(150.00, (float) IndividualTransaction::sole()->amount, 0.001);
    }

    public function test_months_with_an_overridden_basic_are_skipped_and_named(): void
    {
        $this->finalized('2026-07', ['basic_overridden' => true]);
        $this->finalized('2026-08');

        $this->raise(2500, '2026-07-01');

        $tx = IndividualTransaction::sole();
        $this->assertEqualsWithDelta(200.00, (float) $tx->amount, 0.001);
        $this->assertStringContainsString('skipped (basic overridden): 2026-07', $tx->remarks);
    }

    public function test_a_backdated_pay_cut_queues_nothing_and_warns(): void
    {
        $this->finalized('2026-08');

        $this->raise(2000, '2026-08-01');

        $this->assertSame(0, IndividualTransaction::count());
        $this->assertStringContainsString('Nothing was deducted', BackPay::noteSuffix());
    }

    public function test_nothing_happens_without_a_finalized_monthly_run_on_or_after_the_date(): void
    {
        $this->finalized('2026-07');
        $this->finalized('2026-08', [], 'bonus');

        $this->raise(2500, '2026-08-01');

        $this->assertSame(0, IndividualTransaction::count());
        $this->assertSame('', BackPay::noteSuffix());
    }

    public function test_prior_year_arrears_are_flagged_for_pcb(): void
    {
        $this->emp->update(['joined_at' => '2025-01-06']);
        $this->finalized('2025-12');
        $this->finalized('2026-01');

        $this->raise(2500, '2025-12-01');

        $this->assertStringContainsString('prior year arrears', IndividualTransaction::sole()->remarks);
    }

    public function test_another_tenants_runs_do_not_move_the_target_period(): void
    {
        $this->finalized('2026-08');
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        PayrollRun::forceCreate(['tenant_id' => $other->id, 'period' => '2026-12', 'kind' => 'monthly', 'label' => 'x', 'status' => 'finalized', 'finalized_at' => now()]);

        $this->raise(2500, '2026-08-01');

        $this->assertSame('2026-09', IndividualTransaction::sole()->period);
    }

    public function test_batch_salary_tool_queues_back_pay_and_says_so(): void
    {
        $this->finalized('2026-08');

        $this->post(route('progression.batch.salary'), [
            'employee_ids' => [$this->emp->id], 'effective_on' => '2026-08-01', 'mode' => 'increase_amount', 'value' => 100,
        ])->assertSessionHas('ok', fn (string $ok) => str_contains($ok, 'back pay of RM 100.00'));

        $this->assertEqualsWithDelta(100.00, (float) IndividualTransaction::sole()->amount, 0.001);
    }
}
