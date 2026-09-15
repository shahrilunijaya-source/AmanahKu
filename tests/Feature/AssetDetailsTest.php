<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssetDetailsTest extends TestCase
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

    public function test_hr_sets_return_reference_and_remark(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $a = Asset::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'name' => 'Laptop', 'category' => 'laptop', 'status' => 'assigned']);
        $this->from("/app/profile?emp={$e->id}&tab=workinfo")->post("/app/assets/{$a->id}/details", ['returned_at' => '2026-06-01', 'reference_no' => 'REF-1', 'remark' => 'ok'])
            ->assertRedirect("/app/profile?emp={$e->id}&tab=workinfo");
        $a->refresh();
        $this->assertSame('2026-06-01', $a->returned_at->toDateString());
        $this->assertSame('REF-1', $a->reference_no);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Updated asset details']);
    }

    public function test_employee_is_forbidden(): void
    {
        $me = $this->login('employee');
        $a = Asset::create(['tenant_id' => $this->tenant->id, 'employee_id' => $me->id, 'name' => 'Laptop', 'category' => 'laptop', 'status' => 'assigned']);
        $this->post("/app/assets/{$a->id}/details", ['remark' => 'x'])->assertForbidden();
    }
}
