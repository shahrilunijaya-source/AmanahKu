<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `project.manage` opens the Projects register for writing. Editor roles hold it by
 * default; HR grants it one person at a time (a Project Exec on the employee role) on
 * the permission override screen. Adding a project also refuses names already on the
 * register in a slightly different spelling.
 */
class ProjectManagePermissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    public function test_a_granted_employee_can_add_edit_archive_and_delete_a_project(): void
    {
        $exec = $this->person('employee', grant: true);

        $this->as($exec)->postJson(route('projects.store'), $this->payload())->assertSuccessful();
        $project = Project::where('name', 'KPT: RMS')->sole();

        $this->as($exec)->post(route('projects.update', $project), ['name' => 'KPT: RMS Portal', 'status' => 'active'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('KPT: RMS Portal', $project->fresh()->name);

        $this->as($exec)->post(route('projects.archive', $project))->assertRedirect();
        $this->assertFalse((bool) $project->fresh()->is_active);

        $this->as($exec)->post(route('projects.delete', $project))->assertRedirect();
        $this->assertModelMissing($project);
    }

    public function test_a_granted_employee_sees_the_add_form(): void
    {
        $this->as($this->person('employee', grant: true))->get('/app/projects')
            ->assertOk()
            ->assertSee('action="'.route('projects.store').'"', false);
    }

    public function test_an_employee_without_the_grant_is_still_turned_away(): void
    {
        $staff = $this->person('employee');
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'SPA: IRIS', 'is_active' => true]);

        $this->as($staff)->postJson(route('projects.store'), $this->payload())->assertForbidden();
        $this->as($staff)->post(route('projects.update', $project), ['name' => 'Renamed'])->assertForbidden();
        $this->as($staff)->post(route('projects.archive', $project))->assertForbidden();
        $this->as($staff)->post(route('projects.delete', $project))->assertForbidden();
    }

    public function test_a_deny_override_shuts_a_manager_out(): void
    {
        $manager = $this->person('manager');
        UserPermission::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $manager->id,
            'permission' => 'project.manage', 'granted' => false,
        ]);

        $this->as($manager)->postJson(route('projects.store'), $this->payload())->assertForbidden();
    }

    /** The grant makes someone a project manager for the register, never finance. */
    public function test_a_granted_employee_cannot_set_money_fields(): void
    {
        $exec = $this->person('employee', grant: true);

        $this->as($exec)->postJson(route('projects.store'), $this->payload(['bond_value' => 5000, 'loa_ref' => 'LOA-1']))
            ->assertSuccessful();

        $project = Project::where('name', 'KPT: RMS')->sole();
        $this->assertNull($project->bond_value);
        $this->assertNull($project->loa_ref);

        $this->as($exec)->post(route('projects.update', $project), ['name' => 'KPT: RMS', 'bond_value' => 5000])
            ->assertForbidden();
    }

    public function test_a_manager_still_sets_money_fields_on_create(): void
    {
        $this->as($this->person('manager'))->postJson(route('projects.store'), $this->payload(['bond_value' => 5000]))
            ->assertSuccessful();

        $this->assertEquals(5000, Project::where('name', 'KPT: RMS')->sole()->bond_value);
    }

    public function test_the_same_name_in_a_different_spelling_is_blocked(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'JKDM : MyCustoms', 'is_active' => false]);

        $this->as($this->person('manager'))
            ->postJson(route('projects.store'), $this->payload(['name' => 'jkdm:mycustoms', 'confirm_duplicate' => 1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, Project::count());
    }

    public function test_a_close_name_asks_first_then_saves_once_confirmed(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KDN : POC iLPF', 'is_active' => true]);
        $manager = $this->person('manager');

        $this->as($manager)->postJson(route('projects.store'), $this->payload(['name' => 'KDN: iLPF']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm_duplicate')
            ->assertJsonFragment(['message' => 'Similar projects are already on the register: KDN : POC iLPF. Add "KDN: iLPF" anyway?']);
        $this->assertSame(1, Project::count());

        $this->as($manager)->postJson(route('projects.store'), $this->payload(['name' => 'KDN: iLPF', 'confirm_duplicate' => 1]))
            ->assertSuccessful();
        $this->assertSame(2, Project::count());
    }

    public function test_an_unrelated_name_from_the_same_agency_goes_straight_through(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KDN : TU Cloudflare', 'is_active' => true]);

        $this->as($this->person('manager'))->postJson(route('projects.store'), $this->payload(['name' => 'KDN : TU Gitlab']))
            ->assertSuccessful();
    }

    /** A different number marks a follow-on job (phase 2, batch 02), not a near miss. */
    public function test_a_numbered_follow_on_project_goes_straight_through(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS', 'is_active' => true]);

        $this->as($this->person('manager'))->postJson(route('projects.store'), $this->payload(['name' => 'KPT: RMS 2']))
            ->assertSuccessful();
    }

    public function test_projects_in_another_tenant_do_not_count_as_duplicates(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        Project::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'KPT: RMS', 'is_active' => true]);

        $this->as($this->person('manager'))->postJson(route('projects.store'), $this->payload())->assertSuccessful();
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'KPT: RMS', 'project_code' => 'KPT-RMS-2026-01', 'client' => 'KPT',
        ], $overrides);
    }

    private function person(string $role, bool $grant = false): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => $role.'@example.com', 'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ]);

        if ($grant) {
            UserPermission::create([
                'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
                'permission' => 'project.manage', 'granted' => true,
            ]);
        }

        return $user;
    }

    private function as(User $user): self
    {
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }
}
