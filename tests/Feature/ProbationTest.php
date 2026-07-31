<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ProbationReview;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the PRIVILEGED Probation tracking module.
 * Harness (setUp / actingInTenant / hrActor) copied from CaseTest / OffboardingTest.
 */
class ProbationTest extends TestCase
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
            'name' => 'New Hire', 'status' => 'probation', 'workload' => 'green',
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

    private function makeReview(string $status = 'active', ?Carbon $end = null): ProbationReview
    {
        $start = Carbon::create(2026, 6, 1);

        return ProbationReview::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'start_date' => $start->toDateString(),
            'end_date' => ($end ?? $start->copy()->addDays(90))->toDateString(),
            'length_days' => 90,
            'status' => $status,
        ]);
    }

    // ── Start a review ────────────────────────────────────────────

    public function test_privileged_user_starts_a_probation_review(): void
    {
        // Arrange
        $hr = $this->hrActor();

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/probation', [
                'employee_id' => $this->employee->id,
                'start_date' => '2026-06-11',
                'length_days' => 90,
            ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('probation_reviews', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'length_days' => 90,
            'status' => 'active',
        ]);
        $review = ProbationReview::where('employee_id', $this->employee->id)->first();
        $this->assertNotNull($review);
        $this->assertSame('2026-09-09', $review->end_date->toDateString());
    }

    public function test_plain_employee_cannot_start_a_probation_review(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/probation', [
            'employee_id' => $this->employee->id,
            'start_date' => '2026-06-11',
            'length_days' => 90,
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('probation_reviews', ['employee_id' => $this->employee->id]);
    }

    // ── Check-in ──────────────────────────────────────────────────

    public function test_privileged_user_adds_a_checkin(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $review = $this->makeReview();

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/probation/{$review->id}/checkin", [
                'milestone' => '30-day',
                'note' => 'Settling in well.',
                'rating' => 4,
                'checkin_date' => '2026-07-01',
            ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('probation_checkins', [
            'tenant_id' => $this->tenant->id,
            'probation_review_id' => $review->id,
            'milestone' => '30-day',
            'rating' => 4,
        ]);
    }

    // ── Decisions ─────────────────────────────────────────────────

    public function test_confirm_decision_activates_the_employee(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $review = $this->makeReview();
        $this->assertSame('probation', $this->employee->fresh()->status);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/probation/{$review->id}/decide", [
                'decision' => 'confirm',
                'decision_note' => 'Confirmed to permanent staff.',
            ]);

        // Assert
        $response->assertRedirect();
        $this->assertSame('confirmed', $review->fresh()->status);
        $this->assertSame('active', $this->employee->fresh()->status);
    }

    public function test_extend_decision_pushes_out_end_date(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $review = $this->makeReview('active', Carbon::create(2026, 8, 30));
        $originalEnd = $review->end_date->toDateString();

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/probation/{$review->id}/decide", [
                'decision' => 'extend',
                'extend_days' => 30,
                'decision_note' => 'Needs more time on reporting.',
            ]);

        // Assert
        $response->assertRedirect();
        $fresh = $review->fresh();
        $this->assertSame('extended', $fresh->status);
        $this->assertSame('2026-09-29', $fresh->end_date->toDateString());
        $this->assertNotSame($originalEnd, $fresh->end_date->toDateString());
    }

    public function test_plain_employee_cannot_decide(): void
    {
        // Arrange
        $review = $this->makeReview();

        // Act
        $response = $this->actingInTenant()->post("/app/probation/{$review->id}/decide", [
            'decision' => 'confirm',
            'decision_note' => 'Trying to self-confirm.',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertSame('active', $review->fresh()->status);
        $this->assertSame('probation', $this->employee->fresh()->status);
    }
}
