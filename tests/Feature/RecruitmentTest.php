<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Employee;
use App\Models\JobRequisition;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Recruitment / ATS module.
 * Harness (setUp / actingInTenant / hrActor) copied from CoreWritePathsTest.
 */
class RecruitmentTest extends TestCase
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

    // ── Requisitions ──────────────────────────────────────────────

    public function test_privileged_user_opens_a_requisition(): void
    {
        // Act
        $response = $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/recruitment/requisitions', [
                'title' => 'Senior Backend Engineer',
                'openings' => 2,
                'location' => 'KL HQ',
            ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('job_requisitions', [
            'tenant_id' => $this->tenant->id,
            'title' => 'Senior Backend Engineer',
            'openings' => 2,
            'status' => 'open',
        ]);
    }

    public function test_plain_employee_cannot_open_a_requisition(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/recruitment/requisitions', [
            'title' => 'Sneaky Role',
            'openings' => 1,
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('job_requisitions', ['title' => 'Sneaky Role']);
    }

    public function test_open_requisition_requires_a_title(): void
    {
        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/recruitment/requisitions', ['title' => '', 'openings' => 1])
            ->assertSessionHasErrors('title');
    }

    // ── Candidates ────────────────────────────────────────────────

    public function test_privileged_user_adds_a_candidate(): void
    {
        // Arrange
        $req = JobRequisition::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Designer', 'openings' => 1, 'status' => 'open',
        ]);

        // Act
        $response = $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/recruitment/{$req->id}/candidates", [
                'name' => 'Farah Idris',
                'email' => 'farah@example.com',
            ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('candidates', [
            'tenant_id' => $this->tenant->id,
            'job_requisition_id' => $req->id,
            'name' => 'Farah Idris',
            'stage' => 'applied',
        ]);
    }

    public function test_plain_employee_cannot_add_a_candidate(): void
    {
        // Arrange
        $req = JobRequisition::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Designer', 'openings' => 1, 'status' => 'open',
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/recruitment/{$req->id}/candidates", [
            'name' => 'Sneaky Candidate',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('candidates', ['name' => 'Sneaky Candidate']);
    }

    public function test_privileged_user_moves_a_candidate_stage(): void
    {
        // Arrange
        $req = JobRequisition::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Designer', 'openings' => 1, 'status' => 'open',
        ]);
        $candidate = Candidate::create([
            'tenant_id' => $this->tenant->id,
            'job_requisition_id' => $req->id,
            'name' => 'Daniel Tan', 'stage' => 'applied',
        ]);

        // Act
        $response = $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/recruitment/candidates/{$candidate->id}/move", [
                'stage' => 'interview',
                'notes' => 'Technical round scheduled.',
            ]);

        // Assert
        $response->assertRedirect();
        $fresh = $candidate->fresh();
        $this->assertSame('interview', $fresh->stage);
        $this->assertSame('Technical round scheduled.', $fresh->notes);
    }

    public function test_plain_employee_cannot_move_a_candidate(): void
    {
        // Arrange
        $req = JobRequisition::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Designer', 'openings' => 1, 'status' => 'open',
        ]);
        $candidate = Candidate::create([
            'tenant_id' => $this->tenant->id,
            'job_requisition_id' => $req->id,
            'name' => 'Daniel Tan', 'stage' => 'applied',
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/recruitment/candidates/{$candidate->id}/move", [
            'stage' => 'hired',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertSame('applied', $candidate->fresh()->stage);
    }

    public function test_move_rejects_an_invalid_stage(): void
    {
        // Arrange
        $req = JobRequisition::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Designer', 'openings' => 1, 'status' => 'open',
        ]);
        $candidate = Candidate::create([
            'tenant_id' => $this->tenant->id,
            'job_requisition_id' => $req->id,
            'name' => 'Daniel Tan', 'stage' => 'applied',
        ]);

        // Act + Assert
        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/recruitment/candidates/{$candidate->id}/move", ['stage' => 'promoted'])
            ->assertSessionHasErrors('stage');
        $this->assertSame('applied', $candidate->fresh()->stage);
    }
}
