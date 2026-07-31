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
        ]);
        SalaryStructure::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'basic_salary' => 5000,
            'bank_name' => 'Maybank', 'bank_account_no' => '514999001122',
            'epf_no' => 'EPF12345678', 'socso_no' => 'SOC99001122', 'nric' => '880101-14-5500',
        ]);
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
}
