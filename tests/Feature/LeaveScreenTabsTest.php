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
     * The pills show every settled request from the people the viewer can act on, not
     * only the ones they decided themselves.
     */
    public function test_the_approvals_tab_counts_every_settled_request_in_reach(): void
    {
        $management = $this->member('management', 'Director');
        $other = $this->member('management', 'Other Director');
        $manager = $this->member('manager', 'Manager', $management->id);
        $staff = $this->member('employee', 'Staff', $manager->id);

        // Approved by somebody else still counts: the viewer sees every approval.
        $this->submittedRequestFor($staff)->update([
            'status' => 'approved', 'approved_by_id' => $other->id, 'approved_at' => now(),
        ]);
        $this->submittedRequestFor($staff)->update([
            'status' => 'rejected', 'rejected_by_id' => $management->id, 'rejected_at' => now(),
        ]);
        // Withdrawn before anyone acted still lands on the Cancelled pill.
        $this->submittedRequestFor($staff)->update(['status' => 'cancelled']);
        $this->submittedRequestFor($staff)->update([
            'status' => 'verified', 'verified_by_id' => $manager->id, 'verified_at' => now(),
        ]);

        $this->screenAs($management)->assertOk()
            ->assertViewHas('leaveApproved', fn ($c) => $c->count() === 1)
            ->assertViewHas('leaveRejected', fn ($c) => $c->count() === 1)
            ->assertViewHas('leaveCancelled', fn ($c) => $c->count() === 1)
            ->assertViewHas('leaveToApprove', fn ($c) => $c->count() === 1)
            ->assertSee('Cancelled');
    }

    /**
     * The tab used to exist only while something was pending, which would have taken the
     * settled lists off the screen the moment a manager cleared their queue.
     */
    public function test_an_approver_with_an_empty_queue_still_gets_the_approvals_tab(): void
    {
        $management = $this->member('management', 'Director');
        $manager = $this->member('manager', 'Manager', $management->id);
        $staff = $this->member('employee', 'Staff', $manager->id);

        $this->submittedRequestFor($staff)->update([
            'status' => 'approved', 'approved_by_id' => $management->id, 'approved_at' => now(),
        ]);

        $this->screenAs($management)->assertOk()
            ->assertViewHas('leaveApproved', fn ($c) => $c->count() === 1)
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
     * A plain manager sees their own reports' settled requests, whoever decided them, and
     * nobody else's. The viewer's own requests never appear on their review screen.
     */
    public function test_a_plain_manager_sees_their_reports_but_not_other_teams_or_themselves(): void
    {
        $management = $this->member('management', 'Director');
        $manager = $this->member('manager', 'Manager', $management->id);
        $staff = $this->member('employee', 'Staff', $manager->id);
        $otherManager = $this->member('manager', 'Other Manager', $management->id);
        $stranger = $this->member('employee', 'Stranger', $otherManager->id);

        foreach ([$staff, $stranger, $manager] as $who) {
            $this->submittedRequestFor($who)->update([
                'status' => 'approved', 'approved_by_id' => $management->id, 'approved_at' => now(),
            ]);
        }

        $this->screenAs($manager)->assertOk()
            ->assertViewHas('leaveApproved', fn ($c) => $c->pluck('employee_id')->all() === [$staff->id]);

        // The director reaches the whole tenant.
        $this->screenAs($management)->assertOk()
            ->assertViewHas('leaveApproved', fn ($c) => $c->count() === 3);
    }

    /**
     * "Apply period" keeps a leave whose days overlap the range at all, "All" drops the
     * date filter, and the search matches the requester's name.
     */
    public function test_the_filter_bar_narrows_by_apply_period_and_search(): void
    {
        $management = $this->member('management', 'Director');
        $staff = $this->member('employee', 'Aminah', $management->id);
        $other = $this->member('employee', 'Badrul', $management->id);

        $staff->leaveRequests()->create([
            'tenant_id' => $this->tenant->id, 'leave_type_id' => $this->annual->id,
            'date_from' => '2026-06-29', 'date_to' => '2026-07-02', 'days' => 4, 'status' => 'approved',
        ]);
        $other->leaveRequests()->create([
            'tenant_id' => $this->tenant->id, 'leave_type_id' => $this->annual->id,
            'date_from' => '2026-08-10', 'date_to' => '2026-08-10', 'days' => 1, 'status' => 'approved',
        ]);

        $this->screenAs($management, '?tab=approvals&period=range&from=2026-07-01&to=2026-07-31')->assertOk()
            ->assertViewHas('leaveApproved', fn ($c) => $c->pluck('employee_id')->all() === [$staff->id]);

        // "All" ignores the dates it was sent.
        $this->screenAs($management, '?tab=approvals&period=all&from=2026-07-01&to=2026-07-31')->assertOk()
            ->assertViewHas('leaveApproved', fn ($c) => $c->count() === 2);

        $this->screenAs($management, '?tab=approvals&q=badr')->assertOk()
            ->assertViewHas('leaveApproved', fn ($c) => $c->pluck('employee_id')->all() === [$other->id]);
    }

    /** Someone with two or more requests gets a header band with a total; a one-off does not. */
    public function test_repeat_requesters_are_grouped_under_a_band(): void
    {
        $management = $this->member('management', 'Director');
        $repeat = $this->member('employee', 'Repeat Person', $management->id);
        $once = $this->member('employee', 'Once Person', $management->id);

        $this->submittedRequestFor($repeat)->update(['status' => 'approved']);
        $this->submittedRequestFor($repeat)->update(['status' => 'approved']);
        $this->submittedRequestFor($once)->update(['status' => 'approved']);

        $html = $this->screenAs($management)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="uj-ap-band"'));
        $this->assertStringContainsString('6 days', $html);
        $this->assertStringContainsString('Requester', $html);
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

    /**
     * A mistyped grant is corrected in place and the balance follows the difference.
     */
    public function test_hr_edits_a_grant_and_the_balance_follows(): void
    {
        $staff = $this->member('employee', 'Staff');
        $hr = $this->member('hr', 'Hana');
        $type = $this->replacement();

        $this->grantAsHr($hr, [
            'employee_id' => $staff->id, 'leave_type_id' => $type->id,
            'days' => 2, 'remark' => 'Worked 30 Aug',
        ])->assertRedirect();

        $this->editGrantAsHr($hr, LeaveGrant::sole(), [
            'days' => 0.5, 'remark' => 'Worked half of 30 Aug',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $grant = LeaveGrant::sole();
        $this->assertEquals(0.5, (float) $grant->days);
        $this->assertSame('Worked half of 30 Aug', $grant->remark);
        $this->assertEquals(0.5, (float) LeaveBalance::where('employee_id', $staff->id)
            ->where('leave_type_id', $type->id)->value('balance'));
    }

    /**
     * Days already taken cannot be taken back: an edit that would drive the balance below
     * zero is refused whole, leaving both the grant and the balance untouched.
     */
    public function test_an_edit_below_what_is_already_taken_is_refused(): void
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
            'date_from' => '2026-09-07', 'date_to' => '2026-09-07', 'reason' => 'Rest.',
        ])->assertRedirect();

        $leave = LeaveRequest::where('leave_type_id', $type->id)->sole();
        $this->actingAs($manager->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post(route('leave.verify', $leave))->assertRedirect();
        $this->actingAs($director->user)->withSession(['current_tenant' => $this->tenant->id])
            ->post(route('leave.approve', $leave))->assertRedirect();

        // One of the two days is spent, so the grant cannot drop below one day.
        $this->editGrantAsHr($hr, LeaveGrant::sole(), [
            'days' => 0.5, 'remark' => 'Too far',
        ])->assertSessionHasErrors('days');

        $this->assertEquals(2.0, (float) LeaveGrant::sole()->days);
        $this->assertEquals(1.0, (float) LeaveBalance::where('employee_id', $staff->id)
            ->where('leave_type_id', $type->id)->value('balance'));
    }

    /** Editing a grant is HR's job, not the staff member's. */
    public function test_an_employee_cannot_edit_a_grant(): void
    {
        $hr = $this->member('hr', 'Hana');
        $staff = $this->member('employee', 'Staff');
        $type = $this->replacement();

        $this->grantAsHr($hr, [
            'employee_id' => $staff->id, 'leave_type_id' => $type->id,
            'days' => 1, 'remark' => 'Worked 30 Aug',
        ])->assertRedirect();

        $this->editGrantAsHr($staff, LeaveGrant::sole(), [
            'days' => 9, 'remark' => 'Mine now',
        ])->assertForbidden();

        $this->assertEquals(1.0, (float) LeaveGrant::sole()->days);
    }

    private function editGrantAsHr(Employee $hr, LeaveGrant $grant, array $payload)
    {
        return $this->actingAs($hr->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->from('/app/leave-setup')
            ->patch(route('leave.grant.update', $grant->id), $payload);
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
     * A replacement day is taken whenever it suits, including tomorrow, so a granted type
     * must carry no notice period. The live data had three days on it from when HR booked
     * the day itself; the leave_grants migration clears it.
     */
    public function test_a_granted_type_can_be_applied_for_tomorrow(): void
    {
        $hr = $this->member('hr', 'Hana');
        $staff = $this->member('employee', 'Staff');
        $type = $this->replacement();

        $this->assertSame(0, (int) $type->min_notice_days);

        $this->grantAsHr($hr, [
            'employee_id' => $staff->id, 'leave_type_id' => $type->id,
            'days' => 1, 'remark' => 'Worked yesterday',
        ])->assertRedirect();

        $tomorrow = now()->addDay()->toDateString();

        $this->applyAs($staff, [
            'leave_type_id' => $type->id,
            'date_from' => $tomorrow, 'date_to' => $tomorrow, 'reason' => 'Rest.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('submitted', LeaveRequest::where('leave_type_id', $type->id)
            ->sole()->status);
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

        // A working day, whatever weekday the suite runs on: a weekend date counts
        // as zero days and there would be nothing to spend or refund.
        $day = now()->addDays(10)->nextWeekday()->toDateString();
        $this->applyAs($staff, [
            'leave_type_id' => $type->id,
            'date_from' => $day,
            'date_to' => $day,
            'reason' => 'Rest.',
        ])->assertRedirect();

        $leave = LeaveRequest::where('leave_type_id', $type->id)->sole();
        $this->assertEquals(1.0, (float) $leave->days);
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

    /**
     * Emergency leave must never be hard-blocked by the apply form for running out of
     * balance, whether it spends Annual's quota (deducts_from_leave_type_id) or, as here,
     * carries its own zero-balance row. The Alpine overflows() check used to require both
     * `unplanned` and `deducts`, so a standalone-balance Emergency type fell through to the
     * same "shorten your dates" hard block as Annual.
     */
    public function test_emergency_with_its_own_zero_balance_is_not_hard_blocked_client_side(): void
    {
        $emergency = LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Emergency', 'entitlement' => 0,
            'is_unplanned' => true,
        ]);
        $staff = $this->member('employee', 'Staff');
        LeaveBalance::create([
            'employee_id' => $staff->id, 'leave_type_id' => $emergency->id, 'balance' => 0,
        ]);

        // @js() hex-escapes quotes (") so the JSON survives inside the HTML attribute.
        // @js() hex-escapes quotes as a literal " so the JSON survives inside the HTML attribute.
        $needle = '\\u0022unplanned\\u0022:true,\\u0022doc\\u0022:false,\\u0022name\\u0022:\\u0022Emergency\\u0022,\\u0022deducts\\u0022:null';
        $this->screenAs($staff)->assertOk()
            ->assertSee($needle, false)
            ->assertSee('overflows() { const m = this.t(); return !!(m && m.unplanned); }', false);
    }
}
