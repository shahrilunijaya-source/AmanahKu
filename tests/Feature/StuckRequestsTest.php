<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Support\StuckRequests;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A two-step request from a requester with no reporting-line superior is stuck forever.
 * StuckRequests surfaces exactly those for HR (AK-PROC-04).
 */
class StuckRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_submitted_two_step_requests_from_staff_with_no_superior(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($tenant);

        // Orphan (no superior) with a submitted leave + claim → both stuck.
        $orphan = Employee::create(['tenant_id' => $tenant->id, 'name' => 'Orphan', 'status' => 'active', 'workload' => 'green', 'reports_to_id' => null]);
        LeaveRequest::create(['tenant_id' => $tenant->id, 'employee_id' => $orphan->id, 'date_from' => '2026-07-06', 'date_to' => '2026-07-07', 'days' => 2, 'status' => 'submitted']);
        Claim::create(['tenant_id' => $tenant->id, 'employee_id' => $orphan->id, 'type' => 'expense', 'title' => 'X', 'amount' => 50, 'date' => '2026-07-01', 'status' => 'submitted']);

        // Has a superior → routed, NOT stuck.
        $boss = Employee::create(['tenant_id' => $tenant->id, 'name' => 'Boss', 'status' => 'active', 'workload' => 'green']);
        $routed = Employee::create(['tenant_id' => $tenant->id, 'name' => 'Routed', 'status' => 'active', 'workload' => 'green', 'reports_to_id' => $boss->id]);
        LeaveRequest::create(['tenant_id' => $tenant->id, 'employee_id' => $routed->id, 'date_from' => '2026-07-06', 'date_to' => '2026-07-07', 'days' => 2, 'status' => 'submitted']);

        // Orphan but already actioned (approved) → not pending, not stuck.
        Claim::create(['tenant_id' => $tenant->id, 'employee_id' => $orphan->id, 'type' => 'expense', 'title' => 'Old', 'amount' => 10, 'date' => '2026-06-01', 'status' => 'approved']);

        $stuck = app(StuckRequests::class)->forCurrentTenant();

        $this->assertCount(2, $stuck);
        $this->assertEqualsCanonicalizing(['Leave', 'Claim'], $stuck->pluck('type')->all());
        $this->assertSame(['Orphan'], $stuck->pluck('employee')->unique()->values()->all());
    }

    public function test_it_excludes_archived_staff_who_are_detached_from_all_obligations(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($tenant);

        // Orphan (no superior) but ARCHIVED → detached from obligations, must NOT be stuck.
        $archived = Employee::create(['tenant_id' => $tenant->id, 'name' => 'Gone', 'status' => 'active', 'workload' => 'green', 'reports_to_id' => null, 'archived_at' => now()]);
        LeaveRequest::create(['tenant_id' => $tenant->id, 'employee_id' => $archived->id, 'date_from' => '2026-07-06', 'date_to' => '2026-07-07', 'days' => 2, 'status' => 'submitted']);
        Claim::create(['tenant_id' => $tenant->id, 'employee_id' => $archived->id, 'type' => 'expense', 'title' => 'X', 'amount' => 50, 'date' => '2026-07-01', 'status' => 'submitted']);

        $this->assertCount(0, app(StuckRequests::class)->forCurrentTenant());
    }
}
