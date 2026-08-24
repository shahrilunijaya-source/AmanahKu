<?php

namespace Tests\Feature;

use App\Http\Controllers\TimesheetController;
use App\Models\Department;
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

/**
 * The report tab's period and people controls: Week/Month with an offset to step
 * back through, a custom pair of dates, and narrowing by department or name — the
 * same set the attendance ledger has.
 */
class TimesheetReportFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hrUser;

    private Employee $hrEmployee;

    private TimesheetCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-19 12:00:00');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hrUser = User::create(['name' => 'HR', 'email' => 'hr@example.com', 'password' => Hash::make('password')]);
        $this->hrUser->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->hrEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->hrUser->id,
            'name' => 'HR', 'status' => 'active', 'workload' => 'green',
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

    private function staffWithDay(string $name, string $date, ?Department $department = null): Employee
    {
        $emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => $name,
            'status' => 'active', 'workload' => 'green',
            'department_id' => $department?->id,
        ]);
        $ts = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $emp->id,
            'week_start' => Carbon::parse($date)->startOfWeek()->toDateString(),
            'status' => 'submitted', 'total_hours' => 8,
        ]);
        $ts->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => $date,
            'category_id' => $this->category->id, 'percentage' => 100, 'hours' => 8,
        ]);

        return $emp;
    }

    /** @param array<string, string> $query */
    private function report(array $query): array
    {
        app(CurrentTenant::class)->set($this->tenant);
        $request = Request::create('/app/timesheet-reports', 'GET', $query);
        $request->attributes->set('tenantRole', 'hr');
        $request->attributes->set('tenantScope', 'company');

        return app(TimesheetController::class)->reportData($request, $this->hrEmployee);
    }

    public function test_it_defaults_to_the_whole_current_month(): void
    {
        $data = $this->report([]);

        $this->assertSame('month', $data['gran']);
        $this->assertSame(0, $data['offset']);
        $this->assertSame('2026-06-01', $data['from']);
        // Not clamped to today: a month means the month, so nobody reads "3 of 5 weeks"
        // off a period that has not finished.
        $this->assertSame('2026-06-30', $data['to']);
        $this->assertFalse($data['canNext']);
    }

    public function test_week_granularity_covers_monday_to_friday(): void
    {
        $data = $this->report(['gran' => 'week']);

        $this->assertSame('2026-06-15', $data['from']);
        $this->assertSame('2026-06-19', $data['to']);
    }

    public function test_a_negative_offset_steps_back_a_whole_month(): void
    {
        $data = $this->report(['gran' => 'month', 'offset' => '-1']);

        $this->assertSame('2026-05-01', $data['from']);
        $this->assertSame('2026-05-31', $data['to']);
        $this->assertTrue($data['canNext']);
    }

    public function test_stepping_forward_past_the_current_period_is_refused(): void
    {
        $data = $this->report(['gran' => 'month', 'offset' => '3']);

        $this->assertSame(0, $data['offset']);
        $this->assertSame('2026-06-01', $data['from']);
    }

    public function test_an_old_bookmark_with_only_from_and_to_is_still_that_range(): void
    {
        $data = $this->report(['from' => '2026-05-04', 'to' => '2026-05-08']);

        $this->assertSame('custom', $data['gran']);
        $this->assertSame('2026-05-04', $data['from']);
        $this->assertSame('2026-05-08', $data['to']);
    }

    public function test_department_narrows_the_report_to_that_department(): void
    {
        $ops = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Ops']);
        $build = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Build']);
        $this->staffWithDay('Aiman', '2026-06-15', $ops);
        $this->staffWithDay('Bala', '2026-06-15', $build);

        $data = $this->report(['dept' => 'Ops']);

        $names = array_column($data['lensStaff'], 'name');
        $this->assertSame(['Aiman'], $names);
        $this->assertSame(1.0, $data['reportTotals']['days']);
    }

    public function test_the_name_search_narrows_the_report(): void
    {
        $this->staffWithDay('Aiman', '2026-06-15');
        $this->staffWithDay('Bala', '2026-06-15');

        $data = $this->report(['q' => 'ala']);

        $this->assertSame(['Bala'], array_column($data['lensStaff'], 'name'));
    }

    public function test_a_narrowed_report_does_not_count_filtered_out_people_as_missing(): void
    {
        $this->staffWithDay('Aiman', '2026-06-15');
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Bala',
            'status' => 'active', 'workload' => 'green',
        ]);

        $all = $this->report([]);
        $narrowed = $this->report(['q' => 'Aiman']);

        $this->assertGreaterThan($narrowed['reportTotals']['weeksNotIn'], $all['reportTotals']['weeksNotIn']);
    }

    public function test_the_screen_renders_the_period_bar_and_the_department_picker(): void
    {
        Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Ops']);

        $response = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports');

        $response->assertOk();
        // Two rows: the period acts on click, the pickers wait for Apply. Keeping them
        // apart is the point — one bar made Apply look like it governed the period too.
        $response->assertSee('class="uj-tr-period"', false);
        $response->assertSee('uj-ar-seg', false);
        $response->assertSee('All departments', false);
        $response->assertSee('tr-range-form', false);
        // Every period link stays on the report tab; without it Apply bounced the
        // reader back to the chase tab.
        $response->assertSee('gran=week&amp;offset=0&amp;tab=report', false);
    }
}
