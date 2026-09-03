<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveGrant;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Leave screen splits by audience: Apply and My leave for everyone, Approvals only
 * for whoever actually has a review queue with rows in it. These tests pin the gate —
 * an employee must never be served the review markup, and a manager holding a request
 * must be — plus the `?tab=` deep link a notification uses to land on Approvals.
 */
class LeaveScreenTabsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private LeaveType $annual;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->annual = LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 16, 'min_notice_days' => 3,
        ]);
    }

    private function member(string $role, string $name, ?int $reportsToId = null): Employee
    {
        $this->seq++;
        $user = User::create(['name' => $name, 'email' => "user{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId,
        ]);

        LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $this->annual->id, 'balance' => 16,
        ]);

        return $employee;
    }

    private function screenAs(Employee $e, string $query = '')
    {
        return $this->actingAs($e->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/leave'.$query);
    }

    private function submittedRequestFor(Employee $e): LeaveRequest
    {
        return $e->leaveRequests()->create([
            'tenant_id' => $this->tenant->id,
            'leave_type_id' => $this->annual->id,
            'date_from' => now()->addDays(10)->toDateString(),
            'date_to' => now()->addDays(12)->toDateString(),
            'days' => 3,
            'status' => 'submitted',
        ]);
    }

    public function test_an_employee_with_nothing_to_review_gets_no_approvals_tab(): void
    {
        $manager = $this->member('manager', 'Manager');
        $staff = $this->member('employee', 'Staff', $manager->id);
        $this->submittedRequestFor($staff);

        $res = $this->screenAs($staff);

        $res->assertOk()
            ->assertSee('Apply')
            ->assertDontSee('Yours to verify')
            ->assertDontSee('Waiting for final approval')
            // No verify/approve control is rendered for them at all, not merely hidden.
            ->assertDontSee(route('leave.bulk-verify'), false)
            ->assertDontSee(route('leave.bulk-approve'), false);
    }

    /**
     * The counts row is the point of the tab: a manager must be able to see what they
     * decided this year, not only what is still waiting.
     */
    public function test_the_approvals_tab_counts_what_the_viewer_decided_this_year(): void
    {
        $management = $this->member('management', 'Director');
        $manager = $this->member('manager', 'Manager', $management->id);
        $staff = $this->member('employee', 'Staff', $manager->id);

        // One approved by this director, one rejected by them, one still pending.
        $this->submittedRequestFor($staff)->update([
            'status' => 'approved', 'approved_by_id' => $management->id, 'approved_at' => now(),
        ]);
        $this->submittedRequestFor($staff)->update([
            'status' => 'rejected', 'rejected_by_id' => $management->id, 'rejected_at' => now(),
        ]);
        $this->submittedRequestFor($staff)->update([
            'status' => 'verified', 'verified_by_id' => $manager->id, 'verified_at' => now(),
        ]);

        $res = $this->screenAs($management);

        $res->assertOk()
            ->assertSee('Approved this year')
            ->assertSee('Rejected this year');
    }

    /**
     * The tab used to exist only while something was pending, which would have taken the
     * whole decision history off the screen the moment a manager cleared their queue.
     */
    public function test_an_approver_with_an_empty_queue_still_gets_the_approvals_tab(): void
    {
        $management = $this->member('management', 'Director');
        $manager = $this->member('manager', 'Manager', $management->id);
        $staff = $this->member('employee', 'Staff', $manager->id);

        $this->submittedRequestFor($staff)->update([
            'status' => 'approved', 'approved_by_id' => $management->id, 'approved_at' => now(),
        ]);

        $res = $this->screenAs($management);

        // Nothing pending, but the tab and the history are both there.
        $res->assertOk()
            ->assertSee('Approved this year')
            ->assertSee('Nothing is waiting on you.');
    }

    public function test_the_tab_is_named_for_what_the_viewer_can_actually_do(): void
    {
        // A plain manager only recommends — scopeToApprove() closes for them — so calling
        // their tab "Approvals" would promise a power they do not have.
        $manager = $this->member('manager', 'Manager');
        $staff = $this->member('employee', 'Staff', $manager->id);
        $this->submittedRequestFor($staff);

        $this->screenAs($manager)->assertOk()
            ->assertViewHas('givesFinalApproval', false)
            ->assertSee('To verify');

        // A director signs off, so theirs keeps the stronger word.
        $management = $this->member('management', 'Director');

        $this->screenAs($management)->assertOk()
            ->assertViewHas('givesFinalApproval', true)
            ->assertSee('Approvals');
    }

    /**
     * A leave the viewer approved and the applicant later withdrew stays in the approved
     * list, marked — for a while the approver believed that person was away.
     */
    public function test_a_withdrawn_leave_stays_in_the_approved_list_and_is_tagged(): void
    {
        $management = $this->member('management', 'Director');
        $manager = $this->member('manager', 'Manager', $management->id);
        $staff = $this->member('employee', 'Staff', $manager->id);

        $this->submittedRequestFor($staff)->update([
            'status' => 'cancelled', 'approved_by_id' => $management->id, 'approved_at' => now(),
        ]);

        $this->screenAs($management)
            ->assertOk()
            ->assertSee('withdrawn by applicant');
    }

    /**
     * A withdrawal nobody had acted on is the applicant changing their mind before the
     * queue ever saw it — noise on an approver's screen, and deliberately absent.
     */
    public function test_a_leave_withdrawn_before_anyone_acted_never_reaches_the_approver(): void
    {
        $management = $this->member('management', 'Director');
        $manager = $this->member('manager', 'Manager', $management->id);
        $staff = $this->member('employee', 'Staff', $manager->id);

        $this->submittedRequestFor($staff)->update(['status' => 'cancelled']);

        $this->screenAs($management)
            ->assertOk()
            ->assertDontSee('withdrawn by applicant');
    }

    /**
     * Someone else's decision is not the viewer's history — two managers must not see
     * each other's totals.
     */
    public function test_a_decision_made_by_someone_else_is_not_counted_as_yours(): void
    {
        $management = $this->member('management', 'Director');
        $other = $this->member('management', 'Other Director');
        $manager = $this->member('manager', 'Manager', $management->id);
        $staff = $this->member('employee', 'Staff', $manager->id);

        $this->submittedRequestFor($staff)->update([
            'status' => 'approved', 'approved_by_id' => $other->id, 'approved_at' => now(),
        ]);

        $this->screenAs($management)
            ->assertOk()
            ->assertSee('You have not approved anything this year.');
    }

    /**
     * The verifier passed it up; somebody else said no. That rejection belongs to the
     * person who made it, not to whoever moved it along the chain.
     */
    public function test_a_verifier_does_not_own_the_decision_someone_else_made(): void
    {
        $management = $this->member('management', 'Director');
        $manager = $this->member('manager', 'Manager', $management->id);
        $staff = $this->member('employee', 'Staff', $manager->id);

        $this->submittedRequestFor($staff)->update([
            'status' => 'rejected',
            'verified_by_id' => $manager->id, 'verified_at' => now(),
            'rejected_by_id' => $management->id, 'rejected_at' => now(),
        ]);

        // The manager verified it. The director rejected it. The manager rejected nothing.
        $this->screenAs($manager)
            ->assertOk()
            ->assertSee('You have not rejected anything this year.');
    }

    /**
     * Same trap on the withdrawal path: a request the manager verified and the applicant
     * then pulled was never approved by anyone, so it is nobody's approved history.
     */
    public function test_a_withdrawal_before_approval_is_not_the_verifiers_approved_history(): void
    {
        $manager = $this->member('manager', 'Manager');
        $staff = $this->member('employee', 'Staff', $manager->id);

        $this->submittedRequestFor($staff)->update([
            'status' => 'cancelled',
            'verified_by_id' => $manager->id, 'verified_at' => now(),
        ]);

        $this->screenAs($manager)
            ->assertOk()
            ->assertSee('You have not approved anything this year.');
    }

    public function test_the_immediate_superior_gets_the_verify_queue(): void
    {
        $manager = $this->member('manager', 'Manager');
        $staff = $this->member('employee', 'Staff', $manager->id);
        $this->submittedRequestFor($staff);

        $res = $this->screenAs($manager);

        $res->assertOk()
            ->assertSee('Yours to verify')
            ->assertSee(route('leave.bulk-verify'), false)
            ->assertSee('Staff');
    }

    public function test_management_gets_the_final_approval_queue_once_verified(): void
    {
        $management = $this->member('management', 'Director');
        $manager = $this->member('manager', 'Manager', $management->id);
        $staff = $this->member('employee', 'Staff', $manager->id);

        $leave = $this->submittedRequestFor($staff);
        $leave->update(['status' => 'verified', 'verified_by_id' => $manager->id, 'verified_at' => now()]);

        $res = $this->screenAs($management);

        $res->assertOk()
            ->assertSee('Waiting for final approval')
            ->assertSee(route('leave.bulk-approve'), false);
    }

    /**
     * The review row states the balance the requester is left with if you approve. It is
     * the number the queue never carried, and the reason both queues eager-load balances.
     */
    public function test_the_review_row_states_the_balance_left_after_approval(): void
    {
        $manager = $this->member('manager', 'Manager');
        $staff = $this->member('employee', 'Staff', $manager->id);
        $this->submittedRequestFor($staff);

        // 16 entitlement, 3 days requested.
        $this->screenAs($manager)->assertOk()->assertSee('Leaves them')->assertSee('>13<', false);
    }

    public function test_the_tab_query_only_accepts_a_tab_that_exists_for_this_person(): void
    {
        $manager = $this->member('manager', 'Manager');
        $staff = $this->member('employee', 'Staff', $manager->id);
        $this->submittedRequestFor($staff);

        // The manager can be deep-linked into Approvals by a notification.
        $this->screenAs($manager, '?tab=approvals')->assertOk()->assertSee("tab: 'approvals'", false);

        // The applicant has no such tab, so the same link must not open a dead panel.
        $this->screenAs($staff, '?tab=approvals')->assertOk()->assertSee("tab: 'apply'", false);

        // Junk is ignored rather than blanking the screen.
        $this->screenAs($staff, '?tab=nonsense')->assertOk()->assertSee("tab: 'apply'", false);
    }

    /**
     * A rejected submit must come back on the form holding the input, not on whichever
     * tab the URL still names.
     */
    public function test_a_failed_submit_returns_to_the_apply_tab(): void
    {
        $manager = $this->member('manager', 'Manager');
        $staff = $this->member('employee', 'Staff', $manager->id);
        $this->submittedRequestFor($staff);

        $this->actingAs($manager->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->from('/app/leave?tab=approvals')
            ->followingRedirects()
            // Annual needs 3 days' notice; today breaks it.
            ->post(route('leave.store'), [
                'reason' => 'Family matters.',
                'leave_type_id' => $this->annual->id,
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ])
            ->assertOk()
            // Redirected back to ?tab=approvals, but the rejected input is on Apply, so
            // that is the tab the screen must open on.
            ->assertSee("tab: 'apply'", false)
            ->assertSee('must be applied for at least 3 days in advance', false);
    }

    /** A granted type: no yearly entitlement, its quota comes one grant at a time. */
    private function replacement(): LeaveType
    {
        return LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Replacement', 'entitlement' => 0,
            'is_hr_granted_only' => true,
        ]);
    }

    private function grantAsHr(Employee $hr, array $payload)
    {
        return $this->actingAs($hr->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->from('/app/leave-setup')
            ->post(route('leave.grant'), $payload);
    }

    private function applyAs(Employee $staff, array $payload)
    {
        return $this->actingAs($staff->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->from('/app/leave')
            ->post(route('leave.store'), $payload);
    }

    /**
     * HR grants a quota rather than booking the day: the days land on the balance and the
     * grant row keeps the remark saying which rest day earned them.
     */
    public function test_hr_grants_replacement_quota_onto_the_balance(): void
    {
        $staff = $this->member('employee', 'Staff');
        $hr = $this->member('hr', 'Hana');
        $type = $this->replacement();

        $this->grantAsHr($hr, [
            'employee_id' => $staff->id,
            'leave_type_id' => $type->id,
            'days' => 1.5,
            'remark' => 'Worked Saturday 31 Aug',
        ])->assertRedirect();

        $grant = LeaveGrant::sole();
        $this->assertSame($staff->id, $grant->employee_id);
        $this->assertEquals(1.5, (float) $grant->days);
        $this->assertSame('Worked Saturday 31 Aug', $grant->remark);
        $this->assertSame($hr->id, $grant->granted_by_id);

        $this->assertEquals(1.5, (float) LeaveBalance::where('employee_id', $staff->id)
            ->where('leave_type_id', $type->id)->value('balance'));

        // No leave is booked by granting — the staff member applies for the days themselves.
        $this->assertSame(0, LeaveRequest::where('leave_type_id', $type->id)->count());
    }

    /** A second grant adds to the quota rather than replacing it. */
    public function test_grants_accumulate_on_the_same_balance(): void
    {
        $staff = $this->member('employee', 'Staff');
        $hr = $this->member('hr', 'Hana');
        $type = $this->replacement();

        foreach ([[1, 'Worked 31 Aug'], [0.5, 'Half rest day 7 Sep']] as [$days, $remark]) {
            $this->grantAsHr($hr, [
                'employee_id' => $staff->id, 'leave_type_id' => $type->id,
                'days' => $days, 'remark' => $remark,
            ])->assertRedirect();
        }

        $this->assertSame(2, LeaveGrant::count());
        $this->assertEquals(1.5, (float) LeaveBalance::where('employee_id', $staff->id)
            ->where('leave_type_id', $type->id)->value('balance'));
    }

    /** A negative grant corrects a mis-typed one, and the balance never goes below zero. */
    public function test_a_negative_grant_corrects_a_mistake_and_floors_at_zero(): void
    {
        $staff = $this->member('employee', 'Staff');
        $hr = $this->member('hr', 'Hana');
        $type = $this->replacement();

        $this->grantAsHr($hr, [
            'employee_id' => $staff->id, 'leave_type_id' => $type->id,
            'days' => 1, 'remark' => 'Worked 31 Aug',
        ])->assertRedirect();

        $this->grantAsHr($hr, [
            'employee_id' => $staff->id, 'leave_type_id' => $type->id,
            'days' => -5, 'remark' => 'Typo — was not owed',
        ])->assertRedirect();

        $this->assertEquals(0.0, (float) LeaveBalance::where('employee_id', $staff->id)
            ->where('leave_type_id', $type->id)->value('balance'));
    }

    /** The remark is the point of the grant — it says what the quota was for. */
    public function test_a_grant_without_a_remark_is_rejected(): void
    {
        $staff = $this->member('employee', 'Staff');
        $hr = $this->member('hr', 'Hana');
        $type = $this->replacement();

        $this->grantAsHr($hr, [
            'employee_id' => $staff->id, 'leave_type_id' => $type->id, 'days' => 1,
        ])->assertSessionHasErrors('remark');

        $this->assertSame(0, LeaveGrant::count());
    }

    /** Quota is granted in whole or half days, never a third of one. */
    public function test_a_grant_must_be_a_multiple_of_half_a_day(): void
    {
        $staff = $this->member('employee', 'Staff');
        $hr = $this->member('hr', 'Hana');
        $type = $this->replacement();

        $this->grantAsHr($hr, [
            'employee_id' => $staff->id, 'leave_type_id' => $type->id,
            'days' => 0.3, 'remark' => 'Worked 31 Aug',
        ])->assertSessionHasErrors('days');

        $this->assertSame(0, LeaveGrant::count());
    }

    /**
     * The whole point of the change: staff apply for their own replacement days, and an
     * approval spends the granted quota like any other balance.
     */
    public function test_staff_apply_for_granted_quota_and_approval_spends_it(): void
    {
        $hr = $this->member('hr', 'Hana');
        $director = $this->member('director', 'Dee');
        $manager = $this->member('manager', 'Mala', $director->id);
        $staff = $this->member('employee', 'Staff', $manager->id);
        $type = $this->replacement();

        $this->grantAsHr($hr, [
            'employee_id' => $staff->id, 'leave_type_id' => $type->id,
            'days' => 2, 'remark' => 'Worked 30-31 Aug',
        ])->assertRedirect();

        $this->applyAs($staff, [
            'leave_type_id' => $type->id,
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-07',
            'reason' => 'Rest.',
        ])->assertRedirect();

        $leave = LeaveRequest::where('leave_type_id', $type->id)->sole();
        $this->assertSame('submitted', $leave->status);

        // The quota is not touched until the request is actually approved.
        $this->assertEquals(2.0, (float) LeaveBalance::where('employee_id', $staff->id)
            ->where('leave_type_id', $type->id)->value('balance'));

        $this->actingAs($manager->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post(route('leave.verify', $leave))->assertRedirect();
        $this->actingAs($director->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post(route('leave.approve', $leave))->assertRedirect();

        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertEquals(1.0, (float) LeaveBalance::where('employee_id', $staff->id)
            ->where('leave_type_id', $type->id)->value('balance'));
    }

    /**
     * A granted quota is a hard ceiling. Every other type lets the excess through as
     * unpaid leave; replacement days are days already worked, so there is no such thing
     * as taking more than were earned.
     */
    public function test_applying_for_more_than_the_granted_quota_is_refused(): void
    {
        $hr = $this->member('hr', 'Hana');
        $staff = $this->member('employee', 'Staff');
        $type = $this->replacement();

        $this->grantAsHr($hr, [
            'employee_id' => $staff->id, 'leave_type_id' => $type->id,
            'days' => 1, 'remark' => 'Worked 31 Aug',
        ])->assertRedirect();

        $this->applyAs($staff, [
            'leave_type_id' => $type->id,
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-08',
            'reason' => 'Rest.',
        ])->assertSessionHasErrors('leave_type_id');

        $this->assertSame(0, LeaveRequest::where('leave_type_id', $type->id)->count());
        // Nothing spilled onto another type either.
        $this->assertSame(0, LeaveRequest::where('employee_id', $staff->id)->count());
    }

    /**
     * Days still awaiting a decision are already spoken for. Without this, two pending
     * applications could each pass the check and together overdraw the quota.
     */
    public function test_pending_days_count_against_the_quota(): void
    {
        $hr = $this->member('hr', 'Hana');
        $staff = $this->member('employee', 'Staff');
        $type = $this->replacement();

        $this->grantAsHr($hr, [
            'employee_id' => $staff->id, 'leave_type_id' => $type->id,
            'days' => 1, 'remark' => 'Worked 31 Aug',
        ])->assertRedirect();

        $this->applyAs($staff, [
            'leave_type_id' => $type->id,
            'date_from' => '2026-09-07', 'date_to' => '2026-09-07', 'reason' => 'Rest.',
        ])->assertRedirect();

        $this->applyAs($staff, [
            'leave_type_id' => $type->id,
            'date_from' => '2026-09-08', 'date_to' => '2026-09-08', 'reason' => 'Rest again.',
        ])->assertSessionHasErrors('leave_type_id');

        $this->assertSame(1, LeaveRequest::where('leave_type_id', $type->id)->count());
    }

    /** Half a granted day is spendable, the same 0.5 every other type uses. */
    public function test_staff_can_apply_for_half_a_granted_day(): void
    {
        $hr = $this->member('hr', 'Hana');
        $staff = $this->member('employee', 'Staff');
        $type = $this->replacement();

        $this->grantAsHr($hr, [
            'employee_id' => $staff->id, 'leave_type_id' => $type->id,
            'days' => 0.5, 'remark' => 'Half rest day',
        ])->assertRedirect();

        $this->applyAs($staff, [
            'leave_type_id' => $type->id,
            'date_from' => '2026-09-07', 'date_to' => '2026-09-07',
            'half_day_period' => 'am', 'reason' => 'Appointment.',
        ])->assertRedirect();

        $leave = LeaveRequest::where('leave_type_id', $type->id)->sole();
        $this->assertEquals(0.5, (float) $leave->days);
    }

    /**
     * No quota granted means no balance row, and that is exactly what "this type is not
     * yours to take" looks like for every other quota-carrying type.
     */
    public function test_applying_without_any_granted_quota_is_refused(): void
    {
        $staff = $this->member('employee', 'Staff');
        $type = $this->replacement();

        $this->applyAs($staff, [
            'leave_type_id' => $type->id,
            'date_from' => '2026-09-07', 'date_to' => '2026-09-07', 'reason' => 'Rest.',
        ])->assertSessionHasErrors('leave_type_id');

        $this->assertSame(0, LeaveRequest::where('leave_type_id', $type->id)->count());
    }

    /** Cancelling an approved replacement hands the granted days back. */
    public function test_cancelling_an_approved_replacement_refunds_the_quota(): void
    {
        $hr = $this->member('hr', 'Hana');
        $director = $this->member('director', 'Dee');
        $manager = $this->member('manager', 'Mala', $director->id);
        $staff = $this->member('employee', 'Staff', $manager->id);
        $type = $this->replacement();

        $this->grantAsHr($hr, [
            'employee_id' => $staff->id, 'leave_type_id' => $type->id,
            'days' => 1, 'remark' => 'Worked 31 Aug',
        ])->assertRedirect();

        $this->applyAs($staff, [
            'leave_type_id' => $type->id,
            'date_from' => now()->addDays(10)->toDateString(),
            'date_to' => now()->addDays(10)->toDateString(),
            'reason' => 'Rest.',
        ])->assertRedirect();

        $leave = LeaveRequest::where('leave_type_id', $type->id)->sole();
        $this->actingAs($manager->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post(route('leave.verify', $leave))->assertRedirect();
        $this->actingAs($director->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post(route('leave.approve', $leave))->assertRedirect();

        $this->assertEquals(0.0, (float) LeaveBalance::where('employee_id', $staff->id)
            ->where('leave_type_id', $type->id)->value('balance'));

        $this->actingAs($staff->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post(route('leave.cancel', $leave))->assertRedirect();

        $this->assertSame('cancelled', $leave->fresh()->status);
        $this->assertEquals(1.0, (float) LeaveBalance::where('employee_id', $staff->id)
            ->where('leave_type_id', $type->id)->value('balance'));
    }

    /** Granting quota is an HR/management power — an ordinary employee cannot reach it. */
    public function test_an_employee_cannot_grant_quota(): void
    {
        $staff = $this->member('employee', 'Staff');
        $other = $this->member('employee', 'Other');
        $type = $this->replacement();

        $this->actingAs($staff->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->post(route('leave.grant'), [
                'employee_id' => $other->id,
                'leave_type_id' => $type->id,
                'days' => 5,
                'remark' => 'Because I said so',
            ])
            ->assertForbidden();

        $this->assertSame(0, LeaveGrant::count());
    }

    /**
     * Quota is only granted for types that carry no yearly entitlement. Granting Annual
     * this way would hand out entitlement the Leave Setup grid knows nothing about.
     */
    public function test_hr_cannot_grant_quota_of_an_ordinary_leave_type(): void
    {
        $staff = $this->member('employee', 'Staff');
        $hr = $this->member('hr', 'Hana');

        $this->grantAsHr($hr, [
            'employee_id' => $staff->id,
            'leave_type_id' => $this->annual->id,
            'days' => 5,
            'remark' => 'Bonus days',
        ])->assertStatus(422);

        $this->assertSame(0, LeaveGrant::count());
    }
}
