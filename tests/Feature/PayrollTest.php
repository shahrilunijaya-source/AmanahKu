<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Claim;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\StatutoryRate;
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

        $this->actingHr()->post("/app/payroll/payslips/{$payslip->id}", ['pcb' => 200])->assertRedirect();

        $this->assertEqualsWithDelta(200.0, (float) $payslip->fresh()->pcb, 0.001);
        $this->assertEqualsWithDelta(4215.35, (float) $payslip->fresh()->net_pay, 0.001);   // 4415.35 - 200 PCB
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
            'alw_name' => ['Transport', ''], 'alw_amount' => [200, 0],
        ])->assertRedirect();

        $structure = SalaryStructure::where('employee_id', $emp3->id)->firstOrFail();
        $this->assertEqualsWithDelta(4200.0, (float) $structure->basic_salary, 0.001);
        $this->assertCount(1, $structure->allowances);                                  // blank row dropped
        $this->assertSame('Transport', $structure->allowances[0]['name']);
        $this->assertEqualsWithDelta(200.0, (float) $structure->allowances[0]['amount'], 0.001);
    }

    public function test_salary_structure_requires_basic_salary(): void
    {
        $this->actingHr()->post('/app/payroll/salary', ['employee_id' => $this->emp1->id])->assertSessionHasErrors('basic_salary');
    }

    public function test_employee_cannot_set_a_salary_structure(): void
    {
        $this->actingEmployee()->post('/app/payroll/salary', ['employee_id' => $this->emp2->id, 'basic_salary' => 9999])->assertForbidden();
    }

    public function test_privileged_user_updates_statutory_rates(): void
    {
        $this->actingHr()->post('/app/payroll/rates', [
            'epf_employee_pct' => 9, 'epf_employer_pct_below' => 13, 'epf_employer_pct_above' => 12, 'epf_threshold' => 5000,
            'socso_employer_pct' => 1.75, 'socso_employee_pct' => 0.5, 'socso_ceiling' => 6000,
            'eis_employer_pct' => 0.2, 'eis_employee_pct' => 0.2, 'eis_ceiling' => 6000,
        ])->assertRedirect();

        $this->assertDatabaseHas('statutory_rates', ['tenant_id' => $this->tenant->id, 'type' => 'epf']);
        $this->assertEqualsWithDelta(9.0, (float) StatutoryRate::where('type', 'epf')->first()->config['employee_pct'], 0.001);
    }

    public function test_employee_cannot_update_statutory_rates(): void
    {
        $this->actingEmployee()->post('/app/payroll/rates', [
            'epf_employee_pct' => 0, 'epf_employer_pct_below' => 0, 'epf_employer_pct_above' => 0, 'epf_threshold' => 0,
            'socso_employer_pct' => 0, 'socso_employee_pct' => 0, 'socso_ceiling' => 0,
            'eis_employer_pct' => 0, 'eis_employee_pct' => 0, 'eis_ceiling' => 0,
        ])->assertForbidden();
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
        $s = SalaryStructure::where('employee_id', $this->emp1->id)->firstOrFail();
        $s->update(['nric' => '900101-01-1234']);

        $raw = DB::table('salary_structures')->where('id', $s->id)->value('nric');
        $this->assertNotSame('900101-01-1234', $raw);                       // ciphertext, not plaintext, at rest
        $this->assertSame('900101-01-1234', Crypt::decryptString($raw));    // round-trips
        $this->assertSame('900101-01-1234', $s->fresh()->nric);            // model decrypts transparently
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

    private function finalizedRun(): \App\Models\PayrollRun
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
        SalaryStructure::where('employee_id', $this->emp1->id)->first()->update(['nric' => '900101-01-1234']);

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

        $log = \App\Models\AuditLog::where('action', 'Exported bank file')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('unverified layout', $log->target);
    }

    // ── Auto-PCB / MTD (I-016) ────────────────────────────────────

    private function enableAutoPcb(): void
    {
        $this->actingHr()->post('/app/payroll/rates', [
            'epf_employee_pct' => 11, 'epf_employer_pct_below' => 13, 'epf_employer_pct_above' => 12, 'epf_threshold' => 5000,
            'socso_employer_pct' => 1.75, 'socso_employee_pct' => 0.5, 'socso_ceiling' => 6000,
            'eis_employer_pct' => 0.2, 'eis_employee_pct' => 0.2, 'eis_ceiling' => 6000,
            'pcb_auto' => 1, 'pcb_individual_relief' => 9000, 'pcb_epf_relief_cap' => 4000,
        ])->assertRedirect();
    }

    public function test_pcb_is_zero_by_default_when_auto_is_off(): void
    {
        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertSame(0.0, (float) $slip->pcb);
    }

    public function test_auto_pcb_estimates_the_deduction_on_a_new_run(): void
    {
        $this->enableAutoPcb();
        $run = $this->createRun();

        // emp1 gross 5,000 → annual 60,000 − 13,000 relief = 47,000 chargeable → 1,320/yr → 110/mo.
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertSame(110.0, (float) $slip->pcb);
        // EPF 550 + SOCSO 24.75 + EIS 9.90 + PCB 110 = 694.65 → net 4305.35
        $this->assertEqualsWithDelta(4305.35, (float) $slip->net_pay, 0.001);
    }

    public function test_rate_config_persists_the_auto_pcb_toggle(): void
    {
        $this->enableAutoPcb();
        $this->assertTrue((bool) StatutoryRate::where('type', 'pcb')->first()->config['auto']);
    }
}
