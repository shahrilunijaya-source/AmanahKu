<?php

declare(strict_types=1);

namespace Tests\Feature;

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

/**
 * Individual Transactions as their own screen: a one-off earning/deduction queued for a
 * person and a month, picked up by the payroll run when it is created — instead of only
 * being enterable while editing an already-existing draft payslip. See CLAUDE.md.
 *
 * The payslip-edit tx_item_id/tx_amount/tx_remark path (PayrollTransactionsPullTest,
 * PayrollItemTest) is unified with this one: both read and write the same
 * individual_transactions table, via PayrollController::individualTransactionLinesForPeriod
 * / syncIndividualTransactions.
 */
class IndividualTransactionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private Employee $emp1;

    private PayrollItem $travelAllowance;

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
        $this->travelAllowance = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'travel-allowance')->firstOrFail();
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

    private function queue(array $overrides = []): IndividualTransaction
    {
        return IndividualTransaction::forceCreate(array_merge([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->emp1->id,
            'payroll_item_id' => $this->travelAllowance->id,
            'period' => '2026-06',
            'amount' => 300,
        ], $overrides));
    }

    // ── Queued before the run exists ────────────────────────────

    public function test_a_transaction_queued_before_the_run_exists_appears_when_the_run_is_created(): void
    {
        $this->actingHr()->post('/app/payroll/individual-transactions', [
            'employee_id' => $this->emp1->id,
            'payroll_item_id' => $this->travelAllowance->id,
            'period' => '2026-06',
            'amount' => 300,
            'remarks' => 'Site visit',
        ])->assertRedirect();

        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertEqualsWithDelta(5300.0, (float) $slip->gross, 0.001);
        $line = $slip->lines->where('source', 'individual')->firstOrFail();
        $this->assertSame($this->travelAllowance->name, $line->name);
        $this->assertSame('Site visit', $line->remark);
    }

    public function test_two_one_offs_for_one_person_both_appear(): void
    {
        $this->queue(['payroll_item_id' => $this->travelAllowance->id, 'amount' => 100]);
        $this->queue(['payroll_item_id' => PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'meal-allowance')->firstOrFail()->id, 'amount' => 50]);

        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $lines = $slip->lines->where('source', 'individual');
        $this->assertCount(2, $lines);
        $this->assertEqualsWithDelta(150.0, (float) $lines->sum('amount'), 0.001);
    }

    // ── Wage-base flags ──────────────────────────────────────────

    public function test_the_items_flags_drive_the_epf_and_perkeso_bases(): void
    {
        // travel-allowance: EPF no, PERKESO no (see PayrollItem::SYSTEM_ITEMS).
        $this->queue(['amount' => 300]);

        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertEqualsWithDelta(5300.0, (float) $slip->gross, 0.001);
        $this->assertEqualsWithDelta(550.0, (float) $slip->epf_employee, 0.001); // 11% of basic 5000 only
    }

    public function test_a_deduction_reduces_net_but_not_gross(): void
    {
        $this->queue(['payroll_item_id' => $this->staffLoan->id, 'amount' => 200]);

        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertEqualsWithDelta(5000.0, (float) $slip->gross, 0.001);
        $this->assertTrue($slip->lines->contains(fn ($l) => $l->name === 'Staff Loan' && $l->type === 'deduction' && (float) $l->amount === 200.0));
    }

    // ── Unification with the payslip-edit path ────────────────────

    public function test_adding_one_while_editing_a_draft_payslip_creates_a_row_in_the_table(): void
    {
        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        // A genuine render of the edit form for an employee with no individual
        // transactions yet still posts tx_known_ids — empty, but present.
        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", [
            'tx_known_ids' => [],
            'tx_id' => [''],
            'tx_item_id' => [$this->travelAllowance->id],
            'tx_amount' => [80],
            'tx_remark' => ['Travel claim'],
        ])->assertRedirect();

        $this->assertDatabaseHas('individual_transactions', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->emp1->id,
            'payroll_item_id' => $this->travelAllowance->id,
            'period' => '2026-06',
            'amount' => 80,
            'remarks' => 'Travel claim',
        ]);
    }

    public function test_recomputing_a_draft_neither_duplicates_nor_drops_rows(): void
    {
        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", [
            'tx_known_ids' => [],
            'tx_id' => [''],
            'tx_item_id' => [$this->travelAllowance->id],
            'tx_amount' => [80],
            'tx_remark' => ['Travel claim'],
        ])->assertRedirect();
        $row = IndividualTransaction::where('employee_id', $this->emp1->id)->forPeriod('2026-06')->firstOrFail();

        // A real recompute: the form is re-rendered from the live table first, so it
        // knows about — and resubmits — the row it just created.
        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", [
            'tx_known_ids' => [$row->id],
            'tx_id' => [$row->id],
            'tx_item_id' => [$this->travelAllowance->id],
            'tx_amount' => [80],
            'tx_remark' => ['Travel claim'],
            'bonus' => 100,
        ])->assertRedirect();

        $this->assertSame(1, IndividualTransaction::where('employee_id', $this->emp1->id)->forPeriod('2026-06')->count());
        $this->assertSame($row->id, IndividualTransaction::where('employee_id', $this->emp1->id)->forPeriod('2026-06')->firstOrFail()->id);
        $this->assertSame(1, $slip->fresh()->lines->where('source', 'individual')->count());
    }

    public function test_a_row_created_after_the_form_snapshot_survives_a_payslip_save(): void
    {
        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        // The edit form was rendered when there were zero individual transactions...
        $formKnownIds = [];

        // ...then, before HR submits, a one-off is queued from another tab/screen.
        $addedElsewhere = $this->queue(['payroll_item_id' => $this->staffLoan->id, 'amount' => 150]);

        // HR's stale form now submits — it never knew about $addedElsewhere.
        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", [
            'tx_known_ids' => $formKnownIds,
            'tx_id' => [''],
            'tx_item_id' => [$this->travelAllowance->id],
            'tx_amount' => [80],
            'tx_remark' => ['Travel claim'],
        ])->assertRedirect();

        // Both rows survive: the stale form's own addition, and the one it never saw.
        $this->assertDatabaseHas('individual_transactions', ['id' => $addedElsewhere->id, 'amount' => 150]);
        $this->assertDatabaseHas('individual_transactions', ['employee_id' => $this->emp1->id, 'period' => '2026-06', 'amount' => 80]);
        $this->assertSame(2, IndividualTransaction::where('employee_id', $this->emp1->id)->forPeriod('2026-06')->count());
    }

    public function test_a_row_the_form_knew_about_and_dropped_is_deleted(): void
    {
        $itx = $this->queue(['amount' => 300]);
        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        // The form was rendered knowing about $itx, but HR cleared that row (submits it
        // blank) instead of resubmitting it.
        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", [
            'tx_known_ids' => [$itx->id],
            'tx_id' => [$itx->id],
            'tx_item_id' => [''],
            'tx_amount' => [''],
            'tx_remark' => [''],
        ])->assertRedirect();

        $this->assertDatabaseMissing('individual_transactions', ['id' => $itx->id]);
    }

    public function test_a_posted_id_belonging_to_another_employee_or_period_is_ignored(): void
    {
        $emp2 = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green']);
        $othersRow = $this->queue(['employee_id' => $emp2->id, 'amount' => 400]);
        $otherPeriodRow = $this->queue(['period' => '2026-05', 'amount' => 500]);

        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        // HR's form claims to know about both foreign ids and tries to update them —
        // both must be ignored (treated as if the id were blank), never touched.
        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", [
            'tx_known_ids' => [$othersRow->id, $otherPeriodRow->id],
            'tx_id' => [$othersRow->id, $otherPeriodRow->id],
            'tx_item_id' => [$this->travelAllowance->id, $this->travelAllowance->id],
            'tx_amount' => [999, 999],
            'tx_remark' => ['', ''],
        ])->assertRedirect();

        // Neither foreign row was mutated...
        $this->assertDatabaseHas('individual_transactions', ['id' => $othersRow->id, 'employee_id' => $emp2->id, 'amount' => 400]);
        $this->assertDatabaseHas('individual_transactions', ['id' => $otherPeriodRow->id, 'period' => '2026-05', 'amount' => 500]);
        // ...instead, each posted row (ignored id) became a new row of this employee's own.
        $this->assertSame(2, IndividualTransaction::where('employee_id', $this->emp1->id)->forPeriod('2026-06')->count());
    }

    public function test_a_request_with_no_tx_known_ids_leaves_the_table_untouched(): void
    {
        $itx = $this->queue(['amount' => 300]);
        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        // No tx_known_ids key at all — a partial/hand-made request, not a genuine render
        // of the edit form. The sync must be skipped outright, not treated as "the form
        // knew about nothing, delete everything".
        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", [
            'bonus' => 50,
        ])->assertRedirect();

        $this->assertDatabaseHas('individual_transactions', ['id' => $itx->id, 'amount' => 300]);
        $this->assertSame(1, IndividualTransaction::where('employee_id', $this->emp1->id)->forPeriod('2026-06')->count());
    }

    public function test_deleting_one_removes_its_line_from_the_draft_payslip_on_recompute(): void
    {
        $itx = $this->queue(['amount' => 300]);
        $slip = $this->createRun('2026-06')->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertTrue($slip->lines->where('source', 'individual')->isNotEmpty());

        $this->actingHr()->post("/app/payroll/individual-transactions/{$itx->id}/delete")->assertRedirect();
        $this->assertDatabaseMissing('individual_transactions', ['id' => $itx->id]);

        // Recompute the payslip (no tx rows posted — none exist any more) to pick up the deletion.
        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", [])->assertRedirect();

        $this->assertTrue($slip->fresh()->lines->where('source', 'individual')->isEmpty());
        $this->assertEqualsWithDelta(5000.0, (float) $slip->fresh()->gross, 0.001);
    }

    // ── Editability ────────────────────────────────────────────────

    public function test_a_finalized_period_refuses_every_mutation(): void
    {
        $itx = $this->queue(['amount' => 300]);
        $run = $this->createRun('2026-06');
        $this->actingHr()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();

        $this->actingHr()->post('/app/payroll/individual-transactions', [
            'employee_id' => $this->emp1->id,
            'payroll_item_id' => $this->travelAllowance->id,
            'period' => '2026-06',
            'amount' => 50,
        ])->assertStatus(422);

        $this->actingHr()->post("/app/payroll/individual-transactions/{$itx->id}", [
            'payroll_item_id' => $this->travelAllowance->id,
            'amount' => 999,
        ])->assertStatus(422);

        $this->actingHr()->post("/app/payroll/individual-transactions/{$itx->id}/delete")->assertStatus(422);

        $this->assertDatabaseHas('individual_transactions', ['id' => $itx->id, 'amount' => 300]);
    }

    public function test_an_automatic_source_item_is_refused(): void
    {
        $overtimeItem = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'overtime')->firstOrFail();

        $this->actingHr()->post('/app/payroll/individual-transactions', [
            'employee_id' => $this->emp1->id,
            'payroll_item_id' => $overtimeItem->id,
            'period' => '2026-06',
            'amount' => 50,
        ])->assertSessionHasErrors('payroll_item_id');
    }
}
