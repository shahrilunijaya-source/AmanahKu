<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TotSession;
use App\Models\User;
use App\Models\UserPermission;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TotAssignPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $manager;

    private Employee $presenter;

    private function seedWorkspace(): void
    {
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $this->manager = User::create([
            'name' => 'Kussairi', 'email' => 'kus@example.com', 'password' => Hash::make('password'),
        ]);
        $this->manager->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->manager->id,
            'name' => 'Kussairi', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->presenter = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Nabil', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function grantAssign(): void
    {
        UserPermission::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->manager->id,
            'permission' => 'tot.assign',
            'granted' => true,
        ]);
    }

    private function actingAsManager(): self
    {
        $this->actingAs($this->manager)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function slot(): TotSession
    {
        return TotSession::create([
            'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 9,
            'title' => 'Original title', 'status' => 'planned',
        ]);
    }

    public function test_hr_and_management_hold_tot_assign_by_role(): void
    {
        $this->assertTrue(Permissions::roleHas('hr', 'tot.assign'));
        $this->assertTrue(Permissions::roleHas('management', 'tot.assign'));
        $this->assertTrue(Permissions::roleHas('director', 'tot.assign'));
    }

    public function test_manager_and_employee_do_not_hold_it_by_role(): void
    {
        $this->assertFalse(Permissions::roleHas('manager', 'tot.assign'));
        $this->assertFalse(Permissions::roleHas('employee', 'tot.assign'));
    }

    public function test_it_is_overridable_and_grouped_under_tot(): void
    {
        $this->assertContains('tot.assign', Permissions::overridable());
        $this->assertSame(['tot.assign'], Permissions::overridableGrouped()['tot'] ?? []);
    }

    public function test_a_holder_can_set_the_presenter(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $session = $this->slot();

        $this->actingAsManager()
            ->post("/app/tot/{$session->id}", ['presenter_employee_id' => $this->presenter->id])
            ->assertRedirect();

        $this->assertSame($this->presenter->id, $session->fresh()->presenter_employee_id);
    }

    public function test_a_holder_cannot_change_anything_else(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $session = $this->slot();

        $this->actingAsManager()->post("/app/tot/{$session->id}", [
            'presenter_employee_id' => $this->presenter->id,
            'title' => 'Hijacked',
            'status' => 'done',
            'held_on' => '2026-09-05',
        ]);

        $fresh = $session->fresh();
        $this->assertSame($this->presenter->id, $fresh->presenter_employee_id);
        $this->assertSame('Original title', $fresh->title);
        $this->assertSame('planned', $fresh->status);
        $this->assertNull($fresh->held_on);
    }

    public function test_a_manager_without_the_override_cannot_set_a_presenter(): void
    {
        $this->seedWorkspace();
        $session = $this->slot();

        $this->actingAsManager()
            ->post("/app/tot/{$session->id}", ['presenter_employee_id' => $this->presenter->id])
            ->assertForbidden();

        $this->assertNull($session->fresh()->presenter_employee_id);
    }

    public function test_a_revoked_override_takes_the_ability_away(): void
    {
        $this->seedWorkspace();
        UserPermission::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->manager->id,
            'permission' => 'tot.assign',
            'granted' => false,
        ]);
        $session = $this->slot();

        $this->actingAsManager()
            ->post("/app/tot/{$session->id}", ['presenter_employee_id' => $this->presenter->id])
            ->assertForbidden();
    }

    public function test_a_holder_cannot_reach_the_roles_screen(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        $this->actingAsManager()->get('/app/roles')->assertForbidden();
    }
}
