<?php

namespace Tests\Feature;

use App\Http\Controllers\TimesheetController;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TimesheetReviewWeekPanelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $employee;

    private TimesheetCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-19 12:00:00');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Ops', 'requires_project' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_reportdata_week_blocks_carry_week_start_and_line_id(): void
    {
        $ts = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 8,
        ]);
        $entry = $ts->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->category->id, 'percentage' => 100, 'hours' => 8,
        ]);

        app(CurrentTenant::class)->set($this->tenant);
        $request = Request::create('/app/timesheet-reports', 'GET', ['from' => '2026-06-15', 'to' => '2026-06-19']);
        $request->attributes->set('tenantRole', 'hr');
        $request->attributes->set('tenantScope', 'company');

        $data = app(TimesheetController::class)->reportData($request, $this->employee);

        $week = $data['staffWeeks'][$this->employee->id][0];
        $this->assertSame('2026-06-15', $week['weekStart']);
        $this->assertNull($week['status']); // reportData() doesn't pass $timesheetsByWeekStart
        $this->assertSame($entry->id, $week['lines'][0]['id']);
    }
}
