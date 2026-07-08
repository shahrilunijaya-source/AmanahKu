<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Goal;
use App\Models\KeyResult;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Goals / OKRs module.
 * Harness (setUp / actingInTenant / hrActor) copied from CoreWritePathsTest.
 */
class GoalTest extends TestCase
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

    private function hrActor(): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $hr->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $hr;
    }

    // ── Create a goal ─────────────────────────────────────────────

    public function test_employee_creates_a_goal_for_themselves(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/goals', [
            'title' => 'Ship the OKR module',
            'description' => 'End-to-end goals tracking.',
            'category' => 'delivery',
            'period' => '2026 H1',
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('goals', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'title' => 'Ship the OKR module',
            'status' => 'active',
        ]);
    }

    public function test_create_goal_requires_title_and_period(): void
    {
        $this->actingInTenant()->post('/app/goals', ['title' => '', 'period' => ''])
            ->assertSessionHasErrors(['title', 'period']);
    }

    // ── Add a key result ──────────────────────────────────────────

    public function test_owner_adds_a_key_result_to_own_goal(): void
    {
        // Arrange
        $goal = Goal::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'Lead the release', 'period' => '2026 H1', 'status' => 'active',
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/goals/{$goal->id}/key-results", [
            'title' => 'Close all P1 bugs',
            'target_label' => '0 open P1',
            'progress' => 40,
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('key_results', [
            'goal_id' => $goal->id,
            'employee_id' => $this->employee->id,
            'tenant_id' => $this->tenant->id,
            'title' => 'Close all P1 bugs',
            'progress' => 40,
        ]);
    }

    // ── Update key-result progress ────────────────────────────────

    public function test_owner_updates_key_result_progress(): void
    {
        // Arrange
        $goal = Goal::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'Lead the release', 'period' => '2026 H1', 'status' => 'active',
        ]);
        $kr = $goal->keyResults()->create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'Close P1 bugs', 'progress' => 10,
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/goals/key-results/{$kr->id}/progress", [
            'progress' => 75,
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertSame(75, (int) $kr->fresh()->progress);
    }

    public function test_goal_is_marked_achieved_when_all_key_results_reach_100(): void
    {
        // Arrange — one key result at 100 already, the last one updated to 100.
        $goal = Goal::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'Finish strong', 'period' => '2026 H1', 'status' => 'active',
        ]);
        $goal->keyResults()->create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'KR one', 'progress' => 100,
        ]);
        $last = $goal->keyResults()->create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'KR two', 'progress' => 60,
        ]);

        // Act
        $this->actingInTenant()->post("/app/goals/key-results/{$last->id}/progress", ['progress' => 100])
            ->assertRedirect();

        // Assert
        $this->assertSame('achieved', $goal->fresh()->status);
    }

    // ── Ownership gate ────────────────────────────────────────────

    public function test_employee_cannot_update_a_key_result_on_another_employees_goal(): void
    {
        // Arrange — a colleague's goal + key result in the same tenant.
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);
        $goal = Goal::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $colleague->id,
            'title' => 'Their goal', 'period' => '2026 H1', 'status' => 'active',
        ]);
        $kr = $goal->keyResults()->create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $colleague->id,
            'title' => 'Their KR', 'progress' => 20,
        ]);

        // Act — the demo employee tries to update the colleague's key result.
        $response = $this->actingInTenant()->post("/app/goals/key-results/{$kr->id}/progress", [
            'progress' => 99,
        ]);

        // Assert — forbidden and unchanged.
        $response->assertForbidden();
        $this->assertSame(20, (int) $kr->fresh()->progress);
    }

    public function test_employee_cannot_add_a_key_result_to_another_employees_goal(): void
    {
        // Arrange
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);
        $goal = Goal::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $colleague->id,
            'title' => 'Their goal', 'period' => '2026 H1', 'status' => 'active',
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/goals/{$goal->id}/key-results", [
            'title' => 'Sneaky KR', 'progress' => 50,
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('key_results', ['title' => 'Sneaky KR']);
    }
}
