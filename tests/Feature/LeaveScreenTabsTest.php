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
}
