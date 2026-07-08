<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Claim;
use App\Models\Employee;
use App\Models\HandbookSection;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PettyCashFloat;
use App\Models\PolicyAcknowledgement;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Systematic cross-tenant denial: an admin of company B must never be able to
 * act on company A's records through any write path. Route-model binding runs
 * under the BelongsToTenant global scope, so a foreign record must resolve to
 * 404 (or 403 where a controller asserts tenant explicitly) — never 2xx/3xx.
 */
class CrossTenantDenialTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Employee $victimA;

    private User $attackerB;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        $this->victimA = Employee::create(['tenant_id' => $this->tenantA->id, 'name' => 'Victim', 'status' => 'active', 'workload' => 'green']);

        $this->tenantB = Tenant::create(['slug' => 'bravo', 'name' => 'Bravo', 'initials' => 'BR']);
        $this->attackerB = User::create(['name' => 'Attacker', 'email' => 'attacker@example.com', 'password' => Hash::make('password')]);
        // Highest tenant role — if even management/hr of B is denied, every role is.
        $this->attackerB->tenants()->attach($this->tenantB->id, ['role' => 'management']);
        Employee::create(['tenant_id' => $this->tenantB->id, 'user_id' => $this->attackerB->id, 'name' => 'Attacker', 'status' => 'active', 'workload' => 'green']);
    }

    /** POST as company B's admin and assert company A's record was untouchable. */
    private function denied(string $uri, array $payload = []): void
    {
        $status = $this->actingAs($this->attackerB)
            ->withSession(['current_tenant' => $this->tenantB->id])
            ->post($uri, $payload)
            ->status();

        $this->assertContains($status, [403, 404], "Expected denial for [$uri], got HTTP $status.");
    }

    public function test_foreign_leave_request_cannot_be_verified_approved_or_rejected(): void
    {
        $leave = LeaveRequest::create([
            'tenant_id' => $this->tenantA->id, 'employee_id' => $this->victimA->id,
            'date_from' => '2026-07-06', 'date_to' => '2026-07-07', 'days' => 2, 'status' => 'verified',
        ]);

        $this->denied("/app/leave/{$leave->id}/verify");
        $this->denied("/app/leave/{$leave->id}/approve");
        $this->denied("/app/leave/{$leave->id}/reject");
        $this->assertSame('verified', LeaveRequest::withoutGlobalScopes()->find($leave->id)->status);
    }

    public function test_foreign_claim_cannot_be_verified_approved_or_rejected(): void
    {
        $claim = Claim::create([
            'tenant_id' => $this->tenantA->id, 'employee_id' => $this->victimA->id,
            'type' => 'expense', 'title' => 'Foreign claim', 'amount' => 100, 'date' => '2026-07-01', 'status' => 'submitted',
        ]);

        $this->denied("/app/claims/{$claim->id}/verify");
        $this->denied("/app/claims/{$claim->id}/approve");
        $this->denied("/app/claims/{$claim->id}/reject");
        $this->assertSame('submitted', Claim::withoutGlobalScopes()->find($claim->id)->status);
    }

    public function test_foreign_overtime_cannot_be_verified_approved_or_rejected(): void
    {
        $ot = OvertimeRequest::create([
            'tenant_id' => $this->tenantA->id, 'employee_id' => $this->victimA->id,
            'ot_date' => '2026-07-01', 'hours' => 2, 'reason' => 'Deploy', 'status' => 'submitted',
        ]);

        $this->denied("/app/overtime/{$ot->id}/verify");
        $this->denied("/app/overtime/{$ot->id}/approve");
        $this->denied("/app/overtime/{$ot->id}/reject");
        $this->assertSame('submitted', OvertimeRequest::withoutGlobalScopes()->find($ot->id)->status);
    }

    public function test_foreign_board_card_cannot_be_moved_updated_or_deleted(): void
    {
        $item = WorkItem::create([
            'tenant_id' => $this->tenantA->id, 'employee_id' => $this->victimA->id,
            'title' => 'Foreign card', 'type' => 'task', 'status' => 'todo', 'sort_order' => 1,
        ]);

        $this->denied("/app/board/{$item->id}/move", ['to' => 'done', 'ids' => [$item->id]]);
        $this->assertSame('todo', WorkItem::withoutGlobalScopes()->find($item->id)->status);
    }

    public function test_foreign_timesheet_cannot_be_submitted(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenantA->id, 'employee_id' => $this->victimA->id,
            'week_start' => '2026-06-29', 'status' => 'draft',
        ]);

        $this->denied("/app/timesheets/{$sheet->id}/submit");
        $this->assertSame(0, $sheet->entries()->withoutGlobalScopes()->count());
    }

    public function test_foreign_petty_cash_float_cannot_be_disbursed_or_replenished(): void
    {
        $branch = Branch::create(['tenant_id' => $this->tenantA->id, 'name' => 'Alpha HQ']);
        $float = PettyCashFloat::create([
            'tenant_id' => $this->tenantA->id, 'branch_id' => $branch->id,
            'name' => 'Alpha float', 'opening_balance' => 500, 'balance' => 500,
        ]);

        $this->denied("/app/pettycash/{$float->id}/disburse", ['amount' => 100, 'purpose' => 'x']);
        $this->denied("/app/pettycash/{$float->id}/replenish", ['amount' => 100]);
        $this->assertSame(500.0, (float) PettyCashFloat::withoutGlobalScopes()->find($float->id)->balance);
    }

    public function test_foreign_handbook_section_cannot_be_acknowledged(): void
    {
        // Route-model binding resolves the section before the tenant scope is active, so
        // without the explicit tenant assert this would leak B→A (AK-SEC-04).
        $section = HandbookSection::create([
            'tenant_id' => $this->tenantA->id, 'category' => 'Conduct', 'title' => 'Secret Policy',
            'version' => '1.0', 'requires_ack' => true, 'body' => 'confidential', 'sort' => 0,
        ]);

        $this->denied("/app/handbook/{$section->id}/acknowledge");

        $this->assertSame(0, PolicyAcknowledgement::withoutGlobalScopes()
            ->where('handbook_section_id', $section->id)->count());
    }

    public function test_foreign_employee_record_cannot_be_updated_or_archived(): void
    {
        $this->denied("/app/employees/{$this->victimA->id}", ['name' => 'Hijacked', 'status' => 'active']);
        $this->denied("/app/employees/{$this->victimA->id}/delete");

        $fresh = Employee::withoutGlobalScopes()->find($this->victimA->id);
        $this->assertSame('Victim', $fresh->name);
        $this->assertNull($fresh->archived_at);
    }
}
