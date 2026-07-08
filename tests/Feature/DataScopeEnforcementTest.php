<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataScope;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A branch/department-restricted manager must only see their slice of the company on the
 * "see all" screens, not just on the directory (AK-AUTHZ-01). Covers both scoping paths:
 * applyToEmployees() (export, team board) and visibleEmployeeIds() (attendance/timesheet
 * reports).
 */
class DataScopeEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $manager;

    private Employee $inBranchA;

    private Employee $inBranchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $branchA = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Branch A']);
        $branchB = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Branch B']);

        // The viewing manager is restricted to Branch A.
        $this->manager = User::create(['name' => 'Mgr', 'email' => 'mgr@example.com', 'password' => Hash::make('password')]);
        $this->manager->tenants()->attach($this->tenant->id, ['role' => 'manager', 'data_scope' => 'branch']);
        $this->inBranchA = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->manager->id, 'branch_id' => $branchA->id,
            'name' => 'Alice in A', 'email' => 'alice@example.com', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->inBranchB = Employee::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branchB->id,
            'name' => 'Bob in B', 'email' => 'bob@example.com', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    public function test_visible_employee_ids_narrows_to_the_scoped_branch(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        $ids = app(DataScope::class)->visibleEmployeeIds('branch', $this->inBranchA);

        $this->assertContains($this->inBranchA->id, $ids);
        $this->assertNotContains($this->inBranchB->id, $ids);
    }

    public function test_company_scope_is_unrestricted(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        // null signals "no constraint" so callers skip the whereIn entirely.
        $this->assertNull(app(DataScope::class)->visibleEmployeeIds('company', $this->inBranchA));
    }

    public function test_branch_scoped_export_excludes_other_branch_staff(): void
    {
        $content = $this->actingAs($this->manager)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/reports/export/employees')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Alice in A', $content);
        $this->assertStringNotContainsString('Bob in B', $content);
    }
}
