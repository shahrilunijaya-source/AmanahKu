<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TravelRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Travel & business trips module.
 * Harness (setUp / actingInTenant / hrActor) copied from CoreWritePathsTest.
 */
class TravelTest extends TestCase
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

    public function test_employee_submits_a_travel_request(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/travel', [
            'destination' => 'Kuala Lumpur — Client HQ',
            'purpose' => 'Requirements workshop with the client.',
            'depart_date' => now()->addDays(5)->toDateString(),
            'return_date' => now()->addDays(7)->toDateString(),
            'transport' => 'flight',
            'estimated_cost' => 1850.00,
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('travel_requests', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'destination' => 'Kuala Lumpur — Client HQ',
            'transport' => 'flight',
            'status' => 'submitted',
        ]);
    }

    public function test_submitted_request_is_bound_to_own_employee_id(): void
    {
        // Arrange — a colleague the submitter might try to file "as".
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);

        // Act — plain employee submits colleague's id as owner.
        $response = $this->actingInTenant()->post('/app/travel', [
            'employee_id' => $colleague->id,
            'destination' => 'Spoofed Owner Trip',
            'purpose' => 'Attempt to file on behalf of a colleague.',
            'depart_date' => now()->addDays(2)->toDateString(),
            'return_date' => now()->addDays(3)->toDateString(),
            'transport' => 'car',
        ]);

        // Assert — server binds the request to the submitter, ignoring the spoofed id.
        $response->assertRedirect();
        $this->assertDatabaseHas('travel_requests', [
            'destination' => 'Spoofed Owner Trip',
            'employee_id' => $this->employee->id,
        ]);
        $this->assertDatabaseMissing('travel_requests', [
            'destination' => 'Spoofed Owner Trip',
            'employee_id' => $colleague->id,
        ]);
    }

    public function test_privileged_user_approves_a_travel_request(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $travel = TravelRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'destination' => 'Penang Branch',
            'purpose' => 'Branch review.',
            'depart_date' => now()->addDays(4)->toDateString(),
            'return_date' => now()->addDays(6)->toDateString(),
            'transport' => 'car',
            'status' => 'submitted',
        ]);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/travel/{$travel->id}/approve");

        // Assert
        $response->assertRedirect();
        $this->assertSame('approved', $travel->fresh()->status);
    }

    public function test_plain_employee_cannot_approve_a_travel_request(): void
    {
        // Arrange
        $travel = TravelRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'destination' => 'Singapore Conference',
            'purpose' => 'Industry conference.',
            'depart_date' => now()->addDays(8)->toDateString(),
            'return_date' => now()->addDays(10)->toDateString(),
            'transport' => 'flight',
            'status' => 'submitted',
        ]);

        // Act — a plain employee tries to approve their own request.
        $response = $this->actingInTenant()->post("/app/travel/{$travel->id}/approve");

        // Assert — forbidden, and the request stays submitted.
        $response->assertForbidden();
        $this->assertSame('submitted', $travel->fresh()->status);
    }

    public function test_privileged_user_rejects_a_travel_request(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $travel = TravelRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'destination' => 'Johor Bahru Site',
            'purpose' => 'Site inspection.',
            'depart_date' => now()->addDays(3)->toDateString(),
            'return_date' => now()->addDays(4)->toDateString(),
            'transport' => 'car',
            'status' => 'submitted',
        ]);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/travel/{$travel->id}/reject");

        // Assert
        $response->assertRedirect();
        $this->assertSame('rejected', $travel->fresh()->status);
    }
}
