<?php

namespace Tests\Feature;

use App\Models\BenefitEnrollment;
use App\Models\BenefitPlan;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Benefits & insurance enrollment module.
 * Harness (setUp / actingInTenant / hrActor) copied from ModulesBatchTest.
 */
class BenefitTest extends TestCase
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

    private function makePlan(bool $active = true): BenefitPlan
    {
        return BenefitPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'AIA Medical',
            'type' => 'medical',
            'provider' => 'AIA',
            'monthly_cost' => 180.00,
            'active' => $active,
        ]);
    }

    public function test_employee_enrolls_in_a_plan(): void
    {
        // Arrange
        $plan = $this->makePlan();

        // Act
        $response = $this->actingInTenant()->post("/app/benefits/{$plan->id}/enroll", [
            'status' => 'enrolled',
            'dependents' => 2,
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('benefit_enrollments', [
            'benefit_plan_id' => $plan->id,
            'employee_id' => $this->employee->id,
            'status' => 'enrolled',
            'dependents' => 2,
        ]);
    }

    public function test_changing_to_waive_updates_the_same_row(): void
    {
        // Arrange — employee already enrolled in the plan.
        $plan = $this->makePlan();
        $this->actingInTenant()->post("/app/benefits/{$plan->id}/enroll", [
            'status' => 'enrolled',
            'dependents' => 1,
        ]);

        // Act — same employee waives the same plan.
        $response = $this->actingInTenant()->post("/app/benefits/{$plan->id}/enroll", [
            'status' => 'waived',
            'dependents' => 0,
        ]);

        // Assert — the SAME row was updated (unique constraint respected, no duplicate).
        $response->assertRedirect();
        $this->assertSame(1, BenefitEnrollment::where('benefit_plan_id', $plan->id)
            ->where('employee_id', $this->employee->id)->count());
        $this->assertDatabaseHas('benefit_enrollments', [
            'benefit_plan_id' => $plan->id,
            'employee_id' => $this->employee->id,
            'status' => 'waived',
            'dependents' => 0,
        ]);
    }

    public function test_privileged_user_creates_a_plan(): void
    {
        // Arrange
        $hr = $this->hrActor();

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/benefits/plans', [
                'name' => 'Dental Care',
                'type' => 'dental',
                'provider' => 'Great Eastern',
                'monthly_cost' => 45.00,
            ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('benefit_plans', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Dental Care',
            'type' => 'dental',
            'active' => true,
        ]);
    }

    public function test_plain_employee_cannot_create_a_plan(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/benefits/plans', [
            'name' => 'Sneaky Plan',
            'type' => 'other',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('benefit_plans', ['name' => 'Sneaky Plan']);
    }
}
