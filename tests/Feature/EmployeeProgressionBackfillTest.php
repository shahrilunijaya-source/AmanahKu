<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeProgressionBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function emp(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'probation', 'workload' => 'green',
            'joined_at' => '2026-01-05',
        ], $attrs));
    }

    public function test_backfill_writes_one_hired_row_per_non_archived_employee(): void
    {
        $a = $this->emp('A', ['joined_at' => '2025-02-01']);
        $this->emp('B', ['joined_at' => '2025-03-01']);
        $this->emp('Gone', ['archived_at' => now()]);

        (require database_path('migrations/2026_09_26_100200_backfill_hired_progressions.php'))->up();

        $this->assertSame(2, EmployeeProgression::where('type', 'hired')->count());
        $this->assertSame('2025-02-01', EmployeeProgression::where('employee_id', $a->id)->first()->effective_on->toDateString());
        $this->assertFalse(Schema::hasTable('career_timeline'));
    }
}
