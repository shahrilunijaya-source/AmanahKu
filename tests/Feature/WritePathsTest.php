<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\HandbookSection;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WritePathsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_leave_application_persists_a_request(): void
    {
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 18]);

        $this->actingInTenant()->post('/app/leave', [
            'leave_type_id' => $type->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-03',
            'reason' => 'Trip',
        ])->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $this->employee->id,
            'tenant_id' => $this->tenant->id,
            'days' => 3,
            'status' => 'submitted',
        ]);
    }

    public function test_invalid_leave_dates_are_rejected(): void
    {
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 18]);

        $this->actingInTenant()->post('/app/leave', [
            'leave_type_id' => $type->id,
            'date_from' => '2026-07-05',
            'date_to' => '2026-07-01', // before start
        ])->assertSessionHasErrors('date_to');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_clock_in_creates_a_record_for_today(): void
    {
        $this->actingInTenant()->post('/app/attendance/clock', ['action' => 'in'])->assertRedirect();

        $record = $this->employee->attendanceRecords()->whereDate('date', now())->first();
        $this->assertNotNull($record);
        $this->assertSame($this->tenant->id, $record->tenant_id);
        $this->assertNotNull($record->clock_in);
    }

    private function makeApprover(string $role = 'hr'): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => $role]);

        return $hr;
    }

    private function pendingRequest(LeaveType $type): LeaveRequest
    {
        return LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-07-01', 'date_to' => '2026-07-02',
            'days' => 2, 'status' => 'submitted',
        ]);
    }

    public function test_management_approves_a_verified_request_and_balance_decrements(): void
    {
        // Two-step gate: the request is already verified by a superior; management approves.
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 18]);
        LeaveBalance::create(['employee_id' => $this->employee->id, 'leave_type_id' => $type->id, 'balance' => 10]);
        $req = $this->pendingRequest($type);
        $req->update(['status' => 'verified']);

        $this->actingAs($this->makeApprover('management'))
            ->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/leave/{$req->id}/approve")
            ->assertRedirect();

        $this->assertSame('approved', $req->fresh()->status);
        $this->assertEqualsWithDelta(8.0, (float) LeaveBalance::first()->balance, 0.001);
    }

    public function test_employee_cannot_approve_leave(): void
    {
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 18]);
        $req = $this->pendingRequest($type);
        $req->update(['status' => 'verified']);

        // The employee (default setUp user) is not management.
        $this->actingInTenant()->post("/app/leave/{$req->id}/approve")->assertForbidden();
        $this->assertSame('verified', $req->fresh()->status);
    }

    public function test_claim_is_submitted(): void
    {
        $this->actingInTenant()->post('/app/claims', [
            'type' => 'mileage', 'title' => 'Client visit', 'amount' => 120.50,
            'date' => '2026-06-20', 'reason' => 'Klang',
        ])->assertRedirect();

        $this->assertDatabaseHas('claims', [
            'employee_id' => $this->employee->id,
            'tenant_id' => $this->tenant->id,
            'status' => 'submitted',
            'amount' => 120.50,
        ]);
    }

    public function test_employee_cannot_approve_claim_but_management_can_approve_verified(): void
    {
        $claim = Claim::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'type' => 'expense', 'title' => 'X', 'amount' => 50, 'date' => '2026-06-20', 'status' => 'verified',
        ]);

        // The employee is not management.
        $this->actingInTenant()->post("/app/claims/{$claim->id}/approve")->assertForbidden();
        $this->assertSame('verified', $claim->fresh()->status);

        // Management gives final approval on a verified claim.
        $this->actingAs($this->makeApprover('management'))->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/claims/{$claim->id}/approve")->assertRedirect();
        $this->assertSame('approved', $claim->fresh()->status);
    }

    public function test_employee_acknowledges_a_policy(): void
    {
        $section = HandbookSection::create([
            'tenant_id' => $this->tenant->id, 'category' => 'Conduct', 'title' => 'Code of Conduct',
            'version' => '2.1', 'requires_ack' => true, 'body' => '...', 'sort' => 0,
        ]);

        $this->actingInTenant()->post("/app/handbook/{$section->id}/acknowledge")->assertRedirect();

        $this->assertDatabaseHas('policy_acknowledgements', [
            'employee_id' => $this->employee->id,
            'handbook_section_id' => $section->id,
            'version' => '2.1',
        ]);
    }

    public function test_admin_screens_are_role_gated(): void
    {
        // Employee cannot view admin.
        $this->actingInTenant()->get('/app/settings')->assertForbidden();

        // HR can.
        $this->actingAs($this->makeApprover('hr'))->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/settings')->assertOk();
    }

    public function test_admin_can_change_member_role(): void
    {
        $member = $this->makeApprover('employee'); // a second user, role employee
        $hr = $this->makeApproverNamed('chief@example.com', 'hr');

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/admin/roles/{$member->id}", ['role' => 'manager'])->assertRedirect();

        $this->assertSame('manager', $member->fresh()->roleIn($this->tenant));
    }

    /**
     * Roles screen edits post over AJAX (no reload → the embedded screen keeps its scroll).
     * An XHR/JSON request must get a JSON body back, not a redirect. Guards the UX fix.
     */
    public function test_role_and_scope_updates_return_json_for_xhr(): void
    {
        $member = $this->makeApprover('employee');
        $hr = $this->makeApproverNamed('chief2@example.com', 'hr');

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->postJson("/app/admin/roles/{$member->id}", ['role' => 'manager'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('manager', $member->fresh()->roleIn($this->tenant));

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->postJson("/app/admin/scope/{$member->id}", ['data_scope' => 'department'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('department', $member->fresh()->tenants()->first()->pivot->data_scope);
    }

    private function makeApproverNamed(string $email, string $role): User
    {
        $u = User::create(['name' => 'Chief', 'email' => $email, 'password' => bcrypt('password')]);
        $u->tenants()->attach($this->tenant->id, ['role' => $role]);

        return $u;
    }
}
