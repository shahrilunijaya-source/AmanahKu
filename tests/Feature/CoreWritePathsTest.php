<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\PerformanceReview;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CoreWritePathsTest extends TestCase
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
        // Reviewers must have an employee profile in the tenant (authorizeReviewer).
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $hr->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $hr;
    }

    // ── Work items ────────────────────────────────────────────────

    public function test_employee_adds_a_work_item(): void
    {
        $this->actingInTenant()->post('/app/board', [
            'title' => 'Draft the Q3 plan', 'type' => 'task', 'priority' => 'high',
            'due_label' => 'Fri 26 Jun', 'estimate_hours' => 4,
        ])->assertRedirect();

        $this->assertDatabaseHas('work_items', [
            'employee_id' => $this->employee->id, 'tenant_id' => $this->tenant->id,
            'title' => 'Draft the Q3 plan', 'status' => 'todo',
        ]);
    }

    public function test_employee_moves_own_work_item_to_done(): void
    {
        $item = $this->employee->workItems()->create([
            'tenant_id' => $this->tenant->id,
            'title' => 'X', 'type' => 'task', 'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ]);

        $this->actingInTenant()->post("/app/board/{$item->id}/move", ['status' => 'done'])->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame('done', $fresh->status);
        $this->assertEquals(100, $fresh->progress);
    }

    public function test_cannot_move_another_employees_work_item(): void
    {
        $colleague = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green']);
        $item = $colleague->workItems()->create(['tenant_id' => $this->tenant->id, 'title' => 'Y', 'type' => 'task', 'priority' => 'low', 'status' => 'todo', 'progress' => 0]);

        $this->actingInTenant()->post("/app/board/{$item->id}/move", ['status' => 'done'])->assertForbidden();
        $this->assertSame('todo', $item->fresh()->status);
    }

    // ── Employee CRUD ─────────────────────────────────────────────

    public function test_privileged_user_adds_an_employee(): void
    {
        $dept = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Ops']);
        $branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'HQ', 'state' => 'Selangor']);
        $band = Position::create([
            'tenant_id' => $this->tenant->id, 'department_id' => $dept->id,
            'title' => 'Analyst', 'max_salary' => 5000,
        ]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/employees', [
                'name' => 'New Person', 'email' => 'np@example.com',
                'position_id' => $band->id, 'salary' => 4200,
                'branch_id' => $branch->id, 'status' => 'probation',
            ])->assertRedirect();

        // Department + job title are derived from the chosen band; salary is stored per person.
        $this->assertDatabaseHas('employees', [
            'tenant_id' => $this->tenant->id, 'name' => 'New Person',
            'status' => 'probation', 'initials' => 'NP',
            'department_id' => $dept->id, 'position' => 'Analyst',
            'position_id' => $band->id, 'salary' => '4200.00',
        ]);
    }

    public function test_employee_cannot_add_an_employee(): void
    {
        $this->actingInTenant()->post('/app/employees', ['name' => 'Sneaky', 'status' => 'active'])->assertForbidden();
        $this->assertDatabaseMissing('employees', ['name' => 'Sneaky']);
    }

    public function test_add_employee_requires_a_name(): void
    {
        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/employees', ['name' => '', 'status' => 'active'])->assertSessionHasErrors('name');
    }

    public function test_privileged_user_updates_an_employee(): void
    {
        $dept = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Ops']);
        $band = Position::create([
            'tenant_id' => $this->tenant->id, 'department_id' => $dept->id,
            'title' => 'Lead', 'max_salary' => 8000,
        ]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/employees/{$this->employee->id}", ['name' => $this->employee->name, 'position_id' => $band->id, 'status' => 'active'])
            ->assertRedirect();

        // Picking a band syncs the title + department onto the employee.
        $fresh = $this->employee->fresh();
        $this->assertSame('Lead', $fresh->position);
        $this->assertSame($band->id, $fresh->position_id);
        $this->assertSame($dept->id, $fresh->department_id);
    }

    /**
     * Regression: the edit form previously had no employment-type field, so saving
     * it silently nulled the column. The edit form now carries the same fields as the
     * New employee form — name, email and employment type all round-trip on save.
     */
    public function test_editing_an_employee_round_trips_name_email_and_employment_type(): void
    {
        $et = EmploymentType::create(['tenant_id' => $this->tenant->id, 'name' => 'Full-time']);
        $this->employee->update(['employment_type_id' => $et->id]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/employees/{$this->employee->id}", [
                'name' => 'Demo Renamed',
                'email' => 'renamed@example.com',
                'employment_type_id' => $et->id,
                'status' => 'active',
            ])->assertRedirect();

        $fresh = $this->employee->fresh();
        $this->assertSame('Demo Renamed', $fresh->name);
        $this->assertSame('renamed@example.com', $fresh->email);
        $this->assertSame($et->id, $fresh->employment_type_id);   // not wiped
        $this->assertSame('DR', $fresh->initials);                // re-derived from new name
    }

    /**
     * The edit form carries a Work arrangement select. Saving it round-trips the value, and
     * switching away from a detail arrangement (hybrid) clears the now-irrelevant office-day
     * split so stale data never lingers.
     */
    public function test_editing_an_employee_round_trips_work_arrangement_and_clears_stale_detail(): void
    {
        $this->employee->update(['work_arrangement' => 'hybrid', 'hybrid_office_days' => [1, 3]]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/employees/{$this->employee->id}", [
                'name' => $this->employee->name,
                'status' => 'active',
                'work_arrangement' => 'wfh',
            ])->assertRedirect();

        $fresh = $this->employee->fresh();
        $this->assertSame('wfh', $fresh->work_arrangement);
        $this->assertNull($fresh->hybrid_office_days);   // detail cleared on switch away from hybrid
    }

    /** Omitting work_arrangement on edit keeps the existing arrangement — never nulls it. */
    public function test_editing_an_employee_keeps_existing_arrangement_when_omitted(): void
    {
        $this->employee->update(['work_arrangement' => 'client']);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/employees/{$this->employee->id}", ['name' => $this->employee->name, 'status' => 'active'])
            ->assertRedirect();

        $this->assertSame('client', $this->employee->fresh()->work_arrangement);
    }

    public function test_update_employee_requires_a_name(): void
    {
        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/employees/{$this->employee->id}", ['name' => '', 'status' => 'active'])
            ->assertSessionHasErrors('name');
    }

    /** Regression: a blank Joined field on edit must keep the existing hire date, never null it. */
    public function test_editing_an_employee_keeps_existing_joined_date_when_left_blank(): void
    {
        $this->employee->update(['joined_at' => '2020-01-15']);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/employees/{$this->employee->id}", ['name' => 'Demo', 'status' => 'active'])
            ->assertRedirect();

        $this->assertSame('2020-01-15', $this->employee->fresh()->joined_at->toDateString());
    }

    public function test_privileged_user_can_archive_a_staff_member(): void
    {
        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/employees/{$this->employee->id}/delete")
            ->assertRedirect('/app/directory');

        $fresh = Employee::find($this->employee->id);
        // The row still resolves (history preserved, fail-safe) but is flagged archived
        // and drops out of every active-staff query.
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->archived_at);
        $this->assertNull(Employee::active()->find($this->employee->id));
    }

    public function test_archiving_is_idempotent_and_keeps_the_first_timestamp(): void
    {
        $this->employee->update(['archived_at' => '2026-01-01 00:00:00']);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/employees/{$this->employee->id}/delete")
            ->assertRedirect('/app/directory');

        // Re-archiving a already-archived person is a no-op — the original date stands.
        $this->assertSame('2026-01-01 00:00:00', $this->employee->fresh()->archived_at->format('Y-m-d H:i:s'));
    }

    public function test_plain_employee_cannot_archive_staff(): void
    {
        $this->actingInTenant()
            ->post("/app/employees/{$this->employee->id}/delete")
            ->assertForbidden();

        $this->assertDatabaseHas('employees', ['id' => $this->employee->id, 'archived_at' => null]);
    }

    // ── Permanent delete (guarded) ────────────────────────────────

    public function test_privileged_user_can_permanently_delete_an_archived_staff_member(): void
    {
        $this->employee->update(['archived_at' => now()]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/employees/{$this->employee->id}/force-delete")
            ->assertRedirect('/app/directory?view=archived');

        // Row is gone for good — not merely archived.
        $this->assertDatabaseMissing('employees', ['id' => $this->employee->id]);
        // Login revoked: the sole tenant membership is dropped and the orphaned account deleted.
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    }

    public function test_cannot_permanently_delete_a_staff_member_that_is_not_archived(): void
    {
        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/employees/{$this->employee->id}/force-delete")
            ->assertRedirect('/app/directory?view=archived');

        // Delete only follows archive — an un-archived person survives untouched.
        $this->assertDatabaseHas('employees', ['id' => $this->employee->id, 'archived_at' => null]);
    }

    public function test_permanent_delete_is_blocked_when_staff_has_payroll_history(): void
    {
        $this->employee->update(['archived_at' => now()]);
        DB::table('salary_structures')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'basic_salary' => 5000, 'currency' => 'MYR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/employees/{$this->employee->id}/force-delete")
            ->assertRedirect('/app/directory?view=archived');

        // Retention guard (AK-DB-01) held — the person stays as an archived record.
        $this->assertDatabaseHas('employees', ['id' => $this->employee->id]);
    }

    public function test_plain_employee_cannot_permanently_delete_staff(): void
    {
        $this->employee->update(['archived_at' => now()]);

        $this->actingInTenant()
            ->post("/app/employees/{$this->employee->id}/force-delete")
            ->assertForbidden();

        $this->assertDatabaseHas('employees', ['id' => $this->employee->id]);
    }

    // ── Reviewer rating-entry ─────────────────────────────────────

    public function test_reviewer_scores_an_open_review(): void
    {
        $review = PerformanceReview::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'cycle' => '2026 H1', 'status' => 'in_progress',
        ]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/reviews/{$review->id}/complete", [
                'overall_rating' => 4.3, 'rating_label' => 'Exceeds',
                'c_delivery' => 4.5, 'c_collaboration' => 4.0, 'c_leadership' => 4.2,
                'strengths' => 'Great', 'improvements' => 'More docs', 'goals' => 'Lead a project',
            ])->assertRedirect();

        $fresh = $review->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertEqualsWithDelta(4.3, (float) $fresh->overall_rating, 0.001);
        $this->assertCount(3, $fresh->competencies);
    }

    public function test_employee_cannot_score_a_review(): void
    {
        $review = PerformanceReview::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'cycle' => '2026 H1', 'status' => 'in_progress',
        ]);

        $this->actingInTenant()->post("/app/reviews/{$review->id}/complete", [
            'overall_rating' => 5, 'rating_label' => 'Self five',
            'c_delivery' => 5, 'c_collaboration' => 5, 'c_leadership' => 5,
        ])->assertForbidden();

        $this->assertSame('in_progress', $review->fresh()->status);
    }

    public function test_cannot_score_an_already_completed_review(): void
    {
        $review = PerformanceReview::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'cycle' => '2026 H1', 'status' => 'completed', 'overall_rating' => 4.0,
        ]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/reviews/{$review->id}/complete", [
                'overall_rating' => 1, 'rating_label' => 'Override',
                'c_delivery' => 1, 'c_collaboration' => 1, 'c_leadership' => 1,
            ])->assertStatus(422);
    }

    public function test_reviewer_saves_ratings_as_a_draft(): void
    {
        $review = PerformanceReview::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'cycle' => '2026 H1', 'status' => 'in_progress',
        ]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/reviews/{$review->id}/rate", [
                'reviewer_overall' => 4.1, 'rating_label' => 'Strong',
                'r_delivery' => 4.0, 'r_collaboration' => 4.2, 'r_leadership' => 4.0,
                'reviewer_comments' => 'Good half.',
            ])->assertRedirect();

        $fresh = $review->fresh();
        $this->assertSame('in_progress', $fresh->status);          // draft — not finalised
        $this->assertEqualsWithDelta(4.1, (float) $fresh->reviewer_overall, 0.001);
        $this->assertNotNull($fresh->reviewer_rated_at);
        $this->assertNull($fresh->overall_rating);                 // not promoted yet
    }

    public function test_reviewer_finalises_a_review(): void
    {
        $review = PerformanceReview::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'cycle' => '2026 H1', 'status' => 'in_progress',
        ]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/reviews/{$review->id}/rate", [
                'reviewer_overall' => 4.6, 'rating_label' => 'Outstanding',
                'r_delivery' => 4.8, 'r_collaboration' => 4.5, 'r_leadership' => 4.4,
                'finalize' => '1',
            ])->assertRedirect();

        $fresh = $review->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertEqualsWithDelta(4.6, (float) $fresh->overall_rating, 0.001);  // promoted
        $this->assertCount(3, $fresh->competencies);
    }

    // ── Report export ─────────────────────────────────────────────

    public function test_privileged_user_can_export_employees_csv(): void
    {
        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/reports/export/employees')->assertOk();
    }

    public function test_employee_cannot_export_employees_csv(): void
    {
        $this->actingInTenant()->get('/app/reports/export/employees')->assertForbidden();
    }
}
