<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\WorkSite;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_columns_pivot_and_asset_details_round_trip(): void
    {
        $t = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($t);
        $site = WorkSite::create(['tenant_id' => $t->id, 'name' => 'Client HQ']);
        $e = Employee::create(['tenant_id' => $t->id, 'name' => 'Adibah', 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05',
            'attendance_id' => 'ATT-001', 'work_phone' => '03-1234', 'benefit_start_at' => '2026-04-05']);
        $e->allowedWorkSites()->sync([$site->id => ['tenant_id' => $t->id]]);
        $a = Asset::create(['tenant_id' => $t->id, 'employee_id' => $e->id, 'name' => 'Laptop', 'category' => 'laptop', 'status' => 'assigned',
            'assigned_at' => '2026-01-05', 'returned_at' => '2026-06-01', 'reference_no' => 'REF-9', 'remark' => 'Scratched lid']);

        $e = $e->fresh();
        $this->assertSame('ATT-001', $e->attendance_id);
        $this->assertSame('2026-04-05', $e->benefit_start_at->toDateString());
        $this->assertSame([$site->id], $e->allowedWorkSites->pluck('id')->all());
        $this->assertSame('2026-06-01', $a->fresh()->returned_at->toDateString());
        $this->assertSame('REF-9', $a->fresh()->reference_no);
    }
}
