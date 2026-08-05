<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A super-admin is a developer/support seat: they may enter ANY company workspace as
 * management, yet hold no membership and no staff record there, so nothing in that
 * company lists them. A non-member without the flag is still refused.
 */
class SuperAdminWorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        $this->superAdmin = User::create([
            'name' => 'Root',
            'email' => 'root@example.com',
            'password' => Hash::make('password'),
        ]);
        $this->superAdmin->forceFill(['is_super_admin' => true])->save();
    }

    public function test_super_admin_enters_a_company_they_are_not_a_member_of(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('tenant.enter', $this->tenant))
            ->assertRedirect(route('app.screen', 'dash'));

        $this->actingAs($this->superAdmin)
            ->get(route('app.screen', 'dash'))
            ->assertOk();
    }

    public function test_super_admin_acts_as_management_inside_the_company(): void
    {
        $this->assertSame('management', $this->superAdmin->roleIn($this->tenant));

        // Management-only screen, reachable without any membership row.
        $this->actingAs($this->superAdmin)->get(route('tenant.enter', $this->tenant));
        $this->actingAs($this->superAdmin)->get(route('app.screen', 'roles'))->assertOk();
    }

    public function test_super_admin_is_invisible_inside_the_company(): void
    {
        $this->actingAs($this->superAdmin)->get(route('tenant.enter', $this->tenant));

        // No membership row and no staff record → nothing in the company can list them.
        $this->assertFalse($this->tenant->users()->where('users.id', $this->superAdmin->id)->exists());
        $this->assertFalse(
            Employee::withoutGlobalScope('tenant')->where('user_id', $this->superAdmin->id)->exists()
        );
        // …so the Roles screen's member list has no row for them either.
        $members = $this->actingAs($this->superAdmin)
            ->get(route('app.screen', 'roles'))
            ->assertOk()
            ->viewData('members');
        $this->assertFalse($members->contains('id', $this->superAdmin->id));
    }

    public function test_super_admin_picker_lists_every_company(): void
    {
        Tenant::create(['slug' => 'bravo', 'name' => 'Bravo', 'initials' => 'BR']);

        $this->actingAs($this->superAdmin)
            ->get(route('tenant.select'))
            ->assertOk()
            ->assertSee('Alpha')
            ->assertSee('Bravo');
    }

    public function test_observer_may_write_anywhere_in_the_company(): void
    {
        $this->actingAs($this->superAdmin)->get(route('tenant.enter', $this->tenant));

        $this->actingAs($this->superAdmin)
            ->post(route('announcements.store'), ['title' => 'Hi', 'body' => 'Hi'])
            ->assertRedirect();

        $this->assertSame(1, Announcement::withoutGlobalScope('tenant')->count());
        // Invisible seat: the write happens, the company's ledger never mentions it.
        $this->assertSame(0, AuditLog::withoutGlobalScope('tenant')->count());
    }

    public function test_observer_may_write_org_setup_and_leaves_no_entry(): void
    {
        $this->actingAs($this->superAdmin)->get(route('tenant.enter', $this->tenant));

        $this->actingAs($this->superAdmin)
            ->post(route('admin.departments.store'), ['name' => 'Operation'])
            ->assertRedirect();

        $this->assertSame(1, Department::withoutGlobalScope('tenant')->where('name', 'Operation')->count());
        $this->assertSame(0, AuditLog::withoutGlobalScope('tenant')->count());
    }

    public function test_observer_may_edit_staff_and_leaves_no_entry(): void
    {
        $this->actingAs($this->superAdmin)->get(route('tenant.enter', $this->tenant));

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Staff', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('employees.update', $employee), ['name' => 'Renamed', 'status' => 'active'])
            ->assertRedirect();

        $this->assertSame('Renamed', $employee->fresh()->name);
        $this->assertSame(0, AuditLog::withoutGlobalScope('tenant')->count());
    }

    public function test_observer_records_no_audit_entry(): void
    {
        $this->actingAs($this->superAdmin);
        app(CurrentTenant::class)->set($this->tenant);

        AuditLog::record('Observed something', 'target');

        $this->assertSame(0, AuditLog::withoutGlobalScope('tenant')->count());
    }

    public function test_a_real_member_still_writes_and_is_audited(): void
    {
        $hr = User::create(['name' => 'HR', 'email' => 'hr@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr', 'data_scope' => 'company']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $hr->id, 'name' => 'HR', 'status' => 'active', 'workload' => 'green']);

        $this->actingAs($hr)->get(route('tenant.enter', $this->tenant));
        $this->actingAs($hr)->post(route('announcements.store'), ['title' => 'Hi', 'body' => 'Hi']);

        $this->assertSame(1, Announcement::withoutGlobalScope('tenant')->count());
        $this->assertSame(1, AuditLog::withoutGlobalScope('tenant')->count());
    }

    public function test_plain_non_member_is_still_refused(): void
    {
        $outsider = User::create([
            'name' => 'Outsider',
            'email' => 'outsider@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($outsider)->get(route('tenant.enter', $this->tenant))->assertForbidden();
        $this->actingAs($outsider)->get(route('app.screen', 'dash'))->assertRedirect(route('tenant.select'));
    }
}
