<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Tenant;
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

    public function test_employee_cannot_reach_the_screen(): void
    {
        [$tenant, $user] = $this->tenantWithRole('employee');

        $this->actingAs($user)
            ->withSession(['current_tenant' => $tenant->id])
            ->get('/app/project-quick-create')
            ->assertForbidden();
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
