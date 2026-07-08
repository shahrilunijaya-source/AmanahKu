<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PettyCashFloat;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Company Settings org structure: privileged (HR/management) CRUD for branches +
 * departments, with role gating, in-use delete guards, and tenant isolation.
 * Harness mirrors PositionAdminTest.
 */
class OrgStructureAdminTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function actor(string $role): User
    {
        $this->seq++;
        $user = User::create(['name' => $role, 'email' => "{$role}{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    private function actingAsRole(string $role): self
    {
        $this->actingAs($this->actor($role))->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    // --- Branches -----------------------------------------------------------

    public function test_hr_can_create_a_branch(): void
    {
        $this->actingAsRole('hr')
            ->post('/app/admin/branches', ['name' => 'Klang', 'state' => 'Selangor'])
            ->assertRedirect();

        $branch = Branch::where('name', 'Klang')->first();
        $this->assertNotNull($branch);
        $this->assertSame($this->tenant->id, $branch->tenant_id);
        $this->assertSame('Selangor', $branch->state);
    }

    public function test_hr_can_rename_a_branch(): void
    {
        $this->actingAsRole('hr');
        $branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Old', 'state' => 'Perak']);

        $this->post("/app/admin/branches/{$branch->id}", ['name' => 'New', 'state' => 'Johor'])->assertRedirect();

        $this->assertSame('New', $branch->fresh()->name);
        $this->assertSame('Johor', $branch->fresh()->state);
    }

    public function test_hr_can_delete_an_unused_branch(): void
    {
        $this->actingAsRole('hr');
        $branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Temp', 'state' => 'Kedah']);

        $this->post("/app/admin/branches/{$branch->id}/delete")->assertRedirect();

        $this->assertNull(Branch::find($branch->id));
    }

    public function test_deleting_a_branch_with_employees_is_blocked(): void
    {
        $this->actingAsRole('hr');
        $branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Busy', 'state' => 'Selangor']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branch->id,
            'name' => 'Worker', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->post("/app/admin/branches/{$branch->id}/delete")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotNull(Branch::find($branch->id));
    }

    public function test_deleting_a_branch_with_only_archived_employees_is_allowed(): void
    {
        $this->actingAsRole('hr');
        $branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'GhostHQ', 'state' => 'Selangor']);
        // Archived staff are hidden from the directory, so HR can never reach them to
        // reassign — they must not be able to wedge the branch permanently.
        $archived = Employee::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branch->id,
            'name' => 'Departed', 'status' => 'active', 'workload' => 'green',
            'archived_at' => now(),
        ]);

        $this->post("/app/admin/branches/{$branch->id}/delete")
            ->assertRedirect()
            ->assertSessionHas('ok');

        $this->assertNull(Branch::find($branch->id));
        // The archived person survives; their branch_id nulls out via the FK.
        $this->assertNull($archived->fresh()->branch_id);
    }

    public function test_deleting_a_branch_with_petty_cash_is_blocked(): void
    {
        $this->actingAsRole('hr');
        $branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Cash', 'state' => 'Selangor']);
        PettyCashFloat::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'name' => 'Main float',
        ]);

        $this->post("/app/admin/branches/{$branch->id}/delete")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotNull(Branch::find($branch->id));
        $this->assertSame(1, PettyCashFloat::count());
    }

    // --- Departments --------------------------------------------------------

    public function test_hr_can_create_and_rename_a_department(): void
    {
        $this->actingAsRole('hr')
            ->post('/app/admin/departments', ['name' => 'Finance'])
            ->assertRedirect();

        $dept = Department::where('name', 'Finance')->first();
        $this->assertNotNull($dept);

        $this->post("/app/admin/departments/{$dept->id}", ['name' => 'Accounts'])->assertRedirect();
        $this->assertSame('Accounts', $dept->fresh()->name);
    }

    public function test_deleting_a_department_with_employees_is_blocked(): void
    {
        $this->actingAsRole('hr');
        $dept = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Ops']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'department_id' => $dept->id,
            'name' => 'Worker', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->post("/app/admin/departments/{$dept->id}/delete")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotNull(Department::find($dept->id));
    }

    public function test_deleting_a_department_with_only_archived_employees_is_allowed(): void
    {
        $this->actingAsRole('hr');
        $dept = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'GhostDept']);
        $archived = Employee::create([
            'tenant_id' => $this->tenant->id, 'department_id' => $dept->id,
            'name' => 'Departed', 'status' => 'active', 'workload' => 'green',
            'archived_at' => now(),
        ]);

        $this->post("/app/admin/departments/{$dept->id}/delete")
            ->assertRedirect()
            ->assertSessionHas('ok');

        $this->assertNull(Department::find($dept->id));
        $this->assertNull($archived->fresh()->department_id);
    }

    // --- Screen rendering / gating ------------------------------------------

    public function test_hr_sees_branch_and_department_controls_on_settings(): void
    {
        Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Klang', 'state' => 'Selangor']);
        Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Finance']);

        $html = $this->actingAsRole('hr')->get('/app/settings')->assertOk()->getContent();

        $this->assertStringContainsString('app/admin/branches', $html);
        $this->assertStringContainsString('app/admin/departments', $html);
    }

    public function test_feature_modules_are_grouped_by_nav_section(): void
    {
        $html = $this->actingAsRole('hr')->get('/app/settings')->assertOk()->getContent();

        // Section headings mirror the sidebar; multi-screen modules show a caption.
        $this->assertStringContainsString('Pay &amp; Benefits', $html);
        $this->assertStringContainsString('Talent &amp; Growth', $html);
        $this->assertStringContainsString('Controls: ', $html);
    }

    public function test_plain_employee_cannot_open_the_settings_screen(): void
    {
        // The whole Company Settings screen is admin-gated in AppController.
        $this->actingAsRole('employee')->get('/app/settings')->assertForbidden();
    }

    // --- Authorization + isolation ------------------------------------------

    public function test_plain_employee_cannot_create_a_branch(): void
    {
        $this->actingAsRole('employee')
            ->post('/app/admin/branches', ['name' => 'Sneaky'])
            ->assertForbidden();

        $this->assertSame(0, Branch::count());
    }

    public function test_line_manager_cannot_create_a_department(): void
    {
        $this->actingAsRole('manager')
            ->post('/app/admin/departments', ['name' => 'Sneaky'])
            ->assertForbidden();

        $this->assertSame(0, Department::count());
    }

    public function test_hr_cannot_touch_another_tenants_branch(): void
    {
        // A branch owned by a different tenant. assertTenant() in the controller
        // blocks the cross-tenant write (403); the row is never mutated.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreign = Branch::create(['tenant_id' => $other->id, 'name' => 'Foreign', 'state' => 'Sabah']);

        $this->actingAsRole('hr')
            ->post("/app/admin/branches/{$foreign->id}", ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Foreign', $foreign->fresh()->name);
    }
}
