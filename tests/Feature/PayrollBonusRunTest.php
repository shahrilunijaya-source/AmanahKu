<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\IndividualTransaction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollSubmission;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\EpfCalculator;
use App\Services\Payroll\PcbCalculator;
use App\Services\Payroll\PcbInputs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Spec F10: a bonus run pays the Individual Transactions flagged for it, separately from
 * the one monthly run a period may have.
 */
class PayrollBonusRunTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        PayrollItem::seedFor($this->tenant);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    /** @param  array<string, mixed>  $structure */
    private function employee(string $name, float $salary = 4000, array $structure = []): Employee
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'staff_id' => 'AC-'.$name, 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => $salary, 'marital_status' => 'single']);
        SalaryStructure::forceCreate(array_merge(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => $salary, 'nationality' => 'citizen',
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1'], $structure));

        return $emp;
    }

    private function queueBonus(Employee $employee, float $amount, string $period = '2026-06'): IndividualTransaction
    {
        $tx = (new IndividualTransaction)->forceFill([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'payroll_item_id' => PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'bonus')->value('id'),
            'period' => $period,
            'for_bonus_run' => true,
            'amount' => $amount,
        ]);
        $tx->save();

        return $tx;
    }

    public function test_only_employees_with_a_bonus_queued_get_a_bonus_payslip(): void
    {
        $withBonus = $this->employee('Aida');
        $without = $this->employee('Bakar');
        $this->queueBonus($withBonus, 5000);

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'bonus', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();

        $run = PayrollRun::where('kind', 'bonus')->firstOrFail();
        $this->assertSame(1, $run->payslips()->count());
        $slip = $run->payslips()->firstOrFail();
        $this->assertSame($withBonus->id, $slip->employee_id);
        $this->assertSame(0, Payslip::where('employee_id', $without->id)->count());

        // Bonus only: no basic salary, EPF charged (KWSP wages include bonus), SOCSO/EIS
        // and the HRD Corp levy zero (PERKESO excludes the annual bonus).
        $this->assertSame(0.0, (float) $slip->basic);
        $this->assertSame(5000.0, (float) $slip->gross);
        $this->assertGreaterThan(0.0, (float) $slip->epf_employee);
        $this->assertSame(0.0, (float) $slip->socso_employee);
        $this->assertSame(0.0, (float) $slip->eis_employee);
        $this->assertSame(0.0, (float) $slip->hrdf_levy);
    }

    public function test_the_bonus_is_taxed_as_additional_remuneration(): void
    {
        // RM8,000 a month: enough that tax is actually due, so the figure being compared
        // is not zero on both sides.
        $employee = $this->employee('Aida', 8000);
        $this->queueBonus($employee, 5000);

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'bonus', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();

        $slip = Payslip::firstOrFail();
        $epf = app(EpfCalculator::class);
        // No monthly run exists yet, so the normal side is projected from salary alone.
        $expected = (new PcbCalculator)->calculate(new PcbInputs(
            category: 1,
            currentGrossY1: 8000.0,
            currentEpfK1: $epf->contribution(8000.0, 'A')['employee'],
            monthsRemainingAfterCurrent: 6,
            currentAdditionalGrossYt: 5000.0,
            currentAdditionalEpfKt: $epf->contribution(5000.0, 'A')['employee'],
        ));

        $this->assertGreaterThan(0.0, $expected->additionalMtd);
        $this->assertSame($expected->additionalMtd, (float) $slip->pcb_additional);
        // The normal MTD belongs to the monthly payslip, never to the bonus one.
        $this->assertSame(0.0, (float) $slip->pcb);
    }

    public function test_a_monthly_run_in_the_same_period_ignores_the_flagged_transaction(): void
    {
        $employee = $this->employee('Aida');
        $this->queueBonus($employee, 5000);

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();

        $monthly = PayrollRun::where('kind', 'monthly')->firstOrFail();
        $slip = $monthly->payslips()->firstOrFail();
        $this->assertSame(4000.0, (float) $slip->gross);
        $this->assertSame(0.0, (float) $slip->pcb_additional);
    }

    public function test_a_bonus_after_the_monthly_run_sits_on_the_monthly_payslips_figures(): void
    {
        $employee = $this->employee('Aida', 8000);
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();
        $this->queueBonus($employee, 5000);
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'bonus', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();

        $monthlySlip = Payslip::whereHas('payrollRun', fn ($q) => $q->where('kind', 'monthly'))->firstOrFail();
        $bonusSlip = Payslip::whereHas('payrollRun', fn ($q) => $q->where('kind', 'bonus'))->firstOrFail();
        $epf = app(EpfCalculator::class);

        $expected = (new PcbCalculator)->calculate(new PcbInputs(
            category: 1,
            currentGrossY1: (float) $monthlySlip->gross,
            currentEpfK1: $epf->contribution((float) $monthlySlip->gross, 'A')['employee'],
            monthsRemainingAfterCurrent: 6,
            currentAdditionalGrossYt: 5000.0,
            currentAdditionalEpfKt: $epf->contribution(5000.0, 'A')['employee'],
        ));

        $this->assertGreaterThan(0.0, $expected->additionalMtd);
        $this->assertSame($expected->additionalMtd, (float) $bonusSlip->pcb_additional);
    }

    public function test_one_monthly_run_per_period_but_more_than_one_bonus_run(): void
    {
        $employee = $this->employee('Aida');
        $this->queueBonus($employee, 5000);
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])->assertSessionHasNoErrors();
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])->assertSessionHasErrors('period');

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'bonus', 'payment_date' => '2026-06-30'])->assertSessionHasNoErrors();
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'bonus', 'payment_date' => '2026-06-30'])->assertSessionHasNoErrors();

        $this->assertSame(1, PayrollRun::where('kind', 'monthly')->count());
        $this->assertSame(2, PayrollRun::where('kind', 'bonus')->count());
    }

    public function test_a_bonus_payslip_is_not_edited_through_the_payslip_form(): void
    {
        $employee = $this->employee('Aida');
        $this->queueBonus($employee, 5000);
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'bonus', 'payment_date' => '2026-06-30'])->assertSessionHasNoErrors();

        $this->post(route('payroll.payslips.update', Payslip::firstOrFail()), ['bonus' => 9000])->assertStatus(422);
        $this->assertSame(5000.0, (float) Payslip::firstOrFail()->gross);
    }

    public function test_a_bonus_run_needs_a_bonus_queued(): void
    {
        $this->employee('Aida');

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'bonus', 'payment_date' => '2026-06-30'])
            ->assertSessionHasErrors('period');
        $this->assertSame(0, PayrollRun::count());
    }

    public function test_someone_elses_missing_bank_details_do_not_block_a_bonus_run(): void
    {
        $withBonus = $this->employee('Aida');
        $this->employee('Bakar', 3000, ['bank_account_no' => null]);
        $this->queueBonus($withBonus, 5000);

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'bonus', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, PayrollRun::where('kind', 'bonus')->count());

        // ... but that same gap still blocks the monthly run, which does pay them.
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])
            ->assertSessionHasErrors('readiness');
    }

    public function test_a_bonus_can_still_be_queued_after_the_monthly_run_is_finalized(): void
    {
        $employee = $this->employee('Aida');
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])->assertSessionHasNoErrors();
        $monthly = PayrollRun::where('kind', 'monthly')->firstOrFail();
        $this->post(route('payroll.runs.approve', $monthly));
        $this->post(route('payroll.runs.finalize', $monthly), ['payment_date' => '2026-06-30'])->assertSessionHasNoErrors();

        $bonusItem = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'bonus')->firstOrFail();
        $this->post(route('payroll.individual-transactions.store'), [
            'employee_id' => $employee->id, 'payroll_item_id' => $bonusItem->id,
            'period' => '2026-06', 'for_bonus_run' => '1', 'amount' => 5000,
        ])->assertSessionHasNoErrors();
        $this->assertTrue(IndividualTransaction::firstOrFail()->for_bonus_run);

        // A plain one-off for the same month is still refused — that payslip is issued.
        $this->post(route('payroll.individual-transactions.store'), [
            'employee_id' => $employee->id, 'payroll_item_id' => $bonusItem->id,
            'period' => '2026-06', 'amount' => 100,
        ])->assertStatus(422);
    }

    public function test_a_finalized_bonus_run_reuses_the_monthly_runs_statutory_filings(): void
    {
        $employee = $this->employee('Aida');
        $this->queueBonus($employee, 5000);
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])->assertSessionHasNoErrors();
        $monthly = PayrollRun::where('kind', 'monthly')->firstOrFail();
        $this->post(route('payroll.runs.approve', $monthly));
        $this->post(route('payroll.runs.finalize', $monthly), ['payment_date' => '2026-06-30'])->assertSessionHasNoErrors();
        $opened = PayrollSubmission::count();
        $this->assertGreaterThan(0, $opened);

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'bonus', 'payment_date' => '2026-06-30'])->assertSessionHasNoErrors();
        $bonus = PayrollRun::where('kind', 'bonus')->firstOrFail();
        $this->post(route('payroll.runs.approve', $bonus));
        $this->post(route('payroll.runs.finalize', $bonus), ['payment_date' => '2026-06-30'])->assertSessionHasNoErrors();

        $this->assertSame($opened, PayrollSubmission::count());
    }

    public function test_an_earlier_bonus_is_carried_into_the_year_to_date(): void
    {
        $employee = $this->employee('Aida');
        $this->queueBonus($employee, 5000, '2026-05');
        $this->post(route('payroll.runs.create'), ['period' => '2026-05', 'kind' => 'bonus', 'payment_date' => '2026-05-30'])->assertSessionHasNoErrors();
        $may = PayrollRun::where('kind', 'bonus')->firstOrFail();
        $this->post(route('payroll.runs.approve', $may));
        $this->post(route('payroll.runs.finalize', $may), ['payment_date' => '2026-05-30'])->assertSessionHasNoErrors();
        $mayPcb = (float) $may->payslips()->firstOrFail()->pcb_additional;

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])->assertSessionHasNoErrors();
        $juneSlip = Payslip::whereHas('payrollRun', fn ($q) => $q->where('period', '2026-06'))->firstOrFail();

        $epf = app(EpfCalculator::class);
        $expected = (new PcbCalculator)->calculate(new PcbInputs(
            category: 1,
            ytdGrossY: 5000.0,
            ytdEpfK: $epf->contribution(5000.0, 'A')['employee'],
            currentGrossY1: 4000.0,
            currentEpfK1: $epf->contribution(4000.0, 'A')['employee'],
            monthsRemainingAfterCurrent: 6,
            ytdMtdPaidX: $mayPcb,
        ));

        $this->assertSame($expected->netNormalMtd, (float) $juneSlip->pcb);
    }
}
