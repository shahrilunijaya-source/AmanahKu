<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\KpiItem;
use App\Models\OnboardingProfile;
use App\Models\OnboardingTask;
use App\Models\Tenant;
use App\Models\TrainingRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OperationsWritePathsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    private ?User $hrUser = null;

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

    private function hr(): User
    {
        if ($this->hrUser) {
            return $this->hrUser;
        }

        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        return $this->hrUser = $hr;
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr())->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    // ── Onboarding ────────────────────────────────────────────────

    public function test_owner_toggles_an_onboarding_task(): void
    {
        $profile = OnboardingProfile::create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'start_date' => '2026-06-01', 'day_number' => 1, 'total_days' => 90]);
        $task = OnboardingTask::create(['onboarding_profile_id' => $profile->id, 'track' => 'general', 'title' => 'Read handbook', 'done' => false, 'sort' => 0]);

        $this->actingInTenant()->post("/app/onboarding/tasks/{$task->id}/toggle")->assertRedirect();
        $this->assertTrue($task->fresh()->done);
    }

    public function test_outsider_employee_cannot_toggle_anothers_task(): void
    {
        $colleague = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green']);
        $profile = OnboardingProfile::create(['tenant_id' => $this->tenant->id, 'employee_id' => $colleague->id, 'start_date' => '2026-06-01', 'day_number' => 1, 'total_days' => 90]);
        $task = OnboardingTask::create(['onboarding_profile_id' => $profile->id, 'track' => 'general', 'title' => 'X', 'done' => false, 'sort' => 0]);

        $this->actingInTenant()->post("/app/onboarding/tasks/{$task->id}/toggle")->assertForbidden();
        $this->assertFalse($task->fresh()->done);
    }

    // ── KPI ───────────────────────────────────────────────────────

    public function test_employee_updates_own_kpi(): void
    {
        $kpi = KpiItem::create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'title' => 'Ship it', 'category' => 'results', 'progress' => 40, 'status' => 'amber']);

        $this->actingInTenant()->post("/app/kpi/{$kpi->id}", ['actual' => '90%', 'progress' => 90])->assertRedirect();

        $fresh = $kpi->fresh();
        $this->assertEquals(90, $fresh->progress);
        $this->assertSame('green', $fresh->status);
        $this->assertSame('90%', $fresh->actual);
    }

    public function test_employee_cannot_update_anothers_kpi(): void
    {
        $colleague = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green']);
        $kpi = KpiItem::create(['tenant_id' => $this->tenant->id, 'employee_id' => $colleague->id, 'title' => 'X', 'category' => 'results', 'progress' => 10, 'status' => 'amber']);

        $this->actingInTenant()->post("/app/kpi/{$kpi->id}", ['progress' => 100])->assertForbidden();
        $this->assertEquals(10, $kpi->fresh()->progress);
    }

    // ── Training ──────────────────────────────────────────────────

    public function test_privileged_assigns_training_and_assignee_completes_it(): void
    {
        $this->actingHr()->post('/app/training', [
            'employee_id' => $this->employee->id, 'course' => 'Fire Safety', 'mandatory' => '1', 'due_at' => '2026-08-01',
        ])->assertRedirect();

        $record = TrainingRecord::where('course', 'Fire Safety')->first();
        $this->assertNotNull($record);
        $this->assertSame('not_started', $record->status);

        $this->actingInTenant()->post("/app/training/{$record->id}/complete")->assertRedirect();
        $this->assertSame('completed', $record->fresh()->status);
        $this->assertNotNull($record->fresh()->completed_at);
    }

    public function test_employee_cannot_assign_training(): void
    {
        $this->actingInTenant()->post('/app/training', ['employee_id' => $this->employee->id, 'course' => 'Self study'])->assertForbidden();
        $this->assertDatabaseMissing('training_records', ['course' => 'Self study']);
    }

    public function test_privileged_assigns_one_course_to_multiple_employees(): void
    {
        $second = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Second', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingHr()->post('/app/training', [
            'employee_ids' => [$this->employee->id, $second->id],
            'course' => 'Data Protection', 'due_at' => '2026-09-01',
        ])->assertRedirect();

        // One record per selected employee, same course.
        $this->assertSame(2, TrainingRecord::where('course', 'Data Protection')->count());
        $this->assertDatabaseHas('training_records', ['course' => 'Data Protection', 'employee_id' => $this->employee->id]);
        $this->assertDatabaseHas('training_records', ['course' => 'Data Protection', 'employee_id' => $second->id]);
    }

    public function test_assigning_training_rejects_an_employee_from_another_tenant(): void
    {
        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $outsider = Employee::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Outsider', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingHr()->post('/app/training', [
            'employee_ids' => [$outsider->id], 'course' => 'Cross tenant',
        ])->assertSessionHasErrors('employee_ids.0');
        $this->assertDatabaseMissing('training_records', ['course' => 'Cross tenant']);
    }

    // ── Assets ────────────────────────────────────────────────────

    public function test_privileged_adds_assigns_and_releases_an_asset(): void
    {
        $this->actingHr()->post('/app/assets', ['name' => 'MacBook Air', 'category' => 'laptop', 'serial' => 'C02XYZ'])->assertRedirect();
        $asset = Asset::where('name', 'MacBook Air')->first();
        $this->assertSame('available', $asset->status);

        $this->actingHr()->post("/app/assets/{$asset->id}/assign", ['employee_id' => $this->employee->id])->assertRedirect();
        $this->assertSame('assigned', $asset->fresh()->status);
        $this->assertSame($this->employee->id, $asset->fresh()->employee_id);

        $this->actingHr()->post("/app/assets/{$asset->id}/release")->assertRedirect();
        $this->assertSame('available', $asset->fresh()->status);
        $this->assertNull($asset->fresh()->employee_id);
    }

    public function test_employee_cannot_add_an_asset(): void
    {
        $this->actingInTenant()->post('/app/assets', ['name' => 'Sneaky laptop', 'category' => 'laptop'])->assertForbidden();
        $this->assertDatabaseMissing('assets', ['name' => 'Sneaky laptop']);
    }

    // ── Announcements ─────────────────────────────────────────────

    public function test_privileged_posts_an_announcement(): void
    {
        $this->actingHr()->post('/app/announcements', ['title' => 'Office closed Friday', 'tag' => 'Notice'])->assertRedirect();
        $this->assertDatabaseHas('announcements', ['tenant_id' => $this->tenant->id, 'title' => 'Office closed Friday', 'tag' => 'Notice']);
    }

    public function test_employee_cannot_post_an_announcement(): void
    {
        $this->actingInTenant()->post('/app/announcements', ['title' => 'Free coffee'])->assertForbidden();
        $this->assertDatabaseMissing('announcements', ['title' => 'Free coffee']);
    }
}
