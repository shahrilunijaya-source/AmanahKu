<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectQuickCreateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: User} */
    private function tenantWithRole(string $role): array
    {
        $tenant = Tenant::create(['slug' => 'unijaya', 'name' => 'Unijaya', 'initials' => 'UJ']);
        $user = User::create(['name' => 'Test User', 'email' => 'user@example.com', 'password' => bcrypt('password')]);
        $user->tenants()->attach($tenant->id, ['role' => $role]);

        return [$tenant, $user];
    }

    public function test_manager_can_create_a_project(): void
    {
        [$tenant, $user] = $this->tenantWithRole('manager');

        $response = $this->actingAs($user)
            ->withSession(['current_tenant' => $tenant->id])
            ->post(route('project-quick-create.store'), ['code' => 'KPT', 'name' => 'KPT: RMS']);

        $response->assertRedirect();
        $this->assertDatabaseHas('projects', ['tenant_id' => $tenant->id, 'code' => 'KPT', 'name' => 'KPT: RMS', 'is_active' => true]);
    }

    public function test_screen_shows_the_active_categories(): void
    {
        [$tenant, $user] = $this->tenantWithRole('manager');
        TimesheetCategory::create(['tenant_id' => $tenant->id, 'name' => 'Development', 'requires_project' => true]);
        TimesheetCategory::create(['tenant_id' => $tenant->id, 'name' => 'Retired Category', 'requires_project' => true, 'is_active' => false]);

        $this->actingAs($user)
            ->withSession(['current_tenant' => $tenant->id])
            ->get('/app/project-quick-create')
            ->assertOk()
            ->assertSee('Development')
            ->assertDontSee('Retired Category');
    }

    public function test_employee_cannot_reach_the_screen(): void
    {
        [$tenant, $user] = $this->tenantWithRole('employee');

        $this->actingAs($user)
            ->withSession(['current_tenant' => $tenant->id])
            ->get('/app/project-quick-create')
            ->assertForbidden();
    }

    public function test_manager_sees_the_new_project_nav_link(): void
    {
        [$tenant, $user] = $this->tenantWithRole('manager');

        $this->actingAs($user)
            ->withSession(['current_tenant' => $tenant->id])
            ->get(route('app.screen', 'dash'))
            ->assertOk()
            ->assertSee(route('app.screen', ['screen' => 'project-quick-create']));
    }

    public function test_employee_does_not_see_the_new_project_nav_link(): void
    {
        [$tenant, $user] = $this->tenantWithRole('employee');

        $this->actingAs($user)
            ->withSession(['current_tenant' => $tenant->id])
            ->get(route('app.screen', 'dash'))
            ->assertOk()
            ->assertDontSee(route('app.screen', ['screen' => 'project-quick-create']));
    }

    public function test_project_can_be_created_with_categories(): void
    {
        [$tenant, $user] = $this->tenantWithRole('manager');
        $dev = TimesheetCategory::create(['tenant_id' => $tenant->id, 'name' => 'Development', 'requires_project' => true]);
        $maint = TimesheetCategory::create(['tenant_id' => $tenant->id, 'name' => 'Maintenance', 'requires_project' => true]);

        $this->actingAs($user)
            ->withSession(['current_tenant' => $tenant->id])
            ->post(route('project-quick-create.store'), [
                'name' => 'KPT: RMS',
                'categories' => [$dev->id, $maint->id],
            ])
            ->assertRedirect();

        $project = Project::where('name', 'KPT: RMS')->firstOrFail();
        $this->assertSame([$dev->id, $maint->id], $project->categories->pluck('id')->sort()->values()->all());
    }

    public function test_categories_are_optional(): void
    {
        [$tenant, $user] = $this->tenantWithRole('manager');

        $this->actingAs($user)
            ->withSession(['current_tenant' => $tenant->id])
            ->post(route('project-quick-create.store'), ['name' => 'KPT: RMS'])
            ->assertRedirect();

        $project = Project::where('name', 'KPT: RMS')->firstOrFail();
        $this->assertTrue($project->categories->isEmpty());
    }

    public function test_name_is_required(): void
    {
        [$tenant, $user] = $this->tenantWithRole('hr');

        $response = $this->actingAs($user)
            ->withSession(['current_tenant' => $tenant->id])
            ->post(route('project-quick-create.store'), ['code' => 'KPT']);

        $response->assertSessionHasErrors('name');
    }

    public function test_name_must_be_unique_within_tenant(): void
    {
        [$tenant, $user] = $this->tenantWithRole('management');
        Project::create(['tenant_id' => $tenant->id, 'name' => 'KPT: RMS', 'is_active' => true]);

        $response = $this->actingAs($user)
            ->withSession(['current_tenant' => $tenant->id])
            ->post(route('project-quick-create.store'), ['name' => 'KPT: RMS']);

        $response->assertSessionHasErrors('name');
    }
}
