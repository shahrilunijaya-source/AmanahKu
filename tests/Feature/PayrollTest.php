<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\Employee;
use App\Models\PayrollOpeningFigure;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private User $empUser;

    private Employee $emp1;   // has a login + salary structure

    private Employee $emp2;   // no login, salary structure

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->empUser = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $this->empUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->emp1 = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->empUser->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green']);
        $this->emp2 = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green']);

        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'basic_salary' => 5000]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp2->id, 'basic_salary' => 3000]);
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function actingEmployee(): self
    {
        $this->actingAs($this->empUser)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function createRun(string $period = '2026-06'): PayrollRun
    {
        $this->actingHr()->post('/app/payroll/runs', ['period' => $period])->assertRedirect();

        return PayrollRun::where('period', $period)->firstOrFail();
    }

    // ── Run generation ────────────────────────────────────────────

    public function test_privileged_user_creates_a_run_with_payslips(): void
    {
        $run = $this->createRun();

        $this->assertSame('draft', $run->status);
        $this->assertSame(2, $run->payslips()->count());                 // both employees with a structure
        $this->assertEqualsWithDelta(5000.0, (float) $run->payslips()->where('employee_id', $this->emp1->id)->value('gross'), 0.001);
    }

    public function test_employee_cannot_create_a_run(): void
    {
        $this->actingEmployee()->post('/app/payroll/runs', ['period' => '2026-06'])->assertForbidden();
        $this->assertDatabaseMissing('payroll_runs', ['period' => '2026-06']);
    }

    public function test_employee_aged_60_or_over_pays_no_eis_and_no_employee_socso(): void
    {
        // emp2 turns 60+ well before the 2026-06 period → SOCSO Category 2.
        $this->emp2->update(['date_of_birth' => '1960-01-01']);
        $run = $this->createRun('2026-06');

        $slip = $run->payslips()->where('employee_id', $this->emp2->id)->firstOrFail();
        $this->assertSame(0.0, (float) $slip->socso_employee);
        $this->assertGreaterThan(0.0, (float) $slip->socso_employer);
        $this->assertSame(0.0, (float) $slip->eis_employee);
        $this->assertSame(0.0, (float) $slip->eis_employer);

        // emp1 (under 60 / no DOB) still contributes.
        $slip1 = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertGreaterThan(0.0, (float) $slip1->socso_employee);
    }

    public function test_foreign_nationality_gets_epf_part_f_flat_two_percent(): void
    {
        SalaryStructure::where('employee_id', $this->emp2->id)->update(['nationality' => 'foreign']);
        $run = $this->createRun();

        $slip = $run->payslips()->where('employee_id', $this->emp2->id)->firstOrFail();
        // emp2 basic 3000, Part F has no wage bands — flat 2% each side.
        $this->assertEqualsWithDelta(60.0, (float) $slip->epf_employee, 0.001);
        $this->assertEqualsWithDelta(60.0, (float) $slip->epf_employer, 0.001);
    }

    public function test_skbbk_opt_in_adds_a_deduction_line_and_lowers_net_pay(): void
    {
        $baseline = $this->createRun('2026-06');
        $baselineSlip = $baseline->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertSame(0.0, (float) $baselineSlip->skbbk_employee);

        SalaryStructure::where('employee_id', $this->emp1->id)->update(['skbbk_opt_in' => true]);
        $optedIn = $this->createRun('2026-07');
        $optedInSlip = $optedIn->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertGreaterThan(0.0, (float) $optedInSlip->skbbk_employee);
        $this->assertLessThan((float) $baselineSlip->net_pay, (float) $optedInSlip->net_pay);
    }

    public function test_missing_dob_is_flagged_on_the_run(): void
    {
        // Neither seeded employee has a DOB → both treated as Category 1, count surfaced.
        $this->actingHr()->post('/app/payroll/runs', ['period' => '2026-06'])
            ->assertSessionHas('ok', fn ($msg) => str_contains($msg, 'no date of birth'));
    }

    public function test_duplicate_period_is_rejected(): void
    {
        $this->createRun('2026-06');
        $this->actingHr()->post('/app/payroll/runs', ['period' => '2026-06'])->assertSessionHasErrors('period');
        $this->assertSame(1, PayrollRun::where('period', '2026-06')->count());
    }

    // ── Variable inputs ───────────────────────────────────────────

    public function test_update_payslip_recomputes_net_pay(): void
    {
        $run = $this->createRun();
        $payslip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        // basic 5000 → EPF 550 + SOCSO 24.75 + EIS 9.90 = 584.65 deductions (real
        // MY statutory wage brackets), net 4415.35.
        $this->assertEqualsWithDelta(4415.35, (float) $payslip->net_pay, 0.001);

        $this->actingHr()->post("/app/payroll/payslips/{$payslip->id}", ['pcb_override' => 200])->assertRedirect();

        $this->assertEqualsWithDelta(200.0, (float) $payslip->fresh()->pcb, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $payslip->fresh()->pcb_override, 0.001);
        $this->assertEqualsWithDelta(4215.35, (float) $payslip->fresh()->net_pay, 0.001);   // 4415.35 - 200 PCB
    }

    public function test_manual_pcb_override_survives_recomputation(): void
    {
        $run = $this->createRun();
        $payslip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->actingHr()->post("/app/payroll/payslips/{$payslip->id}", ['pcb_override' => 200])->assertRedirect();
        $this->assertEqualsWithDelta(200.0, (float) $payslip->fresh()->pcb, 0.001);

        // A later edit that changes something else, but the override field is
        // resubmitted (as the pre-filled form would) — override still wins.
        $this->actingHr()->post("/app/payroll/payslips/{$payslip->id}", ['overtime_hours' => 4, 'pcb_override' => 200])->assertRedirect();
        $fresh = $payslip->fresh();
        $this->assertEqualsWithDelta(200.0, (float) $fresh->pcb, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $fresh->pcb_override, 0.001);

        // Blanking the override field clears it — back to the computed PCB (0 here).
        $this->actingHr()->post("/app/payroll/payslips/{$payslip->id}", ['pcb_override' => ''])->assertRedirect();
        $cleared = $payslip->fresh();
        $this->assertNull($cleared->pcb_override);
        $this->assertEqualsWithDelta(0.0, (float) $cleared->pcb, 0.001);
    }

    public function test_overtime_and_bonus_increase_gross(): void
    {
        $run = $this->createRun();
        $payslip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        // OT: 8h at 5000/26/8 = 24.0385 → *1.5 = 288.46 ; bonus 500.
        $this->actingHr()->post("/app/payroll/payslips/{$payslip->id}", ['overtime_hours' => 8, 'bonus' => 500])->assertRedirect();

        $fresh = $payslip->fresh();
        $this->assertEqualsWithDelta(288.46, (float) $fresh->overtime_amount, 0.01);
        $this->assertEqualsWithDelta(5788.46, (float) $fresh->gross, 0.01);
    }

    public function test_employee_cannot_update_a_payslip(): void
    {
        $run = $this->createRun();
        $payslip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->actingEmployee()->post("/app/payroll/payslips/{$payslip->id}", ['pcb' => 999])->assertForbidden();
        $this->assertEqualsWithDelta(0.0, (float) $payslip->fresh()->pcb, 0.001);
    }

    public function test_cannot_edit_payslip_once_run_is_finalized(): void
    {
        $run = $this->createRun();
        $payslip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->actingHr()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();

        $this->actingHr()->post("/app/payroll/payslips/{$payslip->id}", ['pcb' => 200])->assertStatus(422);
    }

    // ── Claims reimbursement ──────────────────────────────────────

    public function test_run_pulls_approved_claims_and_marks_them_paid_on_finalize(): void
    {
        $claim = Claim::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id,
            'type' => 'expense', 'title' => 'Dock', 'amount' => 120, 'status' => 'approved', 'date' => now()->toDateString(),
        ]);

        $run = $this->createRun();
        $payslip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertEqualsWithDelta(120.0, (float) $payslip->claims_reimbursement, 0.001);
        $this->assertContains($claim->id, $payslip->claim_ids);
        $this->assertEqualsWithDelta(4535.35, (float) $payslip->net_pay, 0.001);   // 4415.35 + 120

        $this->actingHr()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();

        $this->assertSame('paid', $claim->fresh()->status);
        $this->assertNotNull($claim->fresh()->paid_at);
    }

    public function test_finalize_notifies_employees_with_a_login(): void
    {
        $run = $this->createRun();
        $this->actingHr()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->empUser->id, 'title' => 'Payslip ready', 'tenant_id' => $this->tenant->id,
        ]);
    }

    // ── State machine ─────────────────────────────────────────────

    public function test_run_lifecycle_draft_to_approved_to_finalized(): void
    {
        $run = $this->createRun();

        $this->actingHr()->post("/app/payroll/runs/{$run->id}/approve")->assertRedirect();
        $this->assertSame('approved', $run->fresh()->status);

        $this->actingHr()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();
        $fresh = $run->fresh();
        $this->assertSame('finalized', $fresh->status);
        $this->assertNotNull($fresh->finalized_at);
    }

    public function test_cannot_approve_a_non_draft_run(): void
    {
        $run = $this->createRun();
        $this->actingHr()->post("/app/payroll/runs/{$run->id}/approve")->assertRedirect();
        $this->actingHr()->post("/app/payroll/runs/{$run->id}/approve")->assertStatus(422);
    }

    // ── Salary structures + rate config ───────────────────────────

    public function test_privileged_user_sets_a_salary_structure(): void
    {
        $emp3 = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Newbie', 'status' => 'active', 'workload' => 'green']);

        $this->actingHr()->post('/app/payroll/salary', [
            'employee_id' => $emp3->id, 'basic_salary' => 4200,
        ])->assertRedirect();

        $structure = SalaryStructure::where('employee_id', $emp3->id)->firstOrFail();
        $this->assertEqualsWithDelta(4200.0, (float) $structure->basic_salary, 0.001);
    }

    public function test_salary_structure_requires_basic_salary(): void
    {
        $this->actingHr()->post('/app/payroll/salary', ['employee_id' => $this->emp1->id])->assertSessionHasErrors('basic_salary');
    }

    public function test_privileged_user_saves_the_statutory_profile(): void
    {
        $emp3 = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Newbie', 'status' => 'active', 'workload' => 'green']);

        $this->actingHr()->post('/app/payroll/salary', [
            'employee_id' => $emp3->id, 'basic_salary' => 4200,
            'nationality' => 'pr',
            'epf_opt_in_60plus' => '1',
            'epf_employee_rate_override' => 12.5,
            'tax_no' => 'SG1234567890',
            'spouse_working' => '1',
            'children_relief_count' => 3,
            'disabled_self' => '1',
            'disabled_spouse' => '1',
            'zakat_monthly' => 150.50,
            'cp38_monthly' => 75.25,
        ])->assertRedirect();

        $structure = SalaryStructure::where('employee_id', $emp3->id)->firstOrFail();
        $this->assertSame('pr', $structure->nationality);
        $this->assertTrue($structure->epf_opt_in_60plus);
        $this->assertEqualsWithDelta(12.5, (float) $structure->epf_employee_rate_override, 0.001);
        $this->assertSame('SG1234567890', $structure->tax_no);
        $this->assertTrue($structure->spouse_working);
        $this->assertSame(3, $structure->children_relief_count);
        $this->assertTrue($structure->disabled_self);
        $this->assertTrue($structure->disabled_spouse);
        $this->assertEqualsWithDelta(150.50, (float) $structure->zakat_monthly, 0.001);
        $this->assertEqualsWithDelta(75.25, (float) $structure->cp38_monthly, 0.001);
    }

    public function test_invalid_nationality_is_rejected(): void
    {
        $this->actingHr()->post('/app/payroll/salary', [
            'employee_id' => $this->emp1->id, 'basic_salary' => 4200, 'nationality' => 'martian',
        ])->assertSessionHasErrors('nationality');
    }

    public function test_employee_cannot_set_a_salary_structure(): void
    {
        $this->actingEmployee()->post('/app/payroll/salary', ['employee_id' => $this->emp2->id, 'basic_salary' => 9999])->assertForbidden();
    }

    public function test_privileged_user_saves_opening_figures(): void
    {
        $this->actingHr()->post('/app/payroll/opening', [
            'employee_id' => $this->emp1->id, 'year' => 2026,
            'gross' => 30000, 'epf' => 3300, 'pcb_paid' => 1200, 'zakat_paid' => 500,
            'additional_gross' => 2000, 'additional_epf' => 220,
            'socso' => 250, 'eis' => 40, 'optional_deductions' => 1000, 'exempt_allowances' => 600,
            'previous_employer' => 'Acme Prior Sdn Bhd', 'previous_employer_tin' => 'C1234567890',
        ])->assertRedirect();

        $this->assertDatabaseHas('payroll_opening_figures', [
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'year' => 2026,
        ]);
        $row = PayrollOpeningFigure::where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertEqualsWithDelta(30000.0, (float) $row->gross, 0.001);
        $this->assertEqualsWithDelta(1200.0, (float) $row->pcb_paid, 0.001);
        $this->assertEqualsWithDelta(3300.0, (float) $row->epf, 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $row->zakat_paid, 0.001);
        $this->assertEqualsWithDelta(2000.0, (float) $row->additional_gross, 0.001);
        $this->assertEqualsWithDelta(220.0, (float) $row->additional_epf, 0.001);
        $this->assertEqualsWithDelta(250.0, (float) $row->socso, 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $row->eis, 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $row->optional_deductions, 0.001);
        $this->assertEqualsWithDelta(600.0, (float) $row->exempt_allowances, 0.001);
        $this->assertSame('Acme Prior Sdn Bhd', $row->previous_employer);
        $this->assertSame('C1234567890', $row->previous_employer_tin);
    }

    public function test_employee_cannot_save_opening_figures(): void
    {
        $this->actingEmployee()->post('/app/payroll/opening', ['employee_id' => $this->emp1->id, 'year' => 2026])->assertForbidden();
    }

    // ── Tenant isolation ──────────────────────────────────────────

    public function test_cannot_finalize_a_run_from_another_tenant(): void
    {
        $other = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'initials' => 'RV']);
        $foreignRun = PayrollRun::forceCreate(['tenant_id' => $other->id, 'period' => '2026-06', 'status' => 'draft']);

        $response = $this->actingHr()->post("/app/payroll/runs/{$foreignRun->id}/finalize");

        // Denied either by the explicit tenant assert (403) or the tenant scope (404).
        $this->assertContains($response->status(), [403, 404]);
        $this->assertSame('draft', $foreignRun->fresh()->status);
    }

    // ── NRIC at rest + export auditing (I-018) ────────────────────

    public function test_nric_is_encrypted_at_rest(): void
    {
        // NRIC lives on the employee record now (see the reconcile migration
        // 2026_08_25_200300), not salary_structures.
        $this->emp1->update(['nric' => '900101-01-1234']);

        $raw = DB::table('employees')->where('id', $this->emp1->id)->value('nric');
        $this->assertNotSame('900101-01-1234', $raw);                       // ciphertext, not plaintext, at rest
        $this->assertSame('900101-01-1234', Crypt::decryptString($raw));    // round-trips
        $this->assertSame('900101-01-1234', $this->emp1->fresh()->nric);   // model decrypts transparently
    }

    public function test_statutory_export_logs_who_pulled_the_nric(): void
    {
        $run = $this->createRun();
        $this->actingHr()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();

        $this->actingHr()->get("/app/payroll/runs/{$run->id}/statutory-report")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id, 'action' => 'Exported statutory report',
        ]);
    }

    // ── Bank file formats (I-017) ─────────────────────────────────

    private function finalizedRun(): PayrollRun
    {
        $run = $this->createRun();
        $this->actingHr()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();

        return $run;
    }

    public function test_bank_file_defaults_to_generic_csv(): void
    {
        $run = $this->finalizedRun();

        $response = $this->actingHr()->get("/app/payroll/runs/{$run->id}/bank-file");
        $response->assertOk();
        $this->assertStringContainsString('generic-2026-06.csv', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('TOTAL', $response->streamedContent());
    }

    public function test_bank_file_format_is_selectable(): void
    {
        $run = $this->finalizedRun();
        $this->emp1->update(['nric' => '900101-01-1234']);

        $response = $this->actingHr()->get("/app/payroll/runs/{$run->id}/bank-file?format=maybank2u");
        $response->assertOk();
        $this->assertStringContainsString('maybank2u-2026-06.csv', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('Beneficiary NRIC', $response->streamedContent());   // bank-specific header
    }

    public function test_unknown_format_falls_back_to_generic(): void
    {
        $run = $this->finalizedRun();

        $response = $this->actingHr()->get("/app/payroll/runs/{$run->id}/bank-file?format=bogus");
        $response->assertOk();
        $this->assertStringContainsString('generic-2026-06.csv', $response->headers->get('content-disposition'));
    }

    public function test_unverified_format_is_noted_in_the_audit_trail(): void
    {
        $run = $this->finalizedRun();

        $this->actingHr()->get("/app/payroll/runs/{$run->id}/bank-file?format=duitnow")->assertOk();

        $log = AuditLog::where('action', 'Exported bank file')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('unverified layout', $log->target);
    }

    // ── PCB / MTD — year-to-date accumulation (always on) ──────────

    public function test_pcb_uses_the_employees_real_epf_part_not_an_assumed_under_60(): void
    {
        // 60+ citizen → EPF Part E, 0% employee rate. PcbYearToDate's EPF-on-bonus split
        // must use that same Part, not silently assume Part A (under 60) for K1/Kt.
        $this->emp1->update(['date_of_birth' => '1960-01-01']);
        $run = $this->createRun('2026-01');
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertSame(0.0, (float) $slip->epf_employee);
        // With K1 correctly 0 (no EPF relief to claim), January PCB is 134.20 — higher
        // than the under-60 110 baseline, which would wrongly assume RM550 of EPF relief.
        $this->assertSame(134.2, (float) $slip->pcb);
    }

    public function test_pcb_is_computed_automatically_on_a_new_run(): void
    {
        $run = $this->createRun('2026-01');

        // emp1: single, basic 5000, January (n=11), no YTD — spec's own worked example.
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertSame(110.0, (float) $slip->pcb);
        // EPF 550 + SOCSO 24.75 + EIS 9.90 + PCB 110 = 694.65 → net 4305.35
        $this->assertEqualsWithDelta(4305.35, (float) $slip->net_pay, 0.001);
    }

    /**
     * The spec puts "married with a working spouse", "divorced" and "widowed" in the same
     * tax category (3), so a divorced employee must not be forced to be recorded as widowed
     * to be taxed correctly.
     */
    public function test_divorced_is_taxed_as_category_three_like_a_working_spouse(): void
    {
        // Marital status lives on the employee record now (see the reconcile migration
        // 2026_08_25_200300) — spouse_working stays payroll-specific, on SalaryStructure.
        $this->emp1->update(['marital_status' => 'divorced']);
        SalaryStructure::where('employee_id', $this->emp1->id)->update(['children_relief_count' => 2]);
        $this->emp2->update(['marital_status' => 'married']);
        SalaryStructure::where('employee_id', $this->emp2->id)
            ->update(['spouse_working' => true, 'children_relief_count' => 2,
                'basic_salary' => SalaryStructure::where('employee_id', $this->emp1->id)->value('basic_salary')]);

        $run = $this->createRun('2026-01');
        $divorced = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $marriedSpouseWorking = $run->payslips()->where('employee_id', $this->emp2->id)->firstOrFail();

        $this->assertGreaterThan(0.0, (float) $divorced->pcb, 'Both would match trivially at zero.');
        $this->assertSame((float) $marriedSpouseWorking->pcb, (float) $divorced->pcb);

        // And category 3 is genuinely different from category 1 at the same pay.
        $this->emp2->update(['marital_status' => 'single']);
        SalaryStructure::where('employee_id', $this->emp2->id)->update(['spouse_working' => false, 'children_relief_count' => 0]);
        $single = $this->createRun('2026-02')->payslips()->where('employee_id', $this->emp2->id)->firstOrFail();
        $this->assertNotEqualsWithDelta((float) $divorced->pcb, (float) $single->pcb, 0.001);
    }

    public function test_pcb_moves_month_to_month_as_ytd_accumulates_and_drafts_never_leak(): void
    {
        $run1 = $this->createRun('2026-01');
        $this->actingHr()->post("/app/payroll/runs/{$run1->id}/finalize")->assertRedirect();
        $jan = $run1->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertSame(110.0, (float) $jan->pcb);

        // A pay rise mid-year — YTD gross/EPF from the finalized January run feeds Feb's PCB.
        SalaryStructure::where('employee_id', $this->emp1->id)->update(['basic_salary' => 8000]);
        $run2 = $this->createRun('2026-02');
        $this->actingHr()->post("/app/payroll/runs/{$run2->id}/finalize")->assertRedirect();
        $feb = $run2->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertNotEqualsWithDelta(110.0, (float) $feb->pcb, 0.001);   // moved, driven by YTD

        // March is created as a draft with a large bonus, but NEVER finalized.
        $run3 = $this->createRun('2026-03');
        $march = $run3->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->actingHr()->post("/app/payroll/payslips/{$march->id}", ['bonus' => 50000])->assertRedirect();
        $this->assertSame('draft', $run3->fresh()->status);   // deliberately left unfinalized

        // April's PCB must be computed from Jan+Feb only — March's draft (and its huge
        // bonus) must never enter the year-to-date accumulation.
        $run4 = $this->createRun('2026-04');
        $april = $run4->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        // If March's draft bonus had leaked in, April's PCB would be well over 1,000.
        $this->assertLessThan(600.0, (float) $april->pcb);
    }

    public function test_opening_figures_change_the_computed_pcb_for_a_mid_year_joiner(): void
    {
        $emp3 = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'MidYear', 'status' => 'active', 'workload' => 'green']);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp3->id, 'basic_salary' => 5000]);

        $withoutOpening = $this->createRun('2026-06');
        $slipWithout = $withoutOpening->payslips()->where('employee_id', $emp3->id)->firstOrFail();

        // A previous employer already paid this person RM40,000 gross / RM4,400 EPF /
        // RM500 PCB earlier in 2026 before they joined — "Payroll Figures Take On".
        $this->actingHr()->post('/app/payroll/opening', [
            'employee_id' => $emp3->id, 'year' => 2026,
            'gross' => 40000, 'epf' => 4400, 'pcb_paid' => 500,
        ])->assertRedirect();

        $withOpening = $this->createRun('2026-07');
        $slipWith = $withOpening->payslips()->where('employee_id', $emp3->id)->firstOrFail();

        $this->assertNotEqualsWithDelta((float) $slipWithout->pcb, (float) $slipWith->pcb, 0.001);
    }

    public function test_opening_socso_and_eis_do_not_change_the_computed_pcb(): void
    {
        $emp3 = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'MidYear', 'status' => 'active', 'workload' => 'green']);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp3->id, 'basic_salary' => 5000]);

        $withoutOpening = $this->createRun('2026-06');
        $slipWithout = $withoutOpening->payslips()->where('employee_id', $emp3->id)->firstOrFail();

        // SOCSO/EIS are record-keeping (EA form / reconciliation) — Form TP3 doesn't
        // even carry them — so setting them alone must leave PCB untouched.
        $this->actingHr()->post('/app/payroll/opening', [
            'employee_id' => $emp3->id, 'year' => 2026,
            'socso' => 9999, 'eis' => 9999,
        ])->assertRedirect();

        $withOpening = $this->createRun('2026-07');
        $slipWith = $withOpening->payslips()->where('employee_id', $emp3->id)->firstOrFail();

        $this->assertEqualsWithDelta((float) $slipWithout->pcb, (float) $slipWith->pcb, 0.001);
    }

    public function test_opening_optional_deductions_lower_the_computed_pcb(): void
    {
        $emp3 = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'MidYear', 'status' => 'active', 'workload' => 'green']);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp3->id, 'basic_salary' => 9000]);

        $withoutOpening = $this->createRun('2026-06');
        $slipWithout = $withoutOpening->payslips()->where('employee_id', $emp3->id)->firstOrFail();

        // optional_deductions is Form TP3 section D's ∑LP — it DOES feed the formula.
        $this->actingHr()->post('/app/payroll/opening', [
            'employee_id' => $emp3->id, 'year' => 2026,
            'optional_deductions' => 10000,
        ])->assertRedirect();

        $withOpening = $this->createRun('2026-07');
        $slipWith = $withOpening->payslips()->where('employee_id', $emp3->id)->firstOrFail();

        $this->assertLessThan((float) $slipWithout->pcb, (float) $slipWith->pcb);
    }

    public function test_bonus_produces_both_a_normal_and_an_additional_pcb_figure(): void
    {
        $run = $this->createRun('2026-01');
        $payslip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->actingHr()->post("/app/payroll/payslips/{$payslip->id}", ['bonus' => 5000])->assertRedirect();

        $fresh = $payslip->fresh();
        $this->assertSame(110.0, (float) $fresh->pcb);              // normal PCB unchanged by the bonus
        $this->assertGreaterThan(0.0, (float) $fresh->pcb_additional);   // bonus taxed through its own line
    }

    public function test_zakat_nets_off_pcb_and_cp38_is_a_separate_deduction(): void
    {
        SalaryStructure::where('employee_id', $this->emp1->id)->update(['zakat_monthly' => 50, 'cp38_monthly' => 30]);
        $run = $this->createRun('2026-01');
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $this->assertEqualsWithDelta(60.0, (float) $slip->pcb, 0.001);   // 110 normal MTD − 50 zakat
        $this->assertEqualsWithDelta(50.0, (float) $slip->zakat, 0.001);
        $this->assertEqualsWithDelta(30.0, (float) $slip->cp38, 0.001);
        // EPF 550 + SOCSO 24.75 + EIS 9.90 + PCB 60 + zakat 50 + CP38 30 = 724.65 → net 4275.35
        $this->assertEqualsWithDelta(4275.35, (float) $slip->net_pay, 0.001);
    }
}
