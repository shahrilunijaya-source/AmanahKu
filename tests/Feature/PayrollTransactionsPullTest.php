<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The third payroll-transactions pass: Individual Transactions, and pulling approved
 * overtime / unpaid leave onto a draft payslip instead of HR typing them by hand.
 * See CLAUDE.md's "payroll module polish" pass.
 */
class PayrollTransactionsPullTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private Employee $emp1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->emp1 = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green']);
        // basic 5200 / 26 / 8 = 25.00/hr exactly, so overtime figures land on round numbers.
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'basic_salary' => 5200]);
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function createRun(string $period = '2026-06'): PayrollRun
    {
        $this->actingHr()->post('/app/payroll/runs', ['period' => $period])->assertRedirect();

        return PayrollRun::where('period', $period)->firstOrFail();
    }

    private function overtimeRequest(array $attrs = []): OvertimeRequest
    {
        return OvertimeRequest::forceCreate(array_merge([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->emp1->id,
            'ot_date' => '2026-06-15',
            'hours' => 4,
            'rate_multiplier' => 1.50,
            'reason' => 'Backlog',
            'status' => 'approved',
        ], $attrs));
    }

    private function unpaidLeaveType(): LeaveType
    {
        return LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cuti Tanpa Gaji', 'entitlement' => 0, 'is_unpaid' => true,
        ]);
    }

    // ── Overtime pull ────────────────────────────────────────────

    public function test_approved_overtime_in_period_lands_on_the_payslip_as_raw_hours_not_multiplied_twice(): void
    {
        // 4h at 3x (public holiday). overtime_hours is always RAW hours (4.0, never the
        // old "equivalent hours" flattening) — the multiplier is carried separately. If
        // the calculator's own 1.5x ordinary-rate multiplier were applied on top of the
        // request's own 3x, the payout would be 450.00 instead of the correct 300.00 —
        // this is the exact overpay the task warns about.
        $ot = $this->overtimeRequest(['hours' => 4, 'rate_multiplier' => 3.00]);

        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertEqualsWithDelta(4.0, (float) $slip->overtime_hours, 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $slip->overtime_multiplier, 0.001);
        $this->assertEqualsWithDelta(300.00, (float) $slip->overtime_amount, 0.001);
        $this->assertNotEqualsWithDelta(450.00, (float) $slip->overtime_amount, 0.001);
        $this->assertContains($ot->id, $slip->overtime_request_ids);
        $this->assertFalse($slip->overtime_overridden);

        $line = $slip->lines->where('source', 'overtime')->firstOrFail();
        $this->assertSame('Overtime 3×', $line->name);
        $this->assertEqualsWithDelta(4.0, (float) $line->quantity, 0.001);
        $this->assertEqualsWithDelta(300.00, (float) $line->amount, 0.001);
    }

    public function test_a_pull_mixing_two_rates_emits_one_payslip_line_per_multiplier_group(): void
    {
        // 6h ordinary (1.5x) + 4h public holiday (3x) — a single hours+multiplier pair on
        // the payslip cannot represent this; PayrollController must emit one PayslipLine
        // per rate. hourly = 5200/26/8 = 25.00.
        $this->overtimeRequest(['hours' => 6, 'rate_multiplier' => 1.50, 'ot_date' => '2026-06-10']);
        $this->overtimeRequest(['hours' => 4, 'rate_multiplier' => 3.00, 'ot_date' => '2026-06-15']);

        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        // 6*25*1.5 = 225.00 ; 4*25*3.0 = 300.00 ; total 525.00 — not double-multiplied.
        $this->assertEqualsWithDelta(10.0, (float) $slip->overtime_hours, 0.001);
        $this->assertEqualsWithDelta(525.00, (float) $slip->overtime_amount, 0.001);
        // Mixed rates: no single multiplier describes the payslip.
        $this->assertNull($slip->overtime_multiplier);

        $lines = $slip->lines->where('source', 'overtime')->sortBy('sort_order')->values();
        $this->assertCount(2, $lines);
        $this->assertSame('Overtime 1.5×', $lines[0]->name);
        $this->assertEqualsWithDelta(6.0, (float) $lines[0]->quantity, 0.001);
        $this->assertEqualsWithDelta(225.00, (float) $lines[0]->amount, 0.001);
        $this->assertSame('Overtime 3×', $lines[1]->name);
        $this->assertEqualsWithDelta(4.0, (float) $lines[1]->quantity, 0.001);
        $this->assertEqualsWithDelta(300.00, (float) $lines[1]->amount, 0.001);
        $this->assertEqualsWithDelta(525.00, (float) $lines->sum('amount'), 0.001);
    }

    public function test_overtime_outside_the_run_period_is_not_pulled(): void
    {
        $this->overtimeRequest(['ot_date' => '2026-07-01', 'hours' => 4, 'rate_multiplier' => 1.50]);

        $run = $this->createRun('2026-06');
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertEqualsWithDelta(0.0, (float) $slip->overtime_amount, 0.001);
        $this->assertSame([], $slip->overtime_request_ids ?? []);
    }

    public function test_overtime_already_paid_in_an_earlier_run_is_never_pulled_again(): void
    {
        $ot = $this->overtimeRequest(['hours' => 4, 'rate_multiplier' => 1.50, 'paid_at' => now()]);

        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertEqualsWithDelta(0.0, (float) $slip->overtime_amount, 0.001);
        $this->assertNotContains($ot->id, $slip->overtime_request_ids ?? []);
    }

    public function test_unpaid_leave_spanning_two_periods_is_reserved_by_whichever_run_pulls_it_first(): void
    {
        // Not period-scoped down to the day (same as claims) — a request spanning June and
        // July is pulled whole into whichever run is created first, then the pool-exclusion
        // (paid_at / *_request_ids) keeps the OTHER period's run from also grabbing it. HR
        // needs to split the days by hand across the two runs via the override — see the
        // report.
        $type = $this->unpaidLeaveType();
        LeaveRequest::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'leave_type_id' => $type->id,
            'date_from' => '2026-06-28', 'date_to' => '2026-07-02', 'days' => 5, 'status' => 'approved',
        ]);

        $juneRun = $this->createRun('2026-06');
        $juneSlip = $juneRun->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertEqualsWithDelta(5.0, (float) $juneSlip->pulled_unpaid_days, 0.001);

        $julyRun = $this->createRun('2026-07');
        $julySlip = $julyRun->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $julySlip->pulled_unpaid_days, 0.001);
    }

    public function test_finalize_marks_pulled_overtime_as_paid_so_it_cannot_be_pulled_into_a_later_run(): void
    {
        $ot = $this->overtimeRequest(['hours' => 4, 'rate_multiplier' => 1.50]);
        $run = $this->createRun('2026-06');

        $this->actingHr()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();
        $this->assertNotNull($ot->fresh()->paid_at);

        $julyRun = $this->createRun('2026-07');
        $julySlip = $julyRun->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $julySlip->overtime_amount, 0.001);
    }

    public function test_hr_override_of_pulled_overtime_survives_recomputation(): void
    {
        $this->overtimeRequest(['hours' => 4, 'rate_multiplier' => 1.50]); // pulled: 4.0 raw hours @ 1.5x

        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertEqualsWithDelta(4.0, (float) $slip->pulled_overtime_hours, 0.001);

        // HR overrides with raw hours at the standard rate (multiplier omitted → defaults
        // to 1.5x): 10h * 25.00 * 1.5 = 375.00.
        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", ['overtime_hours' => 10])->assertRedirect();
        $fresh = $slip->fresh();
        $this->assertTrue($fresh->overtime_overridden);
        $this->assertEqualsWithDelta(375.00, (float) $fresh->overtime_amount, 0.001);
        $this->assertEqualsWithDelta(1.5, (float) $fresh->overtime_multiplier, 0.001);

        // A later edit that changes something else, with the override field resubmitted
        // (as the pre-filled form would) — override still wins, not the pulled figure.
        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", ['overtime_hours' => 10, 'bonus' => 50])->assertRedirect();
        $fresh2 = $slip->fresh();
        $this->assertTrue($fresh2->overtime_overridden);
        $this->assertEqualsWithDelta(375.00, (float) $fresh2->overtime_amount, 0.001);

        // Blanking the override clears it — back to the pulled figure: 4.0h * 25.00 * 1.5 = 150.00.
        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", ['overtime_hours' => ''])->assertRedirect();
        $cleared = $slip->fresh();
        $this->assertFalse($cleared->overtime_overridden);
        $this->assertEqualsWithDelta(150.00, (float) $cleared->overtime_amount, 0.001);
    }

    public function test_hr_override_with_an_explicit_multiplier_is_never_double_multiplied(): void
    {
        // Override at 3x public-holiday rate: 12h * 25.00 * 3.0 = 900.00, never 1350.00
        // (3x applied on top of the calculator's own default 1.5x) and never 450.00
        // (the 1.5x default silently overriding HR's chosen 3x).
        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", [
            'overtime_hours' => 12, 'overtime_multiplier' => 3.0,
        ])->assertRedirect();

        $fresh = $slip->fresh();
        $this->assertEqualsWithDelta(900.00, (float) $fresh->overtime_amount, 0.001);
        $this->assertEqualsWithDelta(12.0, (float) $fresh->overtime_hours, 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $fresh->overtime_multiplier, 0.001);
        $line = $fresh->lines->where('source', 'overtime')->firstOrFail();
        $this->assertSame('Overtime 3×', $line->name);
        $this->assertEqualsWithDelta(900.00, (float) $line->amount, 0.001);
    }

    // ── Unpaid leave pull ────────────────────────────────────────

    public function test_unpaid_leave_pulls_by_the_is_unpaid_flag_even_when_the_type_is_renamed(): void
    {
        $type = $this->unpaidLeaveType(); // named in Malay, not "Unpaid" — only is_unpaid matches it
        LeaveRequest::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'leave_type_id' => $type->id,
            'date_from' => '2026-06-10', 'date_to' => '2026-06-12', 'days' => 3, 'status' => 'approved',
        ]);

        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertEqualsWithDelta(3.0, (float) $slip->unpaid_days, 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $slip->pulled_unpaid_days, 0.001);
        $this->assertGreaterThan(0.0, (float) $slip->unpaid_deduction);
    }

    public function test_a_leave_request_of_a_non_unpaid_type_is_never_pulled(): void
    {
        $paidType = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 16, 'is_unpaid' => false]);
        LeaveRequest::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'leave_type_id' => $paidType->id,
            'date_from' => '2026-06-10', 'date_to' => '2026-06-12', 'days' => 3, 'status' => 'approved',
        ]);

        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertEqualsWithDelta(0.0, (float) $slip->unpaid_days, 0.001);
    }

    // ── Individual Transactions ────────────────────────────────────

    public function test_individual_transaction_item_flags_drive_the_epf_and_perkeso_bases(): void
    {
        PayrollItem::seedFor($this->tenant);
        // travel-allowance: EPF no, PERKESO no, taxable yes (see PayrollItem::SYSTEM_ITEMS).
        $travel = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'travel-allowance')->firstOrFail();

        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $baseline = (float) $slip->epf_employee; // basic 5200 → 11% = 572.00

        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", [
            'tx_item_id' => [$travel->id],
            'tx_amount' => [300],
            'tx_remark' => ['Site visit'],
        ])->assertRedirect();

        $fresh = $slip->fresh();
        // Gross rises by the transaction amount...
        $this->assertEqualsWithDelta(5500.0, (float) $fresh->gross, 0.001);
        // ...but EPF is unaffected — travel-allowance is not EPF-liable.
        $this->assertEqualsWithDelta($baseline, (float) $fresh->epf_employee, 0.001);
        $line = $fresh->lines->where('source', 'individual')->firstOrFail();
        $this->assertSame($travel->name, $line->name);
        $this->assertSame('Site visit', $line->remark);
    }

    public function test_individual_transaction_deduction_reduces_net_pay_without_touching_wage_bases(): void
    {
        PayrollItem::seedFor($this->tenant);
        $loan = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'staff-loan')->firstOrFail();

        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $baselineEpf = (float) $slip->epf_employee;
        $baselineNet = (float) $slip->net_pay;

        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", [
            'tx_item_id' => [$loan->id],
            'tx_amount' => [200],
        ])->assertRedirect();

        $fresh = $slip->fresh();
        $this->assertEqualsWithDelta($baselineEpf, (float) $fresh->epf_employee, 0.001);
        $this->assertEqualsWithDelta($baselineNet - 200, (float) $fresh->net_pay, 0.001);
    }

    public function test_individual_transaction_cannot_target_a_forbidden_item(): void
    {
        PayrollItem::seedFor($this->tenant);
        $overtimeItem = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'overtime')->firstOrFail();

        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", [
            'tx_item_id' => [$overtimeItem->id],
            'tx_amount' => [50],
        ])->assertSessionHasErrors('tx_item_id.0');
    }

    // ── Legacy payslips ──────────────────────────────────────────

    public function test_legacy_payslip_still_renders_its_old_free_form_lines(): void
    {
        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        // Simulate a payslip issued before this pass: free-form JSON, no PayslipLine rows
        // for those amounts, and no pull metadata.
        $slip->lines()->delete();
        $slip->forceFill([
            'additions' => [['name' => 'Legacy travel claim', 'amount' => 75]],
            'other_deductions' => [['name' => 'Legacy advance', 'amount' => 40]],
            'overtime_request_ids' => null,
            'unpaid_leave_request_ids' => null,
        ])->save();

        $this->actingHr()->get(route('app.screen', ['screen' => 'payroll', 'payslip' => $slip->id]))->assertOk()
            ->assertSee('Legacy travel claim')
            ->assertSee('Legacy advance');
    }
}
