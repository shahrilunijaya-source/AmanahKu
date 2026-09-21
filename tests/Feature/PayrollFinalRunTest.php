<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\IndividualTransaction;
use App\Models\PayrollItem;
use App\Models\PayrollNotice;
use App\Models\PayrollRun;
use App\Models\PayrollSubmission;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Spec F10: a leaver's final pay run — prorated to the last working day, held back while
 * the CP22A is unsettled, and merged with the month's other runs into one agency file.
 */
class PayrollFinalRunTest extends TestCase
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

    /** @param  array<string, mixed>  $attributes */
    private function employee(string $name, float $salary = 3000, array $attributes = []): Employee
    {
        $emp = Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'staff_id' => 'AC-'.$name, 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => $salary, 'marital_status' => 'single',
        ], $attributes));
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => $salary, 'nationality' => 'citizen',
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG'.$emp->id]);

        return $emp;
    }

    private function leaver(string $name = 'Aida', float $salary = 3000): Employee
    {
        return $this->employee($name, $salary, ['last_working_day' => '2026-06-15']);
    }

    private function queueOneOff(Employee $employee, float $amount, string $code = 'other-addition', string $period = '2026-06'): void
    {
        (new IndividualTransaction)->forceFill([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'payroll_item_id' => PayrollItem::where('tenant_id', $this->tenant->id)->where('code', $code)->value('id'),
            'period' => $period,
            'amount' => $amount,
        ])->save();
    }

    private function createFinalRun(Employee $employee): PayrollRun
    {
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'final', 'employee_id' => $employee->id])
            ->assertSessionHasNoErrors();

        return PayrollRun::where('kind', 'final')->latest('id')->firstOrFail();
    }

    private function finalize(PayrollRun $run, string $payDate = '2026-06-15'): void
    {
        $this->post(route('payroll.runs.finalize', $run), ['payment_date' => $payDate])->assertSessionHasNoErrors();
    }

    public function test_final_pay_is_prorated_to_the_last_working_day_and_carries_the_period_one_offs(): void
    {
        $leaver = $this->leaver();
        $this->queueOneOff($leaver, 400);   // encashed annual leave

        $run = $this->createFinalRun($leaver);

        $this->assertSame(1, $run->payslips()->count());
        $slip = $run->payslips()->firstOrFail();
        $this->assertSame($leaver->id, $slip->employee_id);
        // 30-day June, 15 days served: RM3,000 / 30 × 15 (EA s.18A).
        $this->assertSame(1500.0, (float) $slip->basic);
        $this->assertSame(15, $slip->days_employed);
        $this->assertSame(1900.0, (float) $slip->gross);
        // EA s.20: the pay date defaults to the last working day.
        $this->assertSame('2026-06-15', $run->payment_date?->toDateString());
    }

    public function test_the_monthly_run_skips_an_employee_already_paid_out(): void
    {
        $leaver = $this->leaver();
        $stayer = $this->employee('Bakar');
        $leaverRun = $this->createFinalRun($leaver);
        $this->finalize($leaverRun);

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();

        $monthly = PayrollRun::where('kind', 'monthly')->firstOrFail();
        $this->assertSame([$stayer->id], $monthly->payslips()->pluck('employee_id')->all());
    }

    public function test_a_final_run_is_refused_when_the_monthly_run_already_pays_the_leaver(): void
    {
        $leaver = $this->leaver();
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'final', 'employee_id' => $leaver->id])
            ->assertSessionHasErrors('employee_id');

        $this->assertSame(1, Payslip::where('employee_id', $leaver->id)->count());
        $this->assertSame(0, PayrollRun::where('kind', 'final')->count());
    }

    public function test_the_monthly_run_skips_a_leaver_whose_final_run_is_still_a_draft(): void
    {
        $leaver = $this->leaver();
        $stayer = $this->employee('Bakar');
        $this->createFinalRun($leaver);   // deliberately not finalized

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();

        $monthly = PayrollRun::where('kind', 'monthly')->firstOrFail();
        $this->assertSame([$stayer->id], $monthly->payslips()->pluck('employee_id')->all());
    }

    public function test_a_socso_exempt_employee_has_no_socso_or_eis_on_the_payslip_or_after_a_recompute(): void
    {
        $emp = $this->employee('Exempt');
        $emp->salaryStructure->forceFill(['socso_exempt' => true, 'socso_no' => null])->save();

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();
        $slip = Payslip::where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame(0.0, (float) $slip->socso_employee);
        $this->assertSame(0.0, (float) $slip->eis_employee);
        $this->assertGreaterThan(0, (float) $slip->epf_employee);

        // Recompute through the payslip form keeps it at zero.
        $this->post(route('payroll.payslips.update', $slip), ['bonus' => 0])->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) $slip->fresh()->socso_employer);
        $this->assertSame(0.0, (float) $slip->fresh()->eis_employer);
    }

    public function test_a_resigned_leaver_with_missing_details_cannot_get_a_final_run(): void
    {
        $leaver = $this->employee('Aida', 3000, ['last_working_day' => '2026-06-15', 'status' => 'resigned', 'nric' => null]);

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'final', 'employee_id' => $leaver->id])
            ->assertSessionHasErrors('readiness');

        $this->assertSame(0, PayrollRun::count());
    }

    public function test_an_unfiled_cp22a_holds_the_final_pay_out_of_the_bank_file_until_released(): void
    {
        // Recording the last working day opens the CP22A by itself (spec F11), and it is
        // unfiled — which is exactly what holds the money.
        $leaver = $this->leaver();
        $this->assertSame(1, PayrollNotice::where('type', 'cp22a')->count());

        $run = $this->createFinalRun($leaver);
        $this->finalize($run);

        $slip = $run->payslips()->firstOrFail();
        $this->assertTrue((bool) $slip->held_for_cp22a);

        $held = $this->get(route('payroll.export.bank', $run))->streamedContent();
        $this->assertStringNotContainsString('Aida', $held);

        $this->post(route('payroll.payslips.release-hold', $slip), ['reason' => 'LHDN cleared by phone'])
            ->assertSessionHasNoErrors();

        $slip->refresh();
        $this->assertFalse((bool) $slip->held_for_cp22a);
        $this->assertNotNull($slip->hold_released_at);
        $this->assertSame(1, AuditLog::where('action', 'Released final pay hold')->count());

        $released = $this->get(route('payroll.export.bank', $run))->streamedContent();
        $this->assertStringContainsString('Aida', $released);
    }

    public function test_a_release_needs_a_reason(): void
    {
        $leaver = $this->leaver();
        $run = $this->createFinalRun($leaver);
        $this->finalize($run);

        $this->post(route('payroll.payslips.release-hold', $run->payslips()->firstOrFail()), ['reason' => ''])
            ->assertSessionHasErrors('reason');
    }

    public function test_a_cleared_cp22a_does_not_hold_the_final_pay(): void
    {
        $leaver = $this->leaver();
        PayrollNotice::where('employee_id', $leaver->id)->where('type', 'cp22a')
            ->firstOrFail()->forceFill(['filed_on' => '2026-05-20', 'cleared_on' => '2026-06-01'])->save();

        $run = $this->createFinalRun($leaver);
        $this->finalize($run);

        $this->assertFalse((bool) $run->payslips()->firstOrFail()->held_for_cp22a);
        $this->assertStringContainsString('Aida', $this->get(route('payroll.export.bank', $run))->streamedContent());
    }

    public function test_a_final_run_needs_a_leaver_and_only_one_per_employee(): void
    {
        $stayer = $this->employee('Bakar');
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'final', 'employee_id' => $stayer->id])
            ->assertSessionHasErrors('employee_id');

        $leaver = $this->leaver();
        $this->finalize($this->createFinalRun($leaver));

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'final', 'employee_id' => $leaver->id])
            ->assertSessionHasErrors('employee_id');
    }

    public function test_the_cp39_file_merges_every_finalized_run_of_the_month(): void
    {
        // A high enough salary that PCB is actually due on both the monthly and the
        // bonus payslip, so the merged totals are not all zero.
        $stayer = $this->employee('Bakar', 9000);
        $leaver = $this->leaver('Aida', 9000);
        (new IndividualTransaction)->forceFill([
            'tenant_id' => $this->tenant->id, 'employee_id' => $stayer->id, 'period' => '2026-06', 'for_bonus_run' => true, 'amount' => 6000,
            'payroll_item_id' => PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'bonus')->value('id'),
        ])->save();

        $leaverRun = $this->createFinalRun($leaver);
        $this->finalize($leaverRun);

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])->assertSessionHasNoErrors();
        $monthly = PayrollRun::where('kind', 'monthly')->firstOrFail();
        $this->finalize($monthly, '2026-06-30');

        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'kind' => 'bonus', 'payment_date' => '2026-06-30'])->assertSessionHasNoErrors();
        $bonus = PayrollRun::where('kind', 'bonus')->firstOrFail();
        $this->finalize($bonus, '2026-06-30');

        $body = $this->get(route('payroll.export.statutory-file', ['run' => $monthly->id, 'key' => 'cp39']))->streamedContent();
        $lines = explode("\r\n", $body);

        // The stayer was paid twice in June (monthly + bonus) and still gets ONE line.
        $this->assertSame(2, Payslip::where('employee_id', $stayer->id)->count());
        $taxedPeople = Payslip::all()->groupBy('employee_id')
            ->filter(fn ($slips) => $slips->sum('pcb') + $slips->sum('pcb_additional') + $slips->sum('cp38') > 0)->count();
        $this->assertCount($taxedPeople + 1, $lines);
        $this->assertSame($taxedPeople, (int) substr($lines[0], 37, 5));

        // Header total balances against every finalized run of the month.
        $expected = (int) round(Payslip::sum('pcb') * 100 + Payslip::sum('pcb_additional') * 100);
        $this->assertSame($expected, (int) substr($lines[0], 27, 10));

        // The bonus tax rides on the same detail line as that employee's monthly PCB.
        $bonusSlip = $bonus->payslips()->firstOrFail();
        $this->assertGreaterThan(0.0, (float) $bonusSlip->pcb_additional);
        $stayerLine = collect($lines)->first(fn (string $l) => str_starts_with($l, 'D') && str_contains($l, 'BAKAR'));
        $this->assertNotNull($stayerLine);
        $monthlySlip = $monthly->payslips()->where('employee_id', $stayer->id)->firstOrFail();
        $this->assertSame(
            (int) round(((float) $monthlySlip->pcb + (float) $monthlySlip->pcb_additional + (float) $bonusSlip->pcb + (float) $bonusSlip->pcb_additional) * 100),
            (int) substr($stayerLine, 110, 8),
        );

        // One filing per agency per month: the PCB row was opened by the final run (the
        // first one finalized) and the download above ran off the monthly run, so the
        // stamp has to follow the period rather than the run it was started from.
        $pcbFiling = PayrollSubmission::where('agency', 'pcb')->firstOrFail();
        $this->assertSame($leaverRun->id, $pcbFiling->payroll_run_id);
        $this->assertNotNull($pcbFiling->downloaded_at);
    }
}
