<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\Employee;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSegment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 5 performance regressions:
 *  - AK-PERF-03: the recognition leaderboard is ordered + has-achievements-filtered in SQL
 *    now, not hydrate-all-then-sort-in-PHP. Order and exclusion must be preserved.
 *  - AK-PERF-04: the Knowledge Bank entry list is paginated, so page 2 is reachable.
 */
class Phase5PerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        // Context set so model creation in the tests writes with the tenant filled in.
        app(CurrentTenant::class)->set($this->tenant);

        $this->user = User::create(['name' => 'Viewer', 'email' => 'viewer@example.com', 'password' => Hash::make('password')]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->viewer = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Viewer', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function employeeWithPoints(string $name, int $points): Employee
    {
        $e = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'initials' => mb_substr($name, 0, 2),
            'status' => 'active', 'workload' => 'green',
        ]);
        Achievement::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'title' => $name.' win', 'category' => 'Award', 'points' => $points, 'date' => '2026-06-20',
        ]);

        return $e;
    }

    public function test_leaderboard_orders_by_points_and_excludes_employees_with_no_achievements(): void
    {
        $this->employeeWithPoints('Bravalpha', 100);
        $this->employeeWithPoints('Charlmax', 300);
        $this->employeeWithPoints('Alphamid', 200);
        // No achievements — must not appear on the leaderboard (whereHas filter).
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Zephyrnone', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingInTenant()->get('/app/achievements')
            ->assertOk()
            ->assertSeeInOrder(['Charlmax', 'Alphamid', 'Bravalpha'])
            ->assertDontSee('Zephyrnone');
    }

    public function test_knowledge_bank_entries_paginate(): void
    {
        $segment = KnowledgeSegment::create([
            'tenant_id' => $this->tenant->id, 'label' => 'Lessons', 'sort_order' => 1,
        ]);

        for ($i = 1; $i <= 25; $i++) {
            KnowledgeEntry::create([
                'tenant_id' => $this->tenant->id, 'seg_id' => $segment->id, 'employee_id' => $this->viewer->id,
                'title' => "Lesson $i", 'body' => "Body of lesson $i",
            ]);
        }

        // 25 entries > page size 20 → a second page must be linked.
        $this->actingInTenant()->get('/app/knowledge-bank')
            ->assertOk()
            ->assertSee('page=2');

        // Page 2 renders the tail of the list.
        $this->actingInTenant()->get('/app/knowledge-bank?page=2')->assertOk();
    }
}
