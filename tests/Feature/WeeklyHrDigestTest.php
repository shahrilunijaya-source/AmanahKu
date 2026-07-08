<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\ComplianceItem;
use App\Models\Employee;
use App\Models\ExpenseReport;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\ProbationReview;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\WeeklyHrDigest;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Coverage for the digest:weekly command + WeeklyHrDigest notification.
 *
 * Seeds one tenant with pending approvals, a new joiner, an upcoming probation
 * decision and a soon-to-expire compliance item, plus HR/management/manager/
 * employee users. Asserts the digest is queued only to the HR + management
 * users, with the correct tenant-scoped counts. Time is pinned with
 * Carbon::setTestNow and cleared in tearDown.
 */
class WeeklyHrDigestTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hrUser;

    private User $managementUser;

    private User $managerUser;

    private User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-24 09:00:00');

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'initials' => 'AC', 'color' => '#123456']);

        app(CurrentTenant::class)->set($this->tenant);

        $this->hrUser = $this->userWithRole('hr', 'hr@acme.test');
        $this->managementUser = $this->userWithRole('management', 'mgmt@acme.test');
        $this->managerUser = $this->userWithRole('manager', 'manager@acme.test');
        $this->employeeUser = $this->userWithRole('employee', 'emp@acme.test');

        // Employee on whom the pending items hang.
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green',
        ]);

        // 2 pending leave (submitted) + 1 already-approved (must NOT count).
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 18]);
        foreach (['submitted', 'submitted', 'approved'] as $status) {
            LeaveRequest::create([
                'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
                'date_from' => '2026-07-01', 'date_to' => '2026-07-02', 'days' => 2, 'status' => $status,
            ]);
        }

        // 1 pending claim.
        Claim::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'type' => 'travel', 'title' => 'Taxi', 'amount' => 100, 'date' => '2026-06-21', 'status' => 'submitted',
        ]);

        // 1 pending expense report.
        ExpenseReport::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'title' => 'Trip', 'status' => 'submitted', 'total' => 0,
        ]);

        // 1 pending overtime.
        OvertimeRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'ot_date' => '2026-06-20', 'hours' => 3, 'rate_multiplier' => 1.5,
            'reason' => 'release', 'status' => 'submitted',
        ]);

        // New joiner within the last 7 days.
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Fresh Hire', 'status' => 'active',
            'workload' => 'green', 'joined_at' => '2026-06-20',
        ]);
        // Old joiner — must NOT count.
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Old Hand', 'status' => 'active',
            'workload' => 'green', 'joined_at' => '2026-01-01',
        ]);

        // Probation review ending in 10 days (within 30) — counts. Plus one ending
        // in 60 days (out of horizon) — must NOT count.
        ProbationReview::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id, 'status' => 'active',
            'start_date' => '2026-04-04', 'end_date' => '2026-07-04', 'length_days' => 90,
        ]);
        ProbationReview::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id, 'status' => 'active',
            'start_date' => '2026-05-25', 'end_date' => '2026-08-23', 'length_days' => 90,
        ]);

        // Compliance expiring in 20 days — counts. Plus one expiring in 120 days — must NOT.
        ComplianceItem::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'type' => 'license', 'name' => 'Permit', 'expires_at' => '2026-07-14',
        ]);
        ComplianceItem::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'type' => 'license', 'name' => 'Far Permit', 'expires_at' => '2026-12-01',
        ]);

        app(CurrentTenant::class)->set(null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);

        parent::tearDown();
    }

    private function userWithRole(string $role, string $email): User
    {
        $user = User::create(['name' => ucfirst($role), 'email' => $email, 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return $user;
    }

    public function test_digest_is_queued_to_hr_and_management_only(): void
    {
        Notification::fake();

        $this->artisan('digest:weekly')->assertSuccessful();

        Notification::assertSentTo($this->hrUser, WeeklyHrDigest::class);
        Notification::assertSentTo($this->managementUser, WeeklyHrDigest::class);
        Notification::assertNotSentTo($this->managerUser, WeeklyHrDigest::class);
        Notification::assertNotSentTo($this->employeeUser, WeeklyHrDigest::class);
    }

    public function test_digest_carries_correct_tenant_scoped_counts(): void
    {
        Notification::fake();

        $this->artisan('digest:weekly')->assertSuccessful();

        Notification::assertSentTo($this->hrUser, WeeklyHrDigest::class, function (WeeklyHrDigest $n) {
            $s = $n->summary();

            return $s['pending']['leave'] === 2
                && $s['pending']['claims'] === 1
                && $s['pending']['expenses'] === 1
                && $s['pending']['overtime'] === 1
                && $s['newJoiners'] === 1
                && $s['probationDecisions'] === 1
                && $s['complianceExpiries'] === 1;
        });
    }

    public function test_digest_queues_as_mail_and_implements_should_queue(): void
    {
        Notification::fake();

        $this->artisan('digest:weekly')->assertSuccessful();

        Notification::assertSentTo($this->hrUser, WeeklyHrDigest::class, function (WeeklyHrDigest $n, array $channels) {
            return in_array('mail', $channels, true)
                && $n instanceof \Illuminate\Contracts\Queue\ShouldQueue;
        });
    }

    public function test_counts_do_not_leak_across_tenants(): void
    {
        // A second tenant with its own HR user and its own pending leave. The
        // first tenant's digest must not count the second tenant's items.
        $other = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'initials' => 'BE', 'color' => '#654321']);
        app(CurrentTenant::class)->set($other);
        $otherHr = User::create(['name' => 'OtherHr', 'email' => 'otherhr@beta.test', 'password' => Hash::make('password')]);
        $otherHr->tenants()->attach($other->id, ['role' => 'hr']);
        $otherEmp = Employee::create(['tenant_id' => $other->id, 'name' => 'B', 'status' => 'active', 'workload' => 'green']);
        $otherType = LeaveType::create(['tenant_id' => $other->id, 'name' => 'Annual', 'entitlement' => 18]);
        LeaveRequest::create([
            'tenant_id' => $other->id, 'employee_id' => $otherEmp->id, 'leave_type_id' => $otherType->id,
            'date_from' => '2026-07-01', 'date_to' => '2026-07-02', 'days' => 2, 'status' => 'submitted',
        ]);
        app(CurrentTenant::class)->set(null);

        Notification::fake();
        $this->artisan('digest:weekly')->assertSuccessful();

        // Acme HR still sees only Acme's 2 pending leave.
        Notification::assertSentTo($this->hrUser, WeeklyHrDigest::class, fn (WeeklyHrDigest $n) => $n->summary()['pending']['leave'] === 2);
        // Beta HR sees only Beta's 1.
        Notification::assertSentTo($otherHr, WeeklyHrDigest::class, fn (WeeklyHrDigest $n) => $n->summary()['pending']['leave'] === 1);
    }
}
