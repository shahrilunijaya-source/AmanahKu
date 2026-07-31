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
use App\Services\FeatureManager;
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

    public function test_a_holder_sees_the_create_form_on_an_empty_month(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        $this->actingAsManager()->get('/app/tot')
            ->assertOk()
            ->assertSee('Assign PIC')
            ->assertDontSee('name="presenter_name"', false);
    }

    public function test_a_manager_without_the_override_sees_no_create_form(): void
    {
        $this->seedWorkspace();

        $this->actingAsManager()->get('/app/tot')
            ->assertOk()
            ->assertDontSee('Assign PIC');
    }

    /**
     * A holder gets no validation rule for the material, so rendering those fields to them
     * would show controls the save silently discards. That is the AK-AUTHZ-04 pattern this
     * codebase already names: never show a control that does nothing.
     */
    public function test_a_holder_sees_no_material_fields_on_a_saved_slot(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $this->slot();

        $this->actingAsManager()->get('/app/tot')
            ->assertOk()
            ->assertSee('name="presenter_employee_id"', false)
            ->assertDontSee('name="starts_at"', false);
    }

    public function test_the_presenter_of_a_slot_still_sees_the_material_fields(): void
    {
        $this->seedWorkspace();
        $session = $this->slot();
        $presenterEmployee = Employee::where('user_id', $this->manager->id)->firstOrFail();
        $session->update(['presenter_employee_id' => $presenterEmployee->id]);

        $this->actingAsManager()->get('/app/tot')
            ->assertOk()
            ->assertSee('name="starts_at"', false);
    }

    public function test_a_holder_can_reach_the_roster_screen(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        $this->actingAsManager()->get('/app/tot-roster')->assertOk();
    }

    public function test_a_manager_without_the_override_cannot_reach_the_roster(): void
    {
        $this->seedWorkspace();

        $this->actingAsManager()->get('/app/tot-roster')->assertForbidden();
    }

    public function test_the_roster_renders_a_year_that_has_no_rows(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        $response = $this->actingAsManager()->get('/app/tot-roster?year=2031');

        $response->assertOk()->assertSee('2031');
        $this->assertSame(0, TotSession::where('year', 2031)->count());
    }

    public function test_the_roster_lists_every_assignable_person(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        $this->actingAsManager()->get('/app/tot-roster?year=2026')
            ->assertOk()
            ->assertSee($this->presenter->name);
    }

    public function test_the_roster_is_gated_by_the_knowledge_module(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        app(FeatureManager::class)->setTenant($this->tenant, 'module.knowledge', false);

        // AppController aborts 404, not 403, for a screen whose module is off:
        // a disabled module should look absent, not forbidden.
        $this->actingAsManager()->get('/app/tot-roster')->assertNotFound();
    }

    public function test_a_holder_assigning_from_the_roster_creates_a_planned_slot(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        $this->actingAsManager()->post('/app/tot', [
            'year' => 2026, 'month' => 5,
            'presenter_employee_id' => $this->presenter->id,
        ])->assertRedirect();

        $session = TotSession::where('year', 2026)->where('month', 5)->first();

        $this->assertNotNull($session);
        $this->assertSame('planned', $session->status);
        $this->assertSame($this->presenter->id, $session->presenter_employee_id);
    }

    public function test_assigning_over_an_existing_slot_changes_only_the_presenter(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        $session = TotSession::create([
            'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 5,
            'title' => 'Barcode rollout', 'status' => 'done',
        ]);

        $this->actingAsManager()->post("/app/tot/{$session->id}", [
            'totform' => $session->id,
            'presenter_employee_id' => $this->presenter->id,
        ])->assertRedirect();

        $session->refresh();

        $this->assertSame($this->presenter->id, $session->presenter_employee_id);
        $this->assertSame('Barcode rollout', $session->title, 'the roster must not touch the material');
        $this->assertSame('done', $session->status, 'the roster must not touch the status');
    }

    public function test_hr_can_assign_from_the_roster_without_sending_a_status(): void
    {
        $this->seedWorkspace();

        $hr = User::create(['name' => 'HR', 'email' => 'hr-roster@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        // store() puts status in the rule set only for a privileged role, where it is
        // required. The roster sends status=planned so HR and a tot.assign holder both
        // create the same row instead of HR alone getting a 422.
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/tot', [
                'year' => 2026, 'month' => 7,
                'presenter_employee_id' => $this->presenter->id,
                'status' => 'planned',
            ])->assertRedirect();

        $session = TotSession::where('year', 2026)->where('month', 7)->first();

        $this->assertNotNull($session, 'HR must be able to assign from the roster');
        $this->assertSame('planned', $session->status);
        $this->assertSame($this->presenter->id, $session->presenter_employee_id);
    }

    public function test_creating_a_slot_returns_its_id_to_a_json_caller(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        // The roster needs the new id, or it keeps thinking the month is unsaved and
        // re-POSTs to store(), which the duplicate guard then rejects.
        $response = $this->actingAsManager()->postJson('/app/tot', [
            'year' => 2026, 'month' => 8,
            'presenter_employee_id' => $this->presenter->id,
        ]);

        $session = TotSession::where('year', 2026)->where('month', 8)->firstOrFail();

        $response->assertOk()->assertJsonPath('id', $session->id);
    }

    public function test_a_plain_form_create_still_redirects(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        $this->actingAsManager()->post('/app/tot', [
            'year' => 2026, 'month' => 9,
            'presenter_employee_id' => $this->presenter->id,
        ])->assertRedirect();
    }

    public function test_the_board_offers_a_holder_the_roster_link(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        $this->actingAsManager()->get('/app/tot')
            ->assertOk()
            ->assertSee('tot-roster', false);
    }

    public function test_the_board_hides_the_roster_link_without_the_override(): void
    {
        $this->seedWorkspace();

        $this->actingAsManager()->get('/app/tot')
            ->assertOk()
            ->assertDontSee('tot-roster', false);
    }
}
