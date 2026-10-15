<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\PayrollRun;
use App\Models\PayrollSubmission;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Unlock puts a finalized run back to draft so HR can correct a payslip without
 * deleting the whole run (the way Worksy's Payroll Control unlocks a pay cycle).
 */
class PayrollRunUnlockTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private Employee $emp1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);

        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->emp1 = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green',
            'nric' => '900101-14-5501', 'date_of_birth' => '1990-01-01', 'joined_at' => '2020-01-01']);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'basic_salary' => 5200,
            'epf_no' => '1', 'socso_no' => '1', 'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'tax_no' => 'SG1']);
        Employee::whereKey($this->emp1->id)->update(['salary' => 5200]);
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function createRun(string $period = '2026-06'): PayrollRun
    {
        // Spec F5: finalize needs a pay date within seven days of the period end (EA s.19),
        // so every run this helper creates carries the last day of its own period.
        $payDate = Carbon::createFromFormat('Y-m-d', $period.'-01')->endOfMonth()->toDateString();
        $this->actingHr()->post('/app/payroll/runs', ['period' => $period, 'payment_date' => $payDate])->assertRedirect();

        return PayrollRun::where('period', $period)->firstOrFail();
    }

    private function finalizedRun(string $period = '2026-06'): PayrollRun
    {
        $run = $this->createRun($period);
        $this->actingHr()->post("/app/payroll/runs/{$run->id}/finalize", ['publish_now' => 1])->assertRedirect();

        return $run->fresh();
    }

    private function unlock(PayrollRun $run, ?string $reason = 'Fix EPF for Worker'): TestResponse
    {
        return $this->actingHr()->post("/app/payroll/runs/{$run->id}/unlock", ['reason' => $reason]);
    }

    public function test_hr_unlocks_a_finalized_run_back_to_an_unpublished_draft(): void
    {
        $run = $this->finalizedRun();
        $this->assertNotNull($run->published_at);

        $this->unlock($run)->assertRedirect();

        $run->refresh();
        $this->assertSame('draft', $run->status);
        $this->assertNull($run->finalized_at);
        $this->assertNull($run->published_at);
        $this->assertSame(1, $run->payslips()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'UNLOCKED FINALIZED PAYROLL RUN', 'user_id' => $this->hr->id]);
        $this->assertStringContainsString('Fix EPF for Worker', AuditLog::where('action', 'UNLOCKED FINALIZED PAYROLL RUN')->value('target'));
    }

    public function test_unlock_needs_a_reason(): void
    {
        $run = $this->finalizedRun();

        $this->unlock($run, '')->assertSessionHasErrors('reason');
        $this->assertSame('finalized', $run->fresh()->status);
    }

    public function test_employee_cannot_unlock(): void
    {
        $run = $this->finalizedRun();
        $employeeUser = User::create(['name' => 'Rank and file', 'email' => 'rankandfile@example.com', 'password' => Hash::make('password')]);
        $employeeUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->actingAs($employeeUser)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/payroll/runs/{$run->id}/unlock", ['reason' => 'x'])->assertForbidden();
        $this->assertSame('finalized', $run->fresh()->status);
    }

    public function test_a_draft_run_cannot_be_unlocked(): void
    {
        $run = $this->createRun();

        $this->unlock($run)->assertStatus(422);
    }

    public function test_a_run_marked_paid_cannot_be_unlocked(): void
    {
        $run = $this->finalizedRun();
        $this->actingHr()->post("/app/payroll/runs/{$run->id}/mark-paid")->assertRedirect();

        $this->unlock($run)->assertStatus(422);
        $this->assertSame('finalized', $run->fresh()->status);
    }

    public function test_a_run_filed_with_an_agency_cannot_be_unlocked(): void
    {
        $run = $this->finalizedRun();
        PayrollSubmission::where('payroll_run_id', $run->id)->limit(1)->update(['submitted_at' => now()]);
        $this->assertTrue(PayrollSubmission::where('payroll_run_id', $run->id)->whereNotNull('submitted_at')->exists());

        $this->unlock($run)->assertStatus(422);
        $this->assertSame('finalized', $run->fresh()->status);
    }

    public function test_cannot_unlock_while_a_later_month_is_finalized(): void
    {
        $june = $this->finalizedRun('2026-06');
        $july = $this->finalizedRun('2026-07');

        $this->unlock($june)->assertStatus(422);
        $this->assertSame('finalized', $june->fresh()->status);

        // Unlocking the later month first frees the earlier one.
        $this->unlock($july)->assertRedirect();
        $this->unlock($june)->assertRedirect();
        $this->assertSame('draft', $june->fresh()->status);
    }

    public function test_unlock_returns_claims_overtime_and_unpaid_leave_to_unpaid_and_refinalize_pays_them_again(): void
    {
        $claim = Claim::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id,
            'type' => 'expense', 'title' => 'Dock', 'amount' => 120, 'status' => 'approved', 'date' => now()->toDateString(),
        ]);
        $ot = OvertimeRequest::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id,
            'ot_date' => '2026-06-15', 'hours' => 4, 'rate_multiplier' => 1.50,
            'reason' => 'Backlog', 'status' => 'approved',
        ]);
        $leaveType = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Unpaid', 'entitlement' => 0, 'is_unpaid' => true]);
        $leave = LeaveRequest::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'leave_type_id' => $leaveType->id,
            'date_from' => '2026-06-10', 'date_to' => '2026-06-12', 'days' => 3, 'status' => 'approved',
        ]);

        $run = $this->finalizedRun();
        $this->assertSame('paid', $claim->fresh()->status);

        $this->unlock($run)->assertRedirect();
        $this->assertSame('approved', $claim->fresh()->status);
        $this->assertNull($claim->fresh()->paid_at);
        $this->assertNull($ot->fresh()->paid_at);
        $this->assertNull($leave->fresh()->paid_at);

        // An edit while unlocked recomputes the payslip and must keep the OT and unpaid leave it pulled.
        $payslip = $run->payslips()->firstOrFail();
        $this->actingHr()->post("/app/payroll/payslips/{$payslip->id}", ['epf_employee_override' => 500])->assertRedirect();
        $payslip->refresh();
        $this->assertContains($ot->id, $payslip->overtime_request_ids);
        $this->assertContains($leave->id, $payslip->unpaid_leave_request_ids);
        $this->assertEqualsWithDelta(500.0, (float) $payslip->epf_employee, 0.001);

        $this->actingHr()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();
        $this->assertSame('finalized', $run->fresh()->status);
        $this->assertSame('paid', $claim->fresh()->status);
        $this->assertNotNull($ot->fresh()->paid_at);
        $this->assertNotNull($leave->fresh()->paid_at);
    }
}
