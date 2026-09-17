<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeProgressionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    public function test_progression_row_round_trips_and_employee_has_new_columns(): void
    {
        $e = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Adibah', 'status' => 'probation', 'workload' => 'green',
            'joined_at' => '2026-01-05', 'probation_months' => 6, 'pay_mode' => 'monthly', 'payment_term' => 'monthly',
            'payment_method' => 'bank', 'division' => 'Senior', 'section' => 'PMO',
        ]);
        $row = EmployeeProgression::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'type' => 'hired',
            'effective_on' => '2026-01-05', 'snapshot' => ['position' => 'PE'], 'changed_fields' => [],
        ]);

        $this->assertSame(6, $e->fresh()->probation_months);
        $this->assertSame('PMO', $e->fresh()->section);
        $this->assertSame(['position' => 'PE'], $row->fresh()->snapshot);
        $this->assertSame('2026-01-05', $row->fresh()->effective_on->toDateString());
        $this->assertCount(1, $e->progressions);
    }
}
