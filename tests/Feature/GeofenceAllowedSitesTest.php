<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Attendance\ScheduleResolver;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\WorkSite;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeofenceAllowedSitesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function site(string $name, float $lat, float $lng): WorkSite
    {
        return WorkSite::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'latitude' => $lat, 'longitude' => $lng, 'radius_m' => 200]);
    }

    private function clientWorker(WorkSite $primary): Employee
    {
        return Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'X', 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05', 'work_arrangement' => 'client', 'work_site_id' => $primary->id]);
    }

    public function test_without_pivot_rows_any_configured_site_matches(): void
    {
        $a = $this->site('Site A', 3.1000, 101.6000);
        $this->site('Site B', 3.2000, 101.7000);
        $e = $this->clientWorker($a);
        $r = app(ScheduleResolver::class);
        $spec = $r->matchActualSite($e, $r->resolve($e, now()), 3.2000, 101.7000);
        $this->assertSame('Site B', $spec->label);
    }

    public function test_with_pivot_rows_only_allowed_client_sites_match(): void
    {
        $a = $this->site('Site A', 3.1000, 101.6000);
        $this->site('Site B', 3.2000, 101.7000);
        $e = $this->clientWorker($a);
        $e->allowedWorkSites()->sync([$a->id => ['tenant_id' => $this->tenant->id]]);
        $r = app(ScheduleResolver::class);
        $spec = $r->matchActualSite($e, $r->resolve($e, now()), 3.2000, 101.7000);
        $this->assertSame('Site A', $spec->label); // fell back to the assigned site, Site B not allowed
    }
}
