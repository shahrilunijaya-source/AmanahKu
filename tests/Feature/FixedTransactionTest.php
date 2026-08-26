<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FixedTransaction;
use App\Models\OffboardingCase;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Fixed Transactions: recurring per-employee pay/deduction lines against the Payroll
 * Item catalogue — the second "payroll module polish" pass. See CLAUDE.md.
 */
class FixedTransactionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private Employee $emp1;

    private PayrollItem $fixedAllowance;

    private PayrollItem $staffLoan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->emp1 = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green']);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'basic_salary' => 5000]);

        PayrollItem::seedFor($this->tenant);
        $this->fixedAllowance = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'fixed-allowance')->firstOrFail();
        $this->staffLoan = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'staff-loan')->firstOrFail();
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function createRun(string $period): PayrollRun
    {
        $this->actingHr()->post('/app/payroll/runs', ['period' => $period])->assertRedirect();

        return PayrollRun::where('tenant_id', $this->tenant->id)->where('period', $period)->firstOrFail();
    }

    private function ft(array $overrides = []): FixedTransaction
    {
        return FixedTransaction::forceCreate(array_merge([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->emp1->id,
            'payroll_item_id' => $this->fixedAllowance->id,
            'amount' => 300,
            'start_period' => '2026-01',
            'end_period' => null,
            'prorate' => false,
        ], $overrides));
    }

    // ── Period range ─────────────────────────────────────────────

    public function test_a_transaction_inside_its_period_appears_on_the_payslip(): void
    {
        $this->ft(['start_period' => '2026-01', 'end_period' => '2026-12']);

        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertSame(300.0, (float) $slip->allowances_total);
        $this->assertTrue($slip->lines->contains(fn ($l) => $l->name === 'Fixed Allowance' && (float) $l->amount === 300.0));
    }

    public function test_a_transaction_outside_its_period_does_not_appear(): void
    {
        $this->ft(['start_period' => '2026-01', 'end_period' => '2026-03']);

        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertSame(0.0, (float) $slip->allowances_total);
        $this->assertFalse($slip->lines->contains('name', 'Fixed Allowance'));
    }

    public function test_a_transaction_not_yet_started_does_not_appear(): void
    {
        $this->ft(['start_period' => '2026-08', 'end_period' => null]);

        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertSame(0.0, (float) $slip->allowances_total);
    }

    // ── last_amount ──────────────────────────────────────────────

    public function test_last_amount_applies_only_in_the_final_month(): void
    {
        $this->ft(['amount' => 300, 'last_amount' => 120, 'start_period' => '2026-01', 'end_period' => '2026-06']);

        $earlier = $this->createRun('2026-05')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertSame(300.0, (float) $earlier->allowances_total);

        $final = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertSame(120.0, (float) $final->allowances_total);
    }

    // ── Proration ────────────────────────────────────────────────

    public function test_a_joiner_mid_month_gets_the_calendar_day_prorated_amount(): void
    {
        // August 2026 has 31 days; joined on the 16th → 16 days employed (16..31 inclusive).
        $this->emp1->update(['joined_at' => '2026-08-16']);
        $this->ft(['amount' => 3100, 'start_period' => '2026-08', 'prorate' => true]);

        $slip = $this->createRun('2026-08')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertEqualsWithDelta(3100.0 * 16 / 31, (float) $slip->allowances_total, 0.001);
    }

    public function test_prorate_off_means_the_full_amount_regardless_of_join_date(): void
    {
        $this->emp1->update(['joined_at' => '2026-08-16']);
        $this->ft(['amount' => 3100, 'start_period' => '2026-08', 'prorate' => false]);

        $slip = $this->createRun('2026-08')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertSame(3100.0, (float) $slip->allowances_total);
    }

    public function test_a_leaver_with_an_offboarding_case_gets_the_calendar_day_prorated_amount(): void
    {
        // September 2026 has 30 days; last day the 10th → 10 days employed.
        OffboardingCase::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id,
            'last_day' => '2026-09-10', 'reason' => 'resignation', 'status' => 'in_progress',
        ]);
        $this->ft(['amount' => 3000, 'start_period' => '2026-01', 'prorate' => true]);

        $slip = $this->createRun('2026-09')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertEqualsWithDelta(3000.0 * 10 / 30, (float) $slip->allowances_total, 0.001);
    }

    public function test_a_leaver_with_no_offboarding_case_is_treated_as_full_month(): void
    {
        // No OffboardingCase at all — the only leaving-date signal there is — so this
        // employee is simply treated as employed the whole month (documented limitation).
        $this->ft(['amount' => 3000, 'start_period' => '2026-01', 'prorate' => true]);

        $slip = $this->createRun('2026-09')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertSame(3000.0, (float) $slip->allowances_total);
    }

    // ── Wage-base flags ──────────────────────────────────────────

    public function test_the_items_flags_drive_the_epf_and_perkeso_bases(): void
    {
        PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'fixed-allowance')
            ->update(['epf_liable' => false, 'perkeso_liable' => true]);
        $this->ft(['amount' => 500, 'start_period' => '2026-01', 'end_period' => null]);

        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        // Gross includes it, EPF (11% of basic 5000 = 550) does not.
        $this->assertSame(5500.0, (float) $slip->gross);
        $this->assertSame(550.0, (float) $slip->epf_employee);
    }

    public function test_a_deduction_type_transaction_reduces_net_pay_not_gross(): void
    {
        $this->ft(['payroll_item_id' => $this->staffLoan->id, 'amount' => 200, 'start_period' => '2026-01']);

        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertSame(5000.0, (float) $slip->gross);          // unaffected
        $this->assertSame(200.0, (float) $slip->fixed_deductions_total);
        $this->assertTrue($slip->lines->contains(fn ($l) => $l->name === 'Staff Loan' && $l->type === 'deduction' && (float) $l->amount === 200.0));
    }

    // ── Ending a transaction ─────────────────────────────────────

    public function test_ending_a_transaction_stops_it_next_month_without_touching_the_issued_payslip(): void
    {
        $ft = $this->ft(['amount' => 300, 'start_period' => '2026-01']);

        $juneRun = $this->createRun('2026-06');
        $this->actingHr()->post("/app/payroll/runs/{$juneRun->id}/finalize")->assertRedirect();
        $juneSlip = $juneRun->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertSame(300.0, (float) $juneSlip->allowances_total);

        $this->actingHr()->post("/app/payroll/fixed-transactions/{$ft->id}/end", ['end_period' => '2026-06'])->assertRedirect();
        $this->assertSame('2026-06', $ft->fresh()->end_period);

        // July's run no longer picks it up.
        $julySlip = $this->createRun('2026-07')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertSame(0.0, (float) $julySlip->allowances_total);

        // June's already-issued payslip is untouched.
        $juneSlip->refresh();
        $this->assertSame(300.0, (float) $juneSlip->allowances_total);
    }

    public function test_ending_never_deletes_the_row(): void
    {
        $ft = $this->ft();

        $this->actingHr()->post("/app/payroll/fixed-transactions/{$ft->id}/end", ['end_period' => '2026-03'])->assertRedirect();

        $this->assertDatabaseHas('fixed_transactions', ['id' => $ft->id, 'end_period' => '2026-03']);
    }

    // ── Migrated allowances ──────────────────────────────────────

    public function test_migrated_allowances_produce_the_same_payslip_totals_as_before(): void
    {
        SalaryStructure::where('employee_id', $this->emp1->id)->update([
            'basic_salary' => 4000,
            'allowances' => [['name' => 'Transport', 'amount' => 250], ['name' => 'Meal', 'amount' => 150]],
        ]);

        // Run the data migration directly (RefreshDatabase already ran it once with no
        // data — same technique as PayrollItem::seedFor's own migration test).
        $migration = require database_path('migrations/2026_08_25_200200_migrate_allowances_to_fixed_transactions.php');
        $migration->up();

        $transactions = FixedTransaction::where('employee_id', $this->emp1->id)->get();
        $this->assertCount(2, $transactions);
        $this->assertEqualsWithDelta(400.0, (float) $transactions->sum('amount'), 0.001);
        $this->assertTrue($transactions->contains(fn ($t) => $t->remarks === 'Transport' && (float) $t->amount === 250.0));
        $this->assertNull($transactions->first()->end_period);   // open-ended

        // Deliberately not retroactive (see the migration) — the transactions start
        // from the month the migration ran, so run generation for that same month picks
        // them up.
        $slip = $this->createRun(now()->format('Y-m'))->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        // Same total as the old JSON would have produced: 4000 basic + 400 allowances.
        $this->assertSame(400.0, (float) $slip->allowances_total);
        $this->assertSame(4400.0, (float) $slip->gross);
    }
}
