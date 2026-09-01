<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\KnowledgeContribution;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
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

        return $this->makeEligible(Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId,
        ]));
    }

    /**
     * Every leave type this tenant has, opened at a generous balance. HR ticks eligibility
     * per person on Leave Setup and that tick IS the balance row, so a fixture employee
     * with no rows cannot apply for anything.
     */
    private function makeEligible(Employee $e): Employee
    {
        foreach (LeaveType::all() as $t) {
            LeaveBalance::firstOrCreate(
                ['employee_id' => $e->id, 'leave_type_id' => $t->id],
                ['balance' => 30],
            );
        }

        return $e;
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
        LeaveBalance::updateOrCreate(['employee_id' => $report->id, 'leave_type_id' => $this->type->id], ['balance' => 10]);
        $req = $this->request($report, 'verified', $manager->id);

        $this->actingAsEmployee($mgmt)->post("/app/leave/{$req->id}/approve")
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('approved', $req->fresh()->status);
        $this->assertEqualsWithDelta(8.0, (float) LeaveBalance::where('employee_id', $report->id)->where('leave_type_id', $this->type->id)->value('balance'), 0.001);
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
            ->assertSee('Yours to verify')
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
            ->assertSee('Waiting for final approval')
            ->assertSee('Reportee');
    }

    public function test_leave_screen_still_renders_the_tall_approval_chain(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);

        $this->actingAsEmployee($report)->get('/app/leave')->assertOk()
            ->assertSee('Who signs off your request')
            ->assertSee('Manager')
            ->assertSee('Director');
    }

    // --- Notifications ------------------------------------------------------

    public function test_submitting_notifies_the_superior_to_verify(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);

        $this->actingAsEmployee($report)->post('/app/leave', [
            'reason' => 'Family matters.',
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

    public function test_verifier_comment_is_stored_and_shown_to_the_approver(): void
    {
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = $this->request($report);

        $this->actingAsEmployee($manager)->post("/app/leave/{$req->id}/verify", [
            'verify_note' => 'Cover arranged with the team.',
        ])->assertRedirect();

        $this->assertSame('Cover arranged with the team.', $req->fresh()->verify_note);

        $this->actingAsEmployee($mgmt)->get('/app/leave')->assertOk()
            ->assertSee('Cover arranged with the team.');
    }

    public function test_the_sidebar_marks_leave_when_something_waits_for_you(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $this->settleKnowledge($manager);

        // Nothing pending yet: no dot anywhere in the nav.
        $this->actingAsEmployee($manager)->get('/app/dash')->assertOk()->assertDontSee('uj-nav-dot');

        $this->request($report);

        $this->actingAsEmployee($manager)->get('/app/dash')->assertOk()->assertSee('uj-nav-dot');
        // The person who filed it has nothing to act on, so their nav stays clean.
        $this->settleKnowledge($report);
        $this->actingAsEmployee($report)->get('/app/dash')->assertOk()->assertDontSee('uj-nav-dot');
    }

    public function test_the_sidebar_marks_overtime_too(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $this->settleKnowledge($manager);

        $this->actingAsEmployee($manager)->get('/app/dash')->assertOk()->assertDontSee('uj-nav-dot');

        OvertimeRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'ot_date' => '2026-06-20', 'hours' => 4, 'rate_multiplier' => '1.50',
            'reason' => 'Backlog', 'status' => 'submitted',
        ]);

        $this->actingAsEmployee($manager)->get('/app/dash')->assertOk()->assertSee('uj-nav-dot');
    }

    /** Silence the standing Knowledge Bank dot so the nav assertions here isolate leave. */
    private function settleKnowledge(Employee $e): void
    {
        KnowledgeContribution::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'year' => (int) now()->year, 'month' => (int) now()->month, 'submitted' => true,
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

    // --- HR skips the verify step (reports straight to the directors) --------

    public function test_hr_leave_opens_pre_verified_and_goes_to_the_directors(): void
    {
        $director = $this->member('director', 'Shahril');
        $hr = $this->member('hr', 'HR Officer');

        $this->actingAsEmployee($hr)->post('/app/leave', [
            'reason' => 'Family matters.',
            'leave_type_id' => $this->type->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-02',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $req = LeaveRequest::where('employee_id', $hr->id)->firstOrFail();
        $this->assertSame('verified', $req->status);
        $this->assertNull($req->verified_by_id);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $director->user_id, 'title' => 'Leave awaiting final approval',
        ]);

        // The timeline says the step was skipped, not that an unnamed superior verified it.
        $this->actingAsEmployee($hr)->get('/app/leave')->assertOk()
            ->assertSee('No verification needed')
            ->assertDontSee('Verified by superior');
    }

    public function test_hr_gives_final_approval_on_someone_elses_verified_leave(): void
    {
        $manager = $this->member('manager', 'Manager');
        $hr = $this->member('hr', 'HR Officer');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = $this->request($report, 'verified', $manager->id);

        $this->actingAsEmployee($hr)->post("/app/leave/{$req->id}/approve")->assertRedirect();
        $this->assertSame('approved', $req->fresh()->status);
    }

    public function test_hr_cannot_approve_their_own_pre_verified_leave(): void
    {
        $hr = $this->member('hr', 'HR Officer');
        $req = $this->request($hr, 'verified');

        $this->actingAsEmployee($hr)->post("/app/leave/{$req->id}/approve")->assertForbidden();
        $this->assertSame('verified', $req->fresh()->status);
    }

    // --- A director sits at the top of the chart, so they skip verify too ----

    public function test_director_leave_opens_pre_verified_and_goes_to_the_approval_tier(): void
    {
        $hr = $this->member('hr', 'HR Officer');
        $director = $this->member('director', 'Shahril');

        $this->actingAsEmployee($director)->post('/app/leave', [
            'reason' => 'Family matters.',
            'leave_type_id' => $this->type->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-02',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $req = LeaveRequest::where('employee_id', $director->id)->firstOrFail();
        $this->assertSame('verified', $req->status);
        $this->assertNull($req->verified_by_id);

        // It reaches somebody: HR is in the final-approval tier and gets the ping.
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $hr->user_id, 'title' => 'Leave awaiting final approval',
        ]);
    }

    public function test_director_cannot_approve_their_own_pre_verified_leave(): void
    {
        $director = $this->member('director', 'Shahril');
        $req = $this->request($director, 'verified');

        $this->actingAsEmployee($director)->post("/app/leave/{$req->id}/approve")->assertForbidden();
        $this->assertSame('verified', $req->fresh()->status);
    }

    public function test_hr_gives_final_approval_on_a_directors_leave(): void
    {
        $hr = $this->member('hr', 'HR Officer');
        $director = $this->member('director', 'Shahril');
        $req = $this->request($director, 'verified');

        $this->actingAsEmployee($hr)->post("/app/leave/{$req->id}/approve")->assertRedirect();
        $this->assertSame('approved', $req->fresh()->status);
    }

    /**
     * The role gate, not a missing reports_to_id: a plain employee whose org chart has no
     * superior still waits at `submitted` (StuckRequests surfaces it) rather than quietly
     * routing itself past the manager they should have.
     */
    public function test_a_plain_employee_with_no_superior_still_opens_submitted(): void
    {
        $orphan = $this->member('employee', 'Orphan');

        $this->actingAsEmployee($orphan)->post('/app/leave', [
            'reason' => 'Family matters.',
            'leave_type_id' => $this->type->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-02',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('submitted', LeaveRequest::where('employee_id', $orphan->id)->firstOrFail()->status);
    }

    // --- Cancel (the requester withdraws) -----------------------------------

    public function test_requester_cancels_their_own_pending_request(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = $this->request($report, 'verified', $manager->id);

        $this->actingAsEmployee($report)->post("/app/leave/{$req->id}/cancel")
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('cancelled', $req->fresh()->status);
    }

    public function test_an_approved_leave_that_already_started_cannot_be_cancelled(): void
    {
        // request() defaults to 2026-07-01, before "today" (2026-08-28) in this test suite.
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = $this->request($report, 'approved', $manager->id);

        $this->actingAsEmployee($report)->post("/app/leave/{$req->id}/cancel")->assertStatus(422);
        $this->assertSame('approved', $req->fresh()->status);
    }

    public function test_requester_cancels_their_own_approved_future_leave_and_balance_is_restored(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        LeaveBalance::updateOrCreate(['employee_id' => $report->id, 'leave_type_id' => $this->type->id], ['balance' => 8]);
        $req = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'leave_type_id' => $this->type->id, 'date_from' => '2026-12-01', 'date_to' => '2026-12-02',
            'days' => 2, 'status' => 'approved', 'verified_by_id' => $manager->id,
        ]);

        $this->actingAsEmployee($report)->post("/app/leave/{$req->id}/cancel")
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('cancelled', $req->fresh()->status);
        $this->assertEqualsWithDelta(10.0, (float) LeaveBalance::where('employee_id', $report->id)->where('leave_type_id', $this->type->id)->value('balance'), 0.001);
    }

    public function test_cancelling_an_approved_future_leave_strips_the_on_leave_timesheet_row(): void
    {
        TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'On Leave', 'requires_project' => false]);
        $manager = $this->member('manager', 'Manager');
        $mgmt = $this->member('management', 'Director');
        $report = $this->member('employee', 'Reportee', $manager->id);
        LeaveBalance::updateOrCreate(['employee_id' => $report->id, 'leave_type_id' => $this->type->id], ['balance' => 10]);
        Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'week_start' => '2026-12-14', 'status' => 'draft', 'total_hours' => 0,
        ]);
        $leave = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'leave_type_id' => $this->type->id, 'date_from' => '2026-12-16', 'date_to' => '2026-12-16',
            'days' => 1, 'status' => 'verified', 'verified_by_id' => $manager->id,
        ]);

        // Approve through the real route (as TimesheetTest does), which reconciles the
        // draft week and seeds the "On Leave" row.
        $this->actingAsEmployee($mgmt)->post("/app/leave/{$leave->id}/approve")->assertRedirect();
        $this->assertSame('leave', TimesheetEntry::whereDate('entry_date', '2026-12-16')->first()?->source);

        $this->actingAsEmployee($report)->post("/app/leave/{$leave->id}/cancel")->assertRedirect();

        $this->assertNull(TimesheetEntry::whereDate('entry_date', '2026-12-16')->first());
        $this->assertEqualsWithDelta(10.0, (float) LeaveBalance::where('employee_id', $report->id)->where('leave_type_id', $this->type->id)->value('balance'), 0.001);
    }

    public function test_a_leave_already_pulled_into_a_payslip_cannot_be_cancelled(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'leave_type_id' => $this->type->id, 'date_from' => '2026-12-01', 'date_to' => '2026-12-02',
            'days' => 2, 'status' => 'approved', 'verified_by_id' => $manager->id, 'paid_at' => now(),
        ]);

        $this->actingAsEmployee($report)->post("/app/leave/{$req->id}/cancel")->assertStatus(422);
        $this->assertSame('approved', $req->fresh()->status);
    }

    public function test_leave_screen_shows_cancel_only_for_a_still_future_approved_request(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $this->request($report, 'approved', $manager->id); // default dates are in the past
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'leave_type_id' => $this->type->id, 'date_from' => '2026-12-01', 'date_to' => '2026-12-02',
            'days' => 2, 'status' => 'approved', 'verified_by_id' => $manager->id,
        ]);

        $html = $this->actingAsEmployee($report)->get('/app/leave')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '/cancel"'));
    }

    public function test_nobody_else_can_cancel_someone_elses_request(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $req = $this->request($report);

        $this->actingAsEmployee($manager)->post("/app/leave/{$req->id}/cancel")->assertForbidden();
        $this->assertSame('submitted', $req->fresh()->status);
    }
}
