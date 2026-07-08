<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AI Workforce Intelligence (workload) screen — the "Apply" action on each real
 * recommendation sends an in-app nudge (never an automatic data mutation) to the
 * right people, and is gated to privileged roles.
 */
class WorkforceInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        // The manager actor's own employee is green → satisfies the "available peer"
        // condition so the rebalance recommendation can surface in other tests.
        $this->manager = $this->userWithRole('manager', 'mgr@example.com');
    }

    private function userWithRole(string $role, string $email, string $workload = 'green'): User
    {
        $u = User::create(['name' => ucfirst($role), 'email' => $email, 'password' => Hash::make('password')]);
        $u->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => $workload,
        ]);

        return $u;
    }

    private function acting(User $u): self
    {
        $this->actingAs($u)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function apply(string $type)
    {
        return $this->acting($this->manager)->post('/app/workload/apply', ['type' => $type]);
    }

    // ── Timesheet reminder ────────────────────────────────────────

    public function test_apply_timesheet_nudges_staff_with_no_timesheet(): void
    {
        // Arrange — an active employee who has filed no timesheet this week is "pending".
        $pending = User::create(['name' => 'Pending', 'email' => 'p@example.com', 'password' => Hash::make('x')]);
        $pending->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $pending->id,
            'name' => 'Pending', 'status' => 'active', 'workload' => 'green',
        ]);

        // Act
        $this->apply('timesheet')->assertRedirect();

        // Assert
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $pending->id, 'title' => 'Timesheet reminder',
        ]);
    }

    // ── Rebalance ─────────────────────────────────────────────────

    public function test_apply_rebalance_nudges_overloaded_and_their_manager(): void
    {
        // Arrange — a person reporting to a manager, made LIVE-overloaded by carrying more open
        // work items than the overloaded threshold (workload is derived from real load now, not
        // a stored column).
        $bossUser = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('x')]);
        $boss = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $bossUser->id,
            'name' => 'Boss', 'status' => 'active',
        ]);
        $overUser = User::create(['name' => 'Over', 'email' => 'over@example.com', 'password' => Hash::make('x')]);
        $over = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $overUser->id, 'reports_to_id' => $boss->id,
            'name' => 'Over', 'status' => 'active', 'kpi_pct' => 95,
        ]);
        for ($i = 0; $i < Employee::WORKLOAD_OVERLOADED_FROM; $i++) {
            WorkItem::create([
                'tenant_id' => $this->tenant->id, 'employee_id' => $over->id,
                'title' => 'Load '.$i, 'type' => 'task', 'status' => 'todo',
            ]);
        }

        // Act
        $this->apply('rebalance')->assertRedirect();

        // Assert — both the overloaded person and their manager are nudged.
        $this->assertDatabaseHas('app_notifications', ['user_id' => $overUser->id, 'title' => 'Workload review']);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $bossUser->id, 'title' => 'Workload review']);
    }

    // ── Escalate overdue ──────────────────────────────────────────

    public function test_apply_overdue_nudges_the_item_owners_manager(): void
    {
        // Arrange — an overdue work item owned by a person reporting to a manager.
        $bossUser = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('x')]);
        $boss = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $bossUser->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);
        $owner = Employee::create([
            'tenant_id' => $this->tenant->id, 'reports_to_id' => $boss->id,
            'name' => 'Owner', 'status' => 'active', 'workload' => 'amber',
        ]);
        WorkItem::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $owner->id,
            'title' => 'Late task', 'type' => 'task', 'status' => 'todo',
            'priority' => 'high', 'due_at' => now()->subDays(3),
        ]);

        // Act
        $this->apply('overdue')->assertRedirect();

        // Assert
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $bossUser->id, 'title' => 'Overdue work on your team',
        ]);
    }

    // ── Guards ────────────────────────────────────────────────────

    public function test_plain_employee_cannot_apply(): void
    {
        $emp = $this->userWithRole('employee', 'emp@example.com');

        $this->acting($emp)->post('/app/workload/apply', ['type' => 'timesheet'])
            ->assertForbidden();
    }

    public function test_invalid_recommendation_type_is_rejected(): void
    {
        $this->acting($this->manager)->post('/app/workload/apply', ['type' => 'bogus'])
            ->assertSessionHasErrors('type');
    }
}
