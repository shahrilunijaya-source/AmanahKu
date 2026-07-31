<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Services\MandayRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Costing rule: manday = (max_salary * 1.8) / 20, manhour = manday / 8.
 * Timesheet cost = total hours * manhour rate of the owner's position band.
 */
class MandayRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private MandayRateService $svc;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new MandayRateService;
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function position(float $maxSalary): Position
    {
        return Position::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Developer', 'max_salary' => $maxSalary,
        ]);
    }

    public function test_manday_and_manhour_rate_derive_from_the_band(): void
    {
        // Arrange
        $position = $this->position(5000);

        // Act + Assert — (5000 * 1.8) / 20 = 450/day; 450 / 8 = 56.25/hour.
        $this->assertSame(450.0, $this->svc->mandayRate($position));
        $this->assertSame(56.25, $this->svc->manhourRate($position));
    }

    public function test_timesheet_cost_is_hours_times_manhour_rate(): void
    {
        // Arrange — employee on the 5000 band, 16 hours logged.
        $position = $this->position(5000);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Dev', 'status' => 'active',
            'workload' => 'green', 'position_id' => $position->id,
        ]);
        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 16,
        ]);

        // Act + Assert — 16h * 56.25 = 900.00.
        $this->assertSame(900.0, $this->svc->timesheetCost($timesheet));
    }

    public function test_timesheet_cost_is_null_when_no_position_assigned(): void
    {
        // Arrange — employee without a position band.
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Dev', 'status' => 'active', 'workload' => 'green',
        ]);
        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 16,
        ]);

        // Act + Assert
        $this->assertNull($this->svc->timesheetCost($timesheet));
    }
}
