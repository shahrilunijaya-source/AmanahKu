<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Idea;
use App\Models\IdeaVote;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Suggestion Box (ideas) module.
 * Harness (setUp / actingInTenant / hrActor) copied from CoreWritePathsTest.
 */
class IdeaTest extends TestCase
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

    private function makeIdea(?int $authorId = null): Idea
    {
        return Idea::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $authorId ?? $this->employee->id,
            'title' => 'Hybrid Fridays',
            'body' => 'Let teams work from home on Fridays.',
            'category' => 'Workplace',
            'status' => 'new',
        ]);
    }

    // ── Submit ────────────────────────────────────────────────────

    public function test_employee_submits_an_idea(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/ideas', [
            'title' => 'Quarterly skills swap',
            'body' => 'Lunch-and-learns where staff teach a skill.',
            'category' => 'Process',
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('ideas', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'title' => 'Quarterly skills swap',
            'category' => 'Process',
            'status' => 'new',
        ]);
    }

    // ── Vote ──────────────────────────────────────────────────────

    public function test_employee_votes_on_an_idea(): void
    {
        // Arrange
        $idea = $this->makeIdea();

        // Act
        $response = $this->actingInTenant()->post("/app/ideas/{$idea->id}/vote");

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('idea_votes', [
            'idea_id' => $idea->id,
            'employee_id' => $this->employee->id,
        ]);
        $this->assertSame(1, IdeaVote::where('idea_id', $idea->id)->count());
    }

    public function test_voting_again_toggles_off_and_does_not_duplicate(): void
    {
        // Arrange — employee already has a vote on the idea.
        $idea = $this->makeIdea();
        IdeaVote::create([
            'tenant_id' => $this->tenant->id,
            'idea_id' => $idea->id,
            'employee_id' => $this->employee->id,
        ]);

        // Act — voting again toggles the vote off.
        $response = $this->actingInTenant()->post("/app/ideas/{$idea->id}/vote");

        // Assert — no duplicate row; the toggle removed the vote (unique constraint respected).
        $response->assertRedirect();
        $this->assertSame(0, IdeaVote::where('idea_id', $idea->id)
            ->where('employee_id', $this->employee->id)->count());
        $this->assertDatabaseMissing('idea_votes', [
            'idea_id' => $idea->id,
            'employee_id' => $this->employee->id,
        ]);
    }

    // ── Triage ────────────────────────────────────────────────────

    public function test_privileged_user_sets_idea_status(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $idea = $this->makeIdea();

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/ideas/{$idea->id}/status", ['status' => 'accepted']);

        // Assert
        $response->assertRedirect();
        $this->assertSame('accepted', $idea->fresh()->status);
    }

    public function test_plain_employee_cannot_set_idea_status(): void
    {
        // Arrange
        $idea = $this->makeIdea();

        // Act
        $response = $this->actingInTenant()->post("/app/ideas/{$idea->id}/status", ['status' => 'declined']);

        // Assert
        $response->assertForbidden();
        $this->assertSame('new', $idea->fresh()->status);
    }
}
