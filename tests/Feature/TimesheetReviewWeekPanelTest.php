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

    public function test_screendata_my_weeks_includes_every_week_including_an_empty_draft(): void
    {
        $submitted = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-08', 'status' => 'submitted', 'total_hours' => 8,
        ]);
        $submitted->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-08',
            'category_id' => $this->category->id, 'percentage' => 100, 'hours' => 8,
        ]);
        // Current week's draft exists but has no entries yet.
        Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 0,
        ]);

        $response = $this->actingInTenant()->get('/app/timesheets');

        $response->assertOk();
        $weeks = collect($response->viewData('myWeeks'))->keyBy('weekStart');
        $this->assertCount(2, $weeks);
        $this->assertSame('submitted', $weeks['2026-06-08']['status']);
        $this->assertSame(1.0, $weeks['2026-06-08']['days']);
        $this->assertSame('draft', $weeks['2026-06-15']['status']);
        $this->assertSame([], $weeks['2026-06-15']['lines']);
    }

    public function test_existing_grid_rows_carry_the_entry_id(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 8,
        ]);
        $entry = $sheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->category->id, 'percentage' => 100, 'hours' => 8,
        ]);

        $response = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $grid = $response->viewData('existingGrid');
        $this->assertSame($entry->id, $grid['2026-06-15'][0]['id']);
    }

    public function test_the_review_tab_shows_the_weeks_own_total_but_never_a_salary_figure(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 8,
        ]);
        $sheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->category->id, 'percentage' => 100, 'hours' => 8,
        ]);

        $response = $this->actingInTenant()->get('/app/timesheets?tab=review');

        // "Did this week add up" is the question the tab exists to answer; the day
        // totals below only answered it one day at a time.
        $response->assertSee("md(wk.days) + ' md'", false);

        // Cost is a management figure (TimesheetController::canSeeCost). The partial is
        // shared with the all-staff report, so the personal payload is checked here
        // rather than trusting the markup to keep leaving RM out.
        $weeks = $response->viewData('myWeeks');
        $this->assertSame(1.0, $weeks[0]['days']);
        $this->assertSame(0.0, $weeks[0]['cost']);
    }

    public function test_entry_lines_carry_their_categorys_colour(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 8,
        ]);
        $maintenance = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Maintenance', 'requires_project' => true,
        ]);
        $sheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15',
            'category_id' => $maintenance->id, 'percentage' => 100, 'hours' => 8,
        ]);

        $response = $this->actingInTenant()->get('/app/timesheets?tab=review');

        // Same colour the capture picker's dot and the Projects register pill use, so a
        // category reads the same wherever it appears (TimesheetCategory::colour()).
        $line = $response->viewData('myWeeks')[0]['lines'][0];
        $this->assertSame('Maintenance', $line['category']);
        $this->assertSame('var(--amber-ink)', $line['categoryColour']);
    }

    public function test_review_tab_renders_week_nav_and_entry_link(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 8,
        ]);
        $sheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->category->id, 'percentage' => 100, 'hours' => 8,
        ]);

        $response = $this->actingInTenant()->get('/app/timesheets?tab=review');

        $response->assertOk();
        // The component is wired with the controller's myWeeks data. @js() hex-escapes
        // quotes into JSON.parse('...'), so this checks for the unescaped key/value text
        // rather than matching the exact quoting, which is Js::from()'s concern, not this
        // view's.
        $response->assertSee('timesheetReview(', false);
        $response->assertSee('weekStart', false);
        $response->assertSee('2026-06-15', false);
    }

    public function test_review_tab_has_no_cost_gate(): void
    {
        // A plain employee (not a money role) still gets myWeeks — no canSeeCost check
        // wraps the Review tab any more.
        Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 0,
        ]);

        $response = $this->actingInTenant()->get('/app/timesheets?tab=review');

        $response->assertOk();
        $response->assertSee('timesheetReview(', false);
    }
}
