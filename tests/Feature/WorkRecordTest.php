<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkSite;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkRecordTest extends TestCase
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
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05',
        ], $attrs));
    }

    public function test_hr_updates_work_fields_and_allowed_sites(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $s1 = WorkSite::create(['tenant_id' => $this->tenant->id, 'name' => 'A']);
        $s2 = WorkSite::create(['tenant_id' => $this->tenant->id, 'name' => 'B']);
        $this->post("/app/employees/{$e->id}/work", ['attendance_id' => 'ATT-7', 'work_phone' => '03-999', 'benefit_start_at' => '2026-05-01', 'work_site_id' => $s1->id, 'allowed_work_sites' => [$s1->id, $s2->id]])
            ->assertRedirect("/app/profile?emp={$e->id}&tab=workinfo");
        $e->refresh();
        $this->assertSame('ATT-7', $e->attendance_id);
        $this->assertSame($s1->id, $e->work_site_id);
        $this->assertEqualsCanonicalizing([$s1->id, $s2->id], $e->allowedWorkSites->pluck('id')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'Updated work details']);
    }

    public function test_empty_allowed_list_clears_the_pivot(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $s1 = WorkSite::create(['tenant_id' => $this->tenant->id, 'name' => 'A']);
        $e->allowedWorkSites()->sync([$s1->id => ['tenant_id' => $this->tenant->id]]);
        $this->post("/app/employees/{$e->id}/work", ['allowed_work_sites' => ''])->assertRedirect();
        $this->assertSame([], $e->fresh()->allowedWorkSites->pluck('id')->all());
    }

    public function test_employee_cannot_update_own_work_fields(): void
    {
        $me = $this->login('employee');
        $this->post("/app/employees/{$me->id}/work", ['attendance_id' => 'X'])->assertForbidden();
    }

    public function test_site_from_another_tenant_is_rejected(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreign = WorkSite::create(['tenant_id' => $other->id, 'name' => 'Foreign']);
        $this->from('/app/profile')->post("/app/employees/{$e->id}/work", ['work_site_id' => $foreign->id])->assertSessionHasErrors('work_site_id')->assertSessionHas('form', 'work');
    }

    public function test_cross_tenant_employee_is_404(): void
    {
        $this->login('hr');
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $e = Employee::create(['tenant_id' => $other->id, 'name' => 'Z', 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05']);
        $this->post("/app/employees/{$e->id}/work", ['attendance_id' => 'X'])->assertNotFound();
    }
}
