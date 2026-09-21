<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private User $empUser;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->empUser = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $this->empUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->empUser->id,
            'name' => 'Worker', 'staff_id' => 'AC-0007', 'status' => 'active', 'workload' => 'green',
            // NRIC lives on the employee record (see the reconcile migration 2026_08_25_200300).
            'nric' => '880101-14-5500',
        ]);
        SalaryStructure::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'basic_salary' => 5000,
            'bank_name' => 'Maybank', 'bank_account_no' => '514999001122',
            'epf_no' => 'EPF12345678', 'socso_no' => 'SOC99001122',
        ]);
        Employee::whereKey($this->emp->id)->update(['salary' => 5000]);
    }

    private function finalizedRun(string $status = 'finalized'): PayrollRun
    {
        $run = PayrollRun::forceCreate([
            'tenant_id' => $this->tenant->id, 'period' => '2026-06', 'label' => 'June 2026', 'status' => $status,
            'finalized_at' => $status === 'finalized' ? now() : null,
        ]);
        Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->emp->id,
            'basic' => 5000, 'gross' => 5000,
            'epf_employee' => 550, 'epf_employer' => 650,
            'socso_employee' => 25, 'socso_employer' => 87.5,
            'eis_employee' => 10, 'eis_employer' => 10, 'pcb' => 120,
            'total_deductions' => 705, 'net_pay' => 4295, 'employer_cost' => 5747.5,
        ]);

        return $run;
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

    // ── Bank file ─────────────────────────────────────────────────

    public function test_privileged_user_downloads_bank_file(): void
    {
        $run = $this->finalizedRun();

        $response = $this->actingHr()->get("/app/payroll/runs/{$run->id}/bank-file");
        $response->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Worker', $csv);
        $this->assertStringContainsString('Maybank', $csv);
        $this->assertStringContainsString('514999001122', $csv);
        $this->assertStringContainsString('4295.00', $csv);   // net pay
        $this->assertStringContainsString('TOTAL', $csv);
    }

    public function test_employee_cannot_download_bank_file(): void
    {
        $run = $this->finalizedRun();
        $this->actingEmployee()->get("/app/payroll/runs/{$run->id}/bank-file")->assertForbidden();
    }

    // ── Statutory report ──────────────────────────────────────────

    public function test_privileged_user_downloads_statutory_report(): void
    {
        $run = $this->finalizedRun();

        $response = $this->actingHr()->get("/app/payroll/runs/{$run->id}/statutory-report");
        $response->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('EPF12345678', $csv);
        $this->assertStringContainsString('880101-14-5500', $csv);
        $this->assertStringContainsString('550.00', $csv);   // EPF employee
        $this->assertStringContainsString('650.00', $csv);   // EPF employer
        $this->assertStringContainsString('120.00', $csv);   // PCB
    }

    public function test_employee_cannot_download_statutory_report(): void
    {
        $run = $this->finalizedRun();
        $this->actingEmployee()->get("/app/payroll/runs/{$run->id}/statutory-report")->assertForbidden();
    }

    // ── Finalized-only + tenant isolation ─────────────────────────

    public function test_cannot_export_a_draft_run(): void
    {
        $draft = $this->finalizedRun('draft');
        $this->actingHr()->get("/app/payroll/runs/{$draft->id}/bank-file")->assertStatus(422);
        $this->actingHr()->get("/app/payroll/runs/{$draft->id}/statutory-report")->assertStatus(422);
    }

    public function test_cannot_export_a_run_from_another_tenant(): void
    {
        $other = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'initials' => 'RV']);
        $foreign = PayrollRun::forceCreate(['tenant_id' => $other->id, 'period' => '2026-06', 'status' => 'finalized', 'finalized_at' => now()]);

        $response = $this->actingHr()->get("/app/payroll/runs/{$foreign->id}/bank-file");
        $this->assertContains($response->status(), [403, 404]);
    }
    // ── Agency upload files (spec F6/F7) ──────────────────────────

    public function test_cp39_downloads_for_a_finalized_run_and_is_audited(): void
    {
        $this->tenant->update(['employer_tin' => '9123456708']);
        $run = $this->finalizedRun();
        $res = $this->actingHr()->get(route('payroll.export.statutory-file', [$run, 'cp39']));
        $res->assertOk();
        $this->assertStringContainsString('912345670806_2026.txt', (string) $res->headers->get('content-disposition'));
        $this->assertStringStartsWith('H9123456708', $res->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'Exported statutory file']);
    }

    public function test_statutory_file_refuses_a_draft_run_an_unknown_key_an_employee_and_another_tenant(): void
    {
        $draft = $this->finalizedRun('draft');
        $this->actingHr()->get(route('payroll.export.statutory-file', [$draft, 'cp39']))->assertStatus(422);

        $draft->forceFill(['status' => 'finalized', 'finalized_at' => now()])->save();
        $this->actingHr()->get(route('payroll.export.statutory-file', [$draft, 'nope']))->assertNotFound();
        $this->actingEmployee()->get(route('payroll.export.statutory-file', [$draft, 'cp39']))->assertForbidden();

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreign = PayrollRun::forceCreate(['tenant_id' => $other->id, 'period' => '2026-06', 'label' => 'June 2026', 'status' => 'finalized', 'finalized_at' => now()]);
        // Same as the bank-file case above: the tenant scope hides another tenant's run
        // from route-model binding, so this is a 404 rather than a 403.
        $this->assertContains($this->actingHr()->get(route('payroll.export.statutory-file', [$foreign, 'cp39']))->status(), [403, 404]);
    }

    public function test_hrd_corp_file_is_refused_when_the_levy_is_off(): void
    {
        $run = $this->finalizedRun();
        $this->actingHr()->get(route('payroll.export.statutory-file', [$run, 'hrdcorp']))->assertStatus(422);
    }

    // ── Accounting journal (spec F16) ─────────────────────────────

    public function test_journal_csv_balances_and_uses_tenant_account_codes(): void
    {
        $run = $this->finalizedRun();
        $run->forceFill(['payment_date' => '2026-06-30'])->save();
        $this->tenant->update(['journal_accounts' => ['salaries' => '900-100', 'net_pay' => '400-100']]);

        $res = $this->actingHr()->get(route('payroll.export.journal', $run))->assertOk();
        $lines = array_map('str_getcsv', array_filter(explode("\n", $res->streamedContent())));

        $this->assertSame(['Date', 'Account Code', 'Account Name', 'Description', 'Debit', 'Credit'], $lines[0]);
        $this->assertContains(['2026-06-30', '900-100', 'Salaries and wages', 'Payroll June 2026', '5000.00', ''], $lines);
        $this->assertContains(['2026-06-30', '400-100', 'Net pay payable', 'Payroll June 2026', '', '4295.00'], $lines);
        $this->assertContains(['2026-06-30', '', 'EPF payable', 'Payroll June 2026', '', '1200.00'], $lines);
        $this->assertEqualsWithDelta(array_sum(array_map('floatval', array_column(array_slice($lines, 1), 4))), array_sum(array_map('floatval', array_column(array_slice($lines, 1), 5))), 0.001);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Exported accounting journal']);
    }

    public function test_journal_is_refused_when_it_does_not_balance(): void
    {
        $run = $this->finalizedRun();
        $run->payslips()->update(['net_pay' => 4000]);

        $this->actingHr()->get(route('payroll.export.journal', $run))->assertStatus(422);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'Exported accounting journal']);
    }

    public function test_journal_is_closed_to_employees_other_tenants_and_draft_runs(): void
    {
        $this->actingEmployee()->get(route('payroll.export.journal', $this->finalizedRun()))->assertForbidden();

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreign = PayrollRun::forceCreate(['tenant_id' => $other->id, 'period' => '2026-06', 'label' => 'June 2026', 'status' => 'finalized', 'finalized_at' => now()]);
        $this->actingHr()->get(route('payroll.export.journal', $foreign))->assertNotFound();

        $draft = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => '2026-07', 'label' => 'July 2026', 'status' => 'draft']);
        $this->actingHr()->get(route('payroll.export.journal', $draft))->assertStatus(422);
    }

    public function test_hr_saves_journal_account_codes_and_unknown_lines_are_rejected(): void
    {
        $this->actingHr()->post(route('admin.settings.update'), ['name' => 'Acme', 'journal_accounts' => ['salaries' => '900-100', 'allowances' => '']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['salaries' => '900-100'], $this->tenant->fresh()->journal_accounts);

        $this->actingHr()->post(route('admin.settings.update'), ['name' => 'Acme', 'journal_accounts' => ['bogus' => '1']])
            ->assertSessionHasErrors('journal_accounts');
    }
}
