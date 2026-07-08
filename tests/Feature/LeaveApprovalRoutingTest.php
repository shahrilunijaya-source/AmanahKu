<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Two-step leave approval routed by the org chart:
 *   submitted ──verify(immediate superior)──▶ verified ──approve(management)──▶ approved
 *
 * The immediate superior (reports_to) can only verify; final approval is management only;
 * nobody acts on their own request and the verifier cannot also approve.
 */
class LeaveApprovalRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private LeaveType $type;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 18]);
    }

    private function member(string $role, string $name, ?int $reportsToId = null): Employee
    {
        $this->seq++;
        $user = User::create(['name' => $name, 'email' => "user{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId,
        ]);
    }

    private function actingAsEmployee(Employee $e): self
    {
        $this->actingAs($e->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function request(Employee $employee, string $status = 'submitted', ?int $verifiedById = null): LeaveRequest
    {
        return LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'leave_type_id' => $this->type->id, 'date_from' => '2026-07-01', 'date_to' => '2026-07-02',
            'days' => 2, 'status' => $status, 'verified_by_id' => $verifiedById,
        ]);
    }

    // --- Verify (step 1: immediate superior) --------------------------------

    public function test_immediate_superior_can_verify(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = $this->request($report);

        $this->actingAsEmployee($manager)->post("/app/leave/{$req->id}/verify")
            ->assertRedirect()->assertSessionHas('ok');

        $fresh = $req->fresh();
        $this->assertSame('verified', $fresh->status);
        $this->assertSame($manager->id, $fresh->verified_by_id);
    }

    public function test_a_non_superior_cannot_verify(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $other = $this->member('manager', 'Other Manager');
        $req = $this->request($report);

        $this->actingAsEmployee($other)->post("/app/leave/{$req->id}/verify")->assertForbidden();
        $this->assertSame('submitted', $req->fresh()->status);
    }

    public function test_management_cannot_skip_verification(): void
    {
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee');
        $req = $this->request($report); // still 'submitted'

        $this->actingAsEmployee($mgmt)->post("/app/leave/{$req->id}/approve")->assertStatus(422);
        $this->assertSame('submitted', $req->fresh()->status);
    }

    // --- Approve (step 2: management) ---------------------------------------

    public function test_management_approves_a_verified_request_and_balance_decrements(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        LeaveBalance::create(['employee_id' => $report->id, 'leave_type_id' => $this->type->id, 'balance' => 10]);
        $req = $this->request($report, 'verified', $manager->id);

        $this->actingAsEmployee($mgmt)->post("/app/leave/{$req->id}/approve")
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('approved', $req->fresh()->status);
        $this->assertEqualsWithDelta(8.0, (float) LeaveBalance::first()->balance, 0.001);
    }

    public function test_a_manager_cannot_give_final_approval(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = $this->request($report, 'verified', $manager->id);

        // The manager only verifies; final approval is management's.
        $this->actingAsEmployee($manager)->post("/app/leave/{$req->id}/approve")->assertForbidden();
        $this->assertSame('verified', $req->fresh()->status);
    }

    public function test_the_verifier_cannot_also_approve(): void
    {
        // A management-role person who is ALSO the report's immediate superior: they may
        // verify, but segregation of duties blocks them approving their own verification.
        $boss = $this->member('management', 'Player Coach');
        $report = $this->member('employee', 'Reportee', $boss->id);
        $req = $this->request($report, 'verified', $boss->id);

        $this->actingAsEmployee($boss)->post("/app/leave/{$req->id}/approve")->assertForbidden();
        $this->assertSame('verified', $req->fresh()->status);
    }

    // --- Queues -------------------------------------------------------------

    public function test_superior_verify_queue_shows_only_their_submitted_reports(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mine = $this->member('employee', 'Mine Reportee', $manager->id);
        $stranger = $this->member('employee', 'Stranger Person');
        $this->request($mine);
        $this->request($stranger);

        $this->actingAsEmployee($manager)->get('/app/leave')->assertOk()
            ->assertSee('To verify')
            ->assertSee('Mine Reportee')
            ->assertDontSee('Stranger Person');
    }

    public function test_management_approve_queue_shows_verified_requests(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $this->request($report, 'verified', $manager->id);

        $this->actingAsEmployee($mgmt)->get('/app/leave')->assertOk()
            ->assertSee('To approve')
            ->assertSee('Reportee');
    }

    // --- Notifications ------------------------------------------------------

    public function test_submitting_notifies_the_superior_to_verify(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);

        $this->actingAsEmployee($report)->post('/app/leave', [
            'leave_type_id' => $this->type->id, 'date_from' => '2026-07-10', 'date_to' => '2026-07-11',
        ])->assertRedirect();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $manager->user_id, 'title' => 'Leave awaiting your verification',
        ]);
    }

    public function test_verifying_notifies_management_to_approve(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = $this->request($report);

        $this->actingAsEmployee($manager)->post("/app/leave/{$req->id}/verify")->assertRedirect();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $mgmt->user_id, 'title' => 'Leave awaiting approval',
        ]);
    }

    // --- Reject (either stage) -----------------------------------------------

    public function test_superior_rejects_a_submitted_request_and_the_requester_is_notified(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = $this->request($report);

        $this->actingAsEmployee($manager)->post("/app/leave/{$req->id}/reject")
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('rejected', $req->fresh()->status);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $report->user_id, 'title' => 'Leave declined',
        ]);
    }

    public function test_management_can_override_reject_a_submitted_request(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = $this->request($report);

        // Management is not the immediate superior, but may override-reject pre-verification.
        $this->actingAsEmployee($mgmt)->post("/app/leave/{$req->id}/reject")->assertRedirect();
        $this->assertSame('rejected', $req->fresh()->status);
    }

    public function test_management_rejects_a_verified_request(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = $this->request($report, 'verified', $manager->id);

        $this->actingAsEmployee($mgmt)->post("/app/leave/{$req->id}/reject")->assertRedirect();
        $this->assertSame('rejected', $req->fresh()->status);
    }

    public function test_a_non_superior_manager_cannot_reject_a_submitted_request(): void
    {
        $manager = $this->member('manager', 'Manager');
        $other = $this->member('manager', 'Other Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = $this->request($report);

        $this->actingAsEmployee($other)->post("/app/leave/{$req->id}/reject")->assertForbidden();
        $this->assertSame('submitted', $req->fresh()->status);
    }

    public function test_the_verifier_cannot_also_reject_at_the_approval_stage(): void
    {
        // Same segregation of duties as approve: whoever verified may not decide the outcome.
        $boss = $this->member('management', 'Player Coach');
        $report = $this->member('employee', 'Reportee', $boss->id);
        $req = $this->request($report, 'verified', $boss->id);

        $this->actingAsEmployee($boss)->post("/app/leave/{$req->id}/reject")->assertForbidden();
        $this->assertSame('verified', $req->fresh()->status);
    }

    public function test_an_approved_request_cannot_be_rejected(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = $this->request($report, 'approved', $manager->id);

        $this->actingAsEmployee($mgmt)->post("/app/leave/{$req->id}/reject")->assertStatus(422);
        $this->assertSame('approved', $req->fresh()->status);
    }
}
