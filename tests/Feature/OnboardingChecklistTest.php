<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\OnboardingProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OnboardingService;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The HR-facing onboarding checklist (start · add · toggle · remove) — the onboarding
 * mirror of OffboardingTest. Distinct from OnboardingWizardTest, which covers the staff
 * first-login /app/welcome flow.
 */
class OnboardingChecklistTest extends TestCase
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

    private function profileFor(Employee $employee): OnboardingProfile
    {
        return OnboardingProfile::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'start_date' => now()->subDays(3)->toDateString(), 'day_number' => 3, 'total_days' => 90,
        ]);
    }

    public function test_privileged_user_starts_onboarding_and_seeds_the_standard_checklist(): void
    {
        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/onboarding/start', [
                'employee_id' => $this->employee->id,
                'start_date' => now()->toDateString(),
                'total_days' => 60,
            ])->assertRedirect();

        $profile = OnboardingProfile::where('employee_id', $this->employee->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame(60, $profile->total_days);
        $this->assertSame(count(OnboardingService::STANDARD_CHECKLIST), $profile->tasks()->count());
    }

    public function test_employee_cannot_start_onboarding(): void
    {
        $this->actingInTenant()->post('/app/onboarding/start', [
            'employee_id' => $this->employee->id,
            'start_date' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertDatabaseMissing('onboarding_profiles', ['employee_id' => $this->employee->id]);
    }

    public function test_start_onboarding_is_idempotent_for_the_same_employee(): void
    {
        app(CurrentTenant::class)->set($this->tenant);
        $existing = $this->profileFor($this->employee);

        $again = app(OnboardingService::class)->startOnboarding($this->employee, now()->toDateString());

        $this->assertSame($existing->id, $again->id);
        $this->assertSame(1, OnboardingProfile::where('employee_id', $this->employee->id)->count());
    }

    public function test_privileged_user_adds_a_task_at_the_end_of_the_track(): void
    {
        app(CurrentTenant::class)->set($this->tenant);
        $profile = $this->profileFor($this->employee);
        $profile->tasks()->create(['track' => 'general', 'title' => 'Existing', 'done' => false, 'sort' => 0]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/onboarding/{$profile->id}/tasks", [
                'track' => 'position', 'title' => 'Shadow a senior dev',
            ])->assertRedirect();

        $task = $profile->tasks()->where('title', 'Shadow a senior dev')->first();
        $this->assertNotNull($task);
        $this->assertSame('position', $task->track);
        $this->assertFalse($task->done);
        $this->assertSame(1, $task->sort);
    }

    public function test_add_task_rejects_a_track_outside_the_enum(): void
    {
        app(CurrentTenant::class)->set($this->tenant);
        $profile = $this->profileFor($this->employee);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/onboarding/{$profile->id}/tasks", [
                'track' => 'random', 'title' => 'Bad track',
            ])->assertSessionHasErrors('track');

        $this->assertDatabaseMissing('onboarding_tasks', ['title' => 'Bad track']);
    }

    public function test_employee_cannot_add_a_task(): void
    {
        app(CurrentTenant::class)->set($this->tenant);
        $profile = $this->profileFor($this->employee);

        $this->actingInTenant()->post("/app/onboarding/{$profile->id}/tasks", [
            'track' => 'general', 'title' => 'Sneaky task',
        ])->assertForbidden();

        $this->assertDatabaseMissing('onboarding_tasks', ['title' => 'Sneaky task']);
    }

    public function test_privileged_user_removes_a_task(): void
    {
        app(CurrentTenant::class)->set($this->tenant);
        $profile = $this->profileFor($this->employee);
        $task = $profile->tasks()->create(['track' => 'general', 'title' => 'Wrong task', 'done' => false, 'sort' => 0]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/onboarding/tasks/{$task->id}/remove")->assertRedirect();

        $this->assertDatabaseMissing('onboarding_tasks', ['id' => $task->id]);
    }

    public function test_employee_cannot_remove_a_task(): void
    {
        app(CurrentTenant::class)->set($this->tenant);
        $profile = $this->profileFor($this->employee);
        $task = $profile->tasks()->create(['track' => 'general', 'title' => 'Keep me', 'done' => false, 'sort' => 0]);

        $this->actingInTenant()->post("/app/onboarding/tasks/{$task->id}/remove")->assertForbidden();

        $this->assertDatabaseHas('onboarding_tasks', ['id' => $task->id]);
    }

    public function test_onboardee_can_toggle_their_own_task(): void
    {
        app(CurrentTenant::class)->set($this->tenant);
        $profile = $this->profileFor($this->employee);
        $task = $profile->tasks()->create(['track' => 'general', 'title' => 'Sign handbook', 'done' => false, 'sort' => 0]);

        $this->actingInTenant()->post("/app/onboarding/tasks/{$task->id}/toggle")->assertRedirect();

        $this->assertTrue($task->fresh()->done);
    }

    public function test_onboarding_screen_renders_for_hr_and_for_the_onboardee(): void
    {
        app(CurrentTenant::class)->set($this->tenant);
        $this->profileFor($this->employee);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/onboarding')->assertOk();

        $this->actingInTenant()->get('/app/onboarding')->assertOk();
    }
}
