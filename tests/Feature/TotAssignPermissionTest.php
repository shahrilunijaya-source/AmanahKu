<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\KnowledgeContribution;
use App\Models\Tenant;
use App\Models\TotSession;
use App\Models\User;
use App\Models\UserPermission;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
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

    public function test_a_holder_can_clear_the_presenter(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $session = $this->slot();
        $session->update(['presenter_employee_id' => $this->presenter->id]);

        $this->actingAsManager()->post("/app/tot/{$session->id}", ['presenter_employee_id' => '']);

        $fresh = $session->fresh();
        $this->assertNull($fresh->presenter_employee_id);
        $this->assertNull($fresh->presenter_name);
    }

    public function test_clearing_a_presenter_never_revokes_knowledge_bank_credit(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $session = $this->slot();
        $session->update(['presenter_employee_id' => $this->presenter->id]);
        app(CurrentTenant::class)->set($this->tenant);
        KnowledgeContribution::mark($this->presenter, 2026, 9);

        $this->actingAsManager()->post("/app/tot/{$session->id}", ['presenter_employee_id' => '']);

        $this->assertSame(1, KnowledgeContribution::where('employee_id', $this->presenter->id)
            ->where('year', 2026)->where('month', 9)->where('submitted', true)->count());
    }

    public function test_assigning_an_employee_clears_an_imported_nickname(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $session = $this->slot();
        $session->update(['presenter_name' => 'Kak Lin']);

        $this->actingAsManager()
            ->post("/app/tot/{$session->id}", ['presenter_employee_id' => $this->presenter->id]);

        $fresh = $session->fresh();
        $this->assertSame($this->presenter->id, $fresh->presenter_employee_id);
        $this->assertNull($fresh->presenter_name);
    }

    public function test_a_holder_can_create_a_slot_with_a_presenter(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        $this->actingAsManager()->post('/app/tot', [
            'year' => 2027, 'month' => 1,
            'presenter_employee_id' => $this->presenter->id,
        ])->assertRedirect();

        $created = TotSession::where('year', 2027)->where('month', 1)->firstOrFail();
        $this->assertSame($this->presenter->id, $created->presenter_employee_id);
        $this->assertSame('planned', $created->status);
        $this->assertNull($created->title);
    }

    public function test_a_holder_creating_a_slot_cannot_set_anything_else(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        $this->actingAsManager()->post('/app/tot', [
            'year' => 2027, 'month' => 2,
            'presenter_employee_id' => $this->presenter->id,
            'title' => 'Hijacked',
            'status' => 'done',
        ]);

        $created = TotSession::where('year', 2027)->where('month', 2)->firstOrFail();
        $this->assertSame('planned', $created->status);
        $this->assertNull($created->title);
    }

    public function test_a_holder_still_cannot_create_a_duplicate_slot(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $this->slot();

        $this->actingAsManager()->post('/app/tot', [
            'year' => 2026, 'month' => 9,
            'presenter_employee_id' => $this->presenter->id,
        ])->assertStatus(422);
    }

    public function test_a_manager_without_the_override_cannot_create_a_slot(): void
    {
        $this->seedWorkspace();

        $this->actingAsManager()->post('/app/tot', [
            'year' => 2027, 'month' => 3,
            'presenter_employee_id' => $this->presenter->id,
        ])->assertForbidden();

        $this->assertSame(0, TotSession::where('year', 2027)->count());
    }

    public function test_assigning_notifies_the_new_presenter_once(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $presenterUser = User::create([
            'name' => 'Nabil', 'email' => 'nabil@example.com', 'password' => Hash::make('password'),
        ]);
        $presenterUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->presenter->update(['user_id' => $presenterUser->id]);
        $session = $this->slot();

        $this->actingAsManager()
            ->post("/app/tot/{$session->id}", ['presenter_employee_id' => $this->presenter->id]);
        $this->actingAsManager()
            ->post("/app/tot/{$session->id}", ['presenter_employee_id' => $this->presenter->id]);

        $this->assertSame(1, AppNotification::where('user_id', $presenterUser->id)->count());
        $this->assertStringContainsString(
            'presenting TOT',
            (string) AppNotification::where('user_id', $presenterUser->id)->value('title')
        );
    }

    public function test_clearing_a_presenter_sends_nothing(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $session = $this->slot();
        $session->update(['presenter_employee_id' => $this->presenter->id]);

        $this->actingAsManager()->post("/app/tot/{$session->id}", ['presenter_employee_id' => '']);

        $this->assertSame(0, AppNotification::count());
    }

    public function test_every_presenter_change_writes_an_audit_row(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $session = $this->slot();

        $this->actingAsManager()
            ->post("/app/tot/{$session->id}", ['presenter_employee_id' => $this->presenter->id]);

        $this->assertSame(1, AuditLog::where('action', 'Assigned TOT presenter')->count());
    }

    public function test_the_screen_shows_a_holder_the_presenter_picker(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $this->slot();

        $this->actingAsManager()->get('/app/tot')
            ->assertOk()
            ->assertSee('name="presenter_employee_id"', false);
    }

    public function test_the_screen_hides_privileged_fields_from_a_holder(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $this->slot();

        $this->actingAsManager()->get('/app/tot')
            ->assertOk()
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="held_on"', false);
    }

    public function test_the_screen_shows_no_picker_without_the_override(): void
    {
        $this->seedWorkspace();
        $this->slot();

        $this->actingAsManager()->get('/app/tot')
            ->assertOk()
            ->assertDontSee('name="presenter_employee_id"', false);
    }
}
