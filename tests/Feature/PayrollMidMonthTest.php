<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\AccountingJournal;
use App\Services\Payroll\EaFormData;
use App\Services\Payroll\PcbYearToDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Mid Month runs: a basic-only advance with no statutory, taken back out of net pay by
 * the month-end run, and left out of every year-to-date and EA total.
 */
class PayrollMidMonthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private Employee $emp1;   // salary 5000

    private Employee $emp2;   // salary 3000

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $identifiers = ['epf_no' => '1', 'socso_no' => '1', 'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'tax_no' => 'SG1'];
        $this->emp1 = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green',
            'nric' => '900101-14-5501', 'date_of_birth' => '1990-01-01', 'joined_at' => '2020-01-01', 'salary' => 5000]);
        $this->emp2 = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
            'nric' => '900101-14-5502', 'date_of_birth' => '1990-01-01', 'joined_at' => '2020-01-01', 'salary' => 3000]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'basic_salary' => 5000] + $identifiers);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp2->id, 'basic_salary' => 3000] + $identifiers);

        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    private function midMonth(string $basis = 'cutoff', ?int $value = null, string $period = '2026-06'): PayrollRun
    {
        $this->post(route('payroll.runs.create'), array_filter([
            'period' => $period, 'kind' => 'mid_month', 'mid_month_basis' => $basis, 'mid_month_value' => $value,
            'payment_date' => $period.'-15',
        ]))->assertSessionHasNoErrors();

        return PayrollRun::where('kind', 'mid_month')->where('period', $period)->firstOrFail();
    }

    private function monthEnd(string $period = '2026-06'): PayrollRun
    {
        $this->post(route('payroll.runs.create'), ['period' => $period, 'payment_date' => $period.'-30'])->assertSessionHasNoErrors();

        return PayrollRun::where('kind', 'monthly')->where('period', $period)->firstOrFail();
    }

    private function slip(PayrollRun $run, Employee $employee): Payslip
    {
        return $run->payslips()->where('employee_id', $employee->id)->firstOrFail();
    }

    public function test_cutoff_basis_pays_calendar_days_to_the_cutoff_with_no_statutory(): void
    {
        $run = $this->midMonth('cutoff', 15);

        $this->assertSame('June 2026 mid month', $run->label);
        $this->assertSame('cutoff', $run->mid_month_basis);
        $this->assertSame(15, $run->mid_month_value);

        // June has 30 days: 5000 × 15 / 30.
        $slip = $this->slip($run, $this->emp1);
        $this->assertEqualsWithDelta(2500.0, (float) $slip->basic, 0.001);
        $this->assertEqualsWithDelta(2500.0, (float) $slip->gross, 0.001);
        $this->assertEqualsWithDelta(2500.0, (float) $slip->net_pay, 0.001);
        foreach (['epf_employee', 'epf_employer', 'socso_employee', 'socso_employer', 'eis_employee', 'eis_employer', 'pcb', 'pcb_additional', 'zakat', 'cp38', 'hrdf_levy', 'total_deductions'] as $column) {
            $this->assertSame(0.0, (float) $slip->{$column}, $column);
        }
        $this->assertSame(['Basic Salary'], $slip->lines()->pluck('name')->all());
        $this->assertEqualsWithDelta(1500.0, (float) $this->slip($run, $this->emp2)->net_pay, 0.001);
    }

    public function test_percentage_basis_pays_a_share_of_salary(): void
    {
        $run = $this->midMonth('percentage', 40);

        $this->assertEqualsWithDelta(2000.0, (float) $this->slip($run, $this->emp1)->net_pay, 0.001);
        $this->assertEqualsWithDelta(1200.0, (float) $this->slip($run, $this->emp2)->net_pay, 0.001);
    }

    public function test_default_values_are_fifteen_days_and_fifty_percent(): void
    {
        $this->assertSame(15, $this->midMonth('cutoff')->mid_month_value);
    }

    public function test_someone_joining_after_the_cutoff_gets_no_advance(): void
    {
        $this->emp2->update(['joined_at' => '2026-06-20']);

        $run = $this->midMonth('percentage', 50);

        $this->assertSame([$this->emp1->id], $run->payslips()->pluck('employee_id')->all());
    }

    public function test_mid_month_run_with_nobody_due_an_advance_is_refused(): void
    {
        Employee::query()->update(['joined_at' => '2026-06-20']);

        $this->from('/app/payroll-process')
            ->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'mid_month', 'mid_month_basis' => 'cutoff'])
            ->assertRedirect('/app/payroll-process')
            ->assertSessionHasErrors('period');
        $this->assertStringContainsString('Nobody is due a mid-month advance for June 2026', session('errors')->first('period'));
        $this->assertSame(0, PayrollRun::count());
        $this->assertSame(0, Payslip::count());
    }

    public function test_journals_book_the_advance_to_its_own_account_and_wages_once(): void
    {
        $mm = $this->midMonth('cutoff', 15);
        $this->post(route('payroll.runs.finalize', $mm))->assertSessionHasNoErrors();
        $me = $this->monthEnd();
        $this->post(route('payroll.runs.finalize', $me))->assertSessionHasNoErrors();

        $mmTotals = AccountingJournal::totals($mm->payslips()->get(), true);
        $meTotals = AccountingJournal::totals($me->payslips()->get(), false);
        $this->assertTrue(AccountingJournal::balanced($mmTotals));
        $this->assertTrue(AccountingJournal::balanced($meTotals));

        // Wages expense across both runs is the month-end gross only (no allowances here).
        $meGrossSen = (int) round((float) $me->payslips()->sum('gross') * 100);
        $this->assertSame(0, $mmTotals['salaries']);
        $this->assertSame($meGrossSen, $mmTotals['salaries'] + $meTotals['salaries']);

        // The advance account nets to zero: 2500 + 1500 paid, the same taken back.
        $this->assertSame(400000, $mmTotals['salary_advance']);
        $this->assertSame($mmTotals['salary_advance'], $meTotals['salary_advance_recovered']);
        $this->assertSame(0, $mmTotals['salary_advance_recovered']);
        $this->assertSame(0, $meTotals['salary_advance']);

        // The export itself goes through too.
        $this->get(route('payroll.export.journal', $mm))->assertOk();
        $this->get(route('payroll.export.journal', $me))->assertOk();
    }

    public function test_month_end_takes_the_advance_off_net_only(): void
    {
        // Baseline month end with no mid-month run, then delete it and redo with one.
        $baseline = $this->slip($this->monthEnd(), $this->emp1)->only(['gross', 'epf_employee', 'epf_employer', 'socso_employee', 'eis_employee', 'pcb', 'hrdf_levy', 'net_pay', 'total_deductions']);
        $this->post(route('payroll.runs.delete', PayrollRun::firstOrFail()))->assertRedirect();

        $mm = $this->midMonth('cutoff', 15);
        $this->post(route('payroll.runs.approve', $mm))->assertRedirect();
        $slip = $this->slip($this->monthEnd(), $this->emp1);

        foreach (['gross', 'epf_employee', 'epf_employer', 'socso_employee', 'eis_employee', 'pcb', 'hrdf_levy'] as $column) {
            $this->assertEqualsWithDelta((float) $baseline[$column], (float) $slip->{$column}, 0.001, $column);
        }
        $this->assertEqualsWithDelta(2500.0, (float) $slip->mid_month_advance, 0.001);
        $this->assertEqualsWithDelta((float) $baseline['net_pay'] - 2500, (float) $slip->net_pay, 0.001);
        $this->assertEqualsWithDelta((float) $baseline['total_deductions'] + 2500, (float) $slip->total_deductions, 0.001);
        $this->assertEqualsWithDelta(2500.0, (float) $slip->lines()->where('name', 'Mid-month advance')->value('amount'), 0.001);

        // A payslip recompute keeps the deduction and the line.
        $this->post(route('payroll.payslips.update', $slip), ['bonus' => 0])->assertRedirect();
        $slip->refresh();
        $this->assertEqualsWithDelta(2500.0, (float) $slip->mid_month_advance, 0.001);
        $this->assertEqualsWithDelta((float) $baseline['net_pay'] - 2500, (float) $slip->net_pay, 0.001);
        $this->assertSame(1, $slip->lines()->where('name', 'Mid-month advance')->count());
    }

    public function test_mid_month_is_refused_once_month_end_exists(): void
    {
        $this->monthEnd();

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'mid_month', 'mid_month_basis' => 'cutoff'])
            ->assertSessionHasErrors('period');
        $this->assertSame(0, PayrollRun::where('kind', 'mid_month')->count());
    }

    public function test_month_end_waits_while_mid_month_is_a_draft(): void
    {
        $mm = $this->midMonth();

        $this->post(route('payroll.runs.create'), ['period' => '2026-06'])->assertSessionHasErrors('period');
        $this->assertStringContainsString('mid-month run first', session('errors')->first('period'));

        $this->post(route('payroll.runs.approve', $mm));
        $this->monthEnd();
    }

    public function test_only_one_mid_month_run_per_period(): void
    {
        $this->midMonth();

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'mid_month', 'mid_month_basis' => 'percentage'])
            ->assertSessionHasErrors('period');
        $this->assertSame(1, PayrollRun::where('kind', 'mid_month')->count());
    }

    public function test_basis_is_required_for_mid_month(): void
    {
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'mid_month'])
            ->assertSessionHasErrors('mid_month_basis');
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'mid_month', 'mid_month_basis' => 'cutoff', 'mid_month_value' => 29])
            ->assertSessionHasErrors('mid_month_value');
    }

    public function test_mid_month_run_cannot_be_deleted_while_month_end_exists(): void
    {
        $mm = $this->midMonth();
        $this->post(route('payroll.runs.approve', $mm));
        $this->monthEnd();

        $this->post(route('payroll.runs.delete', $mm))->assertStatus(422);
        $this->assertNotNull($mm->fresh());
    }

    public function test_mid_month_payslip_cannot_be_edited(): void
    {
        $slip = $this->slip($this->midMonth(), $this->emp1);

        $this->post(route('payroll.payslips.update', $slip), ['bonus' => 100])->assertStatus(422);
    }

    public function test_mid_month_pay_is_left_out_of_pcb_year_to_date_and_ea_totals(): void
    {
        $mm = $this->midMonth('cutoff', 15);
        $this->post(route('payroll.runs.finalize', $mm))->assertSessionHasNoErrors();
        $me = $this->monthEnd();
        $this->post(route('payroll.runs.finalize', $me))->assertSessionHasNoErrors();
        $this->assertSame('finalized', $mm->fresh()->status);
        $this->assertSame('finalized', $me->fresh()->status);
        $meGross = (float) $this->slip($me, $this->emp1)->gross;

        $ytd = app(PcbYearToDate::class)->forPeriod($this->emp1->fresh(), '2026-07');
        $this->assertEqualsWithDelta($meGross, $ytd['grossY'], 0.001);

        $ea = app(EaFormData::class)->forEmployee($this->tenant, $this->emp1->fresh(), 2026);
        $this->assertEqualsWithDelta($meGross, $ea['employment_income']['taxable_total'], 0.001);
    }

    /**
     * Queues, for emp1 in June: a Mid Month fixed allowance of 300, a Mid Month one-off
     * travel allowance of 100 and a Mid Month staff-loan deduction of 50, plus a Month End
     * one-off meal allowance of 80 the mid-month run must leave alone.
     */
    private function queueMidMonthTransactions(): void
    {
        PayrollItem::seedFor($this->tenant);
        $item = fn (string $code) => PayrollItem::where('tenant_id', $this->tenant->id)->where('code', $code)->firstOrFail()->id;

        $this->post(route('payroll.fixed-transactions.store'), ['employee_id' => $this->emp1->id, 'payroll_item_id' => $item('fixed-allowance'),
            'amount' => 300, 'start_period' => '2026-01', 'payroll_cycle' => 'mid_month'])->assertSessionHasNoErrors();
        foreach ([['travel-allowance', 100, 'mid_month'], ['staff-loan', 50, 'mid_month'], ['meal-allowance', 80, 'month_end']] as [$code, $amount, $cycle]) {
            $this->post(route('payroll.individual-transactions.store'), ['employee_id' => $this->emp1->id, 'payroll_item_id' => $item($code),
                'period' => '2026-06', 'amount' => $amount, 'payroll_cycle' => $cycle])->assertSessionHasNoErrors();
        }
    }

    public function test_mid_month_transactions_are_paid_in_the_mid_month_run_without_statutory(): void
    {
        $this->queueMidMonthTransactions();

        $slip = $this->slip($this->midMonth('cutoff', 15), $this->emp1);

        // 2500 advance + 300 fixed + 100 one-off, less the 50 loan. No meal allowance.
        $this->assertEqualsWithDelta(2900.0, (float) $slip->gross, 0.001);
        $this->assertEqualsWithDelta(2850.0, (float) $slip->net_pay, 0.001);
        foreach (['epf_employee', 'socso_employee', 'eis_employee', 'pcb', 'hrdf_levy'] as $column) {
            $this->assertSame(0.0, (float) $slip->{$column}, $column);
        }
        $this->assertEqualsCanonicalizing(['Basic Salary', 'Fixed Allowance', 'Travelling / Petrol / Toll Allowance', 'Staff Loan'], $slip->lines()->pluck('name')->all());
    }

    public function test_month_end_counts_mid_month_transactions_once_across_both_runs(): void
    {
        $this->queueMidMonthTransactions();
        $baseline = $this->slip($this->monthEnd(), $this->emp1);
        $this->post(route('payroll.runs.delete', PayrollRun::firstOrFail()))->assertRedirect();

        $mm = $this->midMonth('cutoff', 15);
        $this->post(route('payroll.runs.approve', $mm))->assertRedirect();
        $mmNet = (float) $this->slip($mm, $this->emp1)->net_pay;
        // Fixed pull unticked: the mid-month fixed allowance still comes in, because it was already paid.
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30', 'pull_fixed' => '0'])->assertSessionHasNoErrors();
        $slip = $this->slip(PayrollRun::where('kind', 'monthly')->firstOrFail(), $this->emp1);

        foreach (['gross', 'epf_employee', 'socso_employee', 'eis_employee', 'pcb'] as $column) {
            $this->assertEqualsWithDelta((float) $baseline->{$column}, (float) $slip->{$column}, 0.001, $column);
        }
        $this->assertEqualsWithDelta($mmNet, (float) $slip->mid_month_advance, 0.001);
        $this->assertEqualsWithDelta((float) $baseline->net_pay, $mmNet + (float) $slip->net_pay, 0.001);
    }

    public function test_mid_month_one_off_locks_once_the_mid_month_run_is_finalized(): void
    {
        $this->queueMidMonthTransactions();
        $this->post(route('payroll.runs.finalize', $this->midMonth()))->assertSessionHasNoErrors();

        $item = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'travel-allowance')->firstOrFail()->id;
        $this->post(route('payroll.individual-transactions.store'), ['employee_id' => $this->emp1->id, 'payroll_item_id' => $item,
            'period' => '2026-06', 'amount' => 10, 'payroll_cycle' => 'mid_month'])->assertStatus(422);
        $this->post(route('payroll.individual-transactions.store'), ['employee_id' => $this->emp1->id, 'payroll_item_id' => $item,
            'period' => '2026-06', 'amount' => 10, 'payroll_cycle' => 'month_end'])->assertSessionHasNoErrors();
    }

    public function test_a_last_amount_can_be_paid_in_a_different_cycle(): void
    {
        PayrollItem::seedFor($this->tenant);
        $allowance = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'fixed-allowance')->firstOrFail()->id;
        // Regular 300 at month end; the last month (June) pays 120 in the mid-month run instead.
        $this->post(route('payroll.fixed-transactions.store'), ['employee_id' => $this->emp1->id, 'payroll_item_id' => $allowance,
            'amount' => 300, 'start_period' => '2026-01', 'end_period' => '2026-06', 'last_amount' => 120,
            'payroll_cycle' => 'month_end', 'last_payroll_cycle' => 'mid_month'])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(2620.0, (float) $this->slip($this->midMonth('cutoff', 15), $this->emp1)->gross, 0.001);
    }

    public function test_individual_tab_lists_only_the_chosen_pay_cycle(): void
    {
        $this->queueMidMonthTransactions();
        $this->emp1->update(['staff_id' => 'UR1']);

        $this->get('/app/payroll-transaction?tab=individual&itx_period=2026-06&itx_cycle=mid_month')->assertOk()
            ->assertSee('Travelling / Petrol / Toll Allowance')->assertDontSee('Meal Allowance</td>', false);
        $this->get('/app/payroll-transaction?tab=individual&itx_period=2026-06&itx_cycle=month_end')->assertOk()
            ->assertSee('Meal Allowance</td>', false);
    }
}
