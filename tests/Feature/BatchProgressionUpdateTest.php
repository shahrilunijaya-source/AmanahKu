<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Batch Progression Update: many staff, same fields, all-or-nothing. */
class BatchProgressionUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function login(string $role, array $attrs = []): Employee
    {
        $user = User::create(['name' => ucfirst($role), 'email' => $role.'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $employee = Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green', 'joined_at' => '2025-01-06',
        ], $attrs));
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    private function emp(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'probation', 'workload' => 'green',
            'joined_at' => '2026-01-05',
        ], $attrs));
    }

    public function test_hr_updates_many_staff_in_one_run(): void
    {
        $this->login('hr');
        $d = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Ops']);
        $a = $this->emp('A');
        $b = $this->emp('B', ['status' => 'active']);
        $this->post('/app/progression/batch/update', ['employee_ids' => [$a->id, $b->id], 'effective_on' => '2026-03-01', 'update_type' => 'role_transfer', 'fields' => ['department_id', 'payment_term'], 'department_id' => $d->id, 'payment_term' => 'weekly', 'remark' => 'Batch'])
            ->assertRedirect('/app/progression?batch=update');
        $this->assertSame($d->id, $a->fresh()->department_id);
        $this->assertSame('weekly', $b->fresh()->payment_term);
        $this->assertSame(2, EmployeeProgression::where('type', 'updated')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'Batch progression update']);
    }

    public function test_one_resigned_person_rolls_back_the_whole_batch(): void
    {
        $this->login('hr');
        $d = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Ops']);
        $a = $this->emp('A');
        $gone = $this->emp('Gone', ['status' => 'resigned']);
        $this->from('/app/progression?batch=update')->post('/app/progression/batch/update', ['employee_ids' => [$a->id, $gone->id], 'effective_on' => '2026-03-01', 'update_type' => 'role_transfer', 'fields' => ['department_id'], 'department_id' => $d->id])
            ->assertRedirect('/app/progression?batch=update')->assertSessionHasErrors('employee_ids');
        $this->assertNull($a->fresh()->department_id);
        $this->assertSame(0, EmployeeProgression::where('type', 'updated')->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'Batch progression update']);
    }

    public function test_unticked_fields_are_ignored_and_over_200_is_refused(): void
    {
        $this->login('hr');
        $a = $this->emp('A');
        $this->post('/app/progression/batch/update', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-01', 'update_type' => 'role_transfer', 'fields' => ['division'], 'division' => 'North', 'section' => 'Ignored'])->assertRedirect();
        $this->assertSame('North', $a->fresh()->division);
        $this->assertNull($a->fresh()->section);
        $ids = array_fill(0, 201, $a->id);
        $this->post('/app/progression/batch/update', ['employee_ids' => $ids, 'effective_on' => '2026-03-01', 'update_type' => 'role_transfer', 'fields' => ['division'], 'division' => 'X'])->assertSessionHasErrors('employee_ids');
    }

    public function test_other_tenant_staff_are_refused(): void
    {
        $this->login('hr');
        $other = Tenant::create(['slug' => 'zeta', 'name' => 'Zeta', 'initials' => 'ZT']);
        $foreign = Employee::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'F', 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05']);
        $this->post('/app/progression/batch/update', ['employee_ids' => [$foreign->id], 'effective_on' => '2026-03-01', 'update_type' => 'role_transfer', 'fields' => ['division'], 'division' => 'X'])->assertSessionHasErrors('employee_ids.0');
        $this->assertNull($foreign->fresh()->division);
    }

    public function test_manager_and_employee_are_forbidden(): void
    {
        $a = $this->emp('A');
        $this->login('manager');
        $this->post('/app/progression/batch/update', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-01', 'update_type' => 'role_transfer', 'fields' => ['division'], 'division' => 'X'])->assertForbidden();
        $this->login('employee');
        $this->post('/app/progression/batch/update', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-01', 'update_type' => 'role_transfer', 'fields' => ['division'], 'division' => 'X'])->assertForbidden();
    }
}
