<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PerformanceTest extends TestCase
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

    private function makeHr(): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        return $hr;
    }

    private function ownReview(string $status = 'completed'): PerformanceReview
    {
        return PerformanceReview::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'cycle' => '2026 H1', 'status' => $status, 'overall_rating' => 4.2,
        ]);
    }

    // ── Recognition ───────────────────────────────────────────────

    public function test_privileged_user_can_record_recognition(): void
    {
        $this->actingAs($this->makeHr())->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/achievements', [
                'employee_id' => $this->employee->id,
                'title' => 'Shipped the onboarding revamp',
                'category' => 'Award',
                'points' => 150,
            ])->assertRedirect();

        $this->assertDatabaseHas('achievements', [
            'employee_id' => $this->employee->id,
            'tenant_id' => $this->tenant->id,
            'title' => 'Shipped the onboarding revamp',
            'category' => 'Award',
            'points' => 150,
        ]);
    }

    public function test_employee_cannot_record_recognition(): void
    {
        $this->actingInTenant()->post('/app/achievements', [
            'employee_id' => $this->employee->id,
            'title' => 'Self praise',
            'category' => 'Award',
        ])->assertForbidden();

        $this->assertDatabaseCount('achievements', 0);
    }

    public function test_recognition_validation_rejects_bad_input(): void
    {
        $this->actingAs($this->makeHr())->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/achievements', [
                'employee_id' => $this->employee->id,
                'title' => '',
                'category' => 'NotARealCategory',
            ])->assertSessionHasErrors(['title', 'category']);

        $this->assertDatabaseCount('achievements', 0);
    }

    public function test_recognition_recipient_must_belong_to_active_tenant(): void
    {
        // An employee in a different tenant must not be a valid recipient.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $outsider = Employee::create([
            'tenant_id' => $other->id, 'name' => 'Outsider', 'status' => 'active', 'workload' => 'green',
        ]);

        // The tenant-scoped `exists` rule rejects the foreign recipient at validation;
        // the controller's tenant_id assert is the defense-in-depth layer behind it.
        $this->actingAs($this->makeHr())->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/achievements', [
                'employee_id' => $outsider->id,
                'title' => 'Cross-tenant recognition',
                'category' => 'Award',
            ])->assertSessionHasErrors('employee_id');

        $this->assertDatabaseCount('achievements', 0);
    }

    // ── Review acknowledge ────────────────────────────────────────

    public function test_employee_acknowledges_own_completed_review(): void
    {
        $review = $this->ownReview('completed');

        $this->actingInTenant()->post("/app/reviews/{$review->id}/acknowledge")->assertRedirect();

        $this->assertSame('acknowledged', $review->fresh()->status);
        $this->assertNotNull($review->fresh()->acknowledged_at);
    }

    public function test_cannot_acknowledge_another_employees_review(): void
    {
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);
        $review = PerformanceReview::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $colleague->id,
            'cycle' => '2026 H1', 'status' => 'completed',
        ]);

        $this->actingInTenant()->post("/app/reviews/{$review->id}/acknowledge")->assertForbidden();
        $this->assertSame('completed', $review->fresh()->status);
    }

    public function test_cannot_acknowledge_a_review_that_is_not_completed(): void
    {
        $review = $this->ownReview('in_progress');

        $this->actingInTenant()->post("/app/reviews/{$review->id}/acknowledge")->assertStatus(422);
        $this->assertSame('in_progress', $review->fresh()->status);
    }

    // ── Self-assessment ───────────────────────────────────────────

    public function test_employee_saves_self_assessment_on_open_review(): void
    {
        $review = $this->ownReview('in_progress');

        $this->actingInTenant()->post("/app/reviews/{$review->id}/self-assessment", [
            'self_assessment' => 'Delivered the payroll project on time and under budget.',
        ])->assertRedirect();

        $this->assertSame('Delivered the payroll project on time and under budget.', $review->fresh()->self_assessment);
    }

    public function test_self_assessment_requires_text(): void
    {
        $review = $this->ownReview('in_progress');

        $this->actingInTenant()->post("/app/reviews/{$review->id}/self-assessment", [
            'self_assessment' => '',
        ])->assertSessionHasErrors('self_assessment');
    }

    public function test_self_assessment_blocked_on_completed_review(): void
    {
        $review = $this->ownReview('completed');

        $this->actingInTenant()->post("/app/reviews/{$review->id}/self-assessment", [
            'self_assessment' => 'Too late to edit.',
        ])->assertStatus(422);
    }

    // ── Screens render ────────────────────────────────────────────

    public function test_achievements_screen_renders(): void
    {
        Achievement::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'who' => 'Demo', 'title' => 'Did a great thing', 'category' => 'Recognition', 'points' => 40,
            'date' => '2026-06-20', 'date_label' => 'today',
        ]);

        $this->actingInTenant()->get('/app/achievements')->assertOk()->assertSee('Did a great thing');
    }

    public function test_reviews_screen_renders(): void
    {
        $this->ownReview('completed');

        $this->actingInTenant()->get('/app/reviews')->assertOk()->assertSee('2026 H1');
    }
}
