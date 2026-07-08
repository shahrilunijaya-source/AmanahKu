<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\Employee;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AK-DB-06 — BelongsToTenant is fail-closed on writes: a tenant-owned row created with
 * neither an active tenant context NOR an explicit tenant_id is refused, so a future
 * queued job or command that forgets to set CurrentTenant can't write unscoped rows.
 */
class TenantWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);

        // Ensure no ambient tenant context leaks in from setUp.
        app(CurrentTenant::class)->set(null);
    }

    public function test_creating_a_tenant_owned_model_with_no_context_and_no_tenant_id_throws(): void
    {
        $this->assertFalse(app(CurrentTenant::class)->check());

        $this->expectException(\RuntimeException::class);

        Achievement::create([
            'employee_id' => $this->employee->id,
            'title' => 'Unscoped write',
            'points' => 10,
        ]);
    }

    public function test_explicit_tenant_id_with_no_context_still_writes(): void
    {
        // The seeder / admin pattern: no active tenant, but tenant_id passed explicitly.
        $achievement = Achievement::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'title' => 'Explicitly scoped',
            'points' => 20,
        ]);

        $this->assertDatabaseHas('achievements', [
            'id' => $achievement->id,
            'tenant_id' => $this->tenant->id,
            'title' => 'Explicitly scoped',
        ]);
    }

    public function test_active_context_auto_fills_tenant_id(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        $achievement = Achievement::create([
            'employee_id' => $this->employee->id,
            'title' => 'Context filled',
            'points' => 30,
        ]);

        $this->assertSame($this->tenant->id, $achievement->tenant_id);
    }
}
