<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TimesheetReportScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hrUser;

    private Employee $hrEmployee;

    private Position $position;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-19 12:00:00');

        $this->tenant = Tenant::create([
            'slug' => 'unijaya',
            'name' => 'Unijaya',
            'initials' => 'UJ',
        ]);

        $this->position = Position::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Software Engineer',
            'max_salary' => 10000,
        ]);

        $this->hrUser = User::create([
            'name' => 'HR Admin',
            'email' => 'hr@example.com',
            'password' => Hash::make('password'),
        ]);
        $this->hrUser->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->hrEmployee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->hrUser->id,
            'name' => 'HR Admin',
            'status' => 'active',
            'workload' => 'green',
            'position_id' => $this->position->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createEmployee(string $name, ?Position $position = null, string $role = 'employee'): array
    {
        $this->seq++;
        $user = User::create([
            'name' => $name,
            'email' => "user{$this->seq}@example.com",
            'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        $emp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'name' => $name,
            'status' => 'active',
            'workload' => 'green',
            'position_id' => $position?->id,
        ]);

        return [$user, $emp];
    }

    private function createTimesheetWithEntry(
        Employee $employee,
        TimesheetCategory $category,
        string $date = '2026-06-15',
        float $percentage = 100,
        string $status = 'submitted'
    ): Timesheet {
        $weekStart = Carbon::parse($date)->startOfWeek()->toDateString();
        $ts = Timesheet::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'week_start' => $weekStart,
            'status' => $status,
            'total_hours' => 8,
        ]);

        $ts->entries()->create([
            'tenant_id' => $this->tenant->id,
            'entry_date' => $date,
            'category_id' => $category->id,
            'percentage' => $percentage,
            'hours' => 8,
        ]);

        return $ts;
    }

    public function test_hr_sees_the_three_lens_controls(): void
    {
        $response = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports?from=2026-06-01&to=2026-06-30');

        $response->assertOk()
            ->assertSee('By category')
            ->assertSee('By project')
            ->assertSee('By person');
    }

    public function test_the_person_days_figure_renders(): void
    {
        $cat = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Dev', 'requires_project' => false]);
        [,$emp] = $this->createEmployee('Alice', $this->position);
        $this->createTimesheetWithEntry($emp, $cat, '2026-06-15', 100);

        $response = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports?from=2026-06-01&to=2026-06-30');

        $response->assertOk()
            ->assertSee('person-days recorded');
    }

    public function test_an_unbanded_employee_reads_uncosted_not_zero_ringgit(): void
    {
        $cat = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Dev', 'requires_project' => false]);
        [,$unbandedEmp] = $this->createEmployee('Unbanded Bob', null);
        $this->createTimesheetWithEntry($unbandedEmp, $cat, '2026-06-15', 100);

        $response = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports?from=2026-06-01&to=2026-06-30');

        $response->assertOk()
            ->assertSee('uncosted')
            ->assertDontSee('RM 0.00');
    }

    public function test_the_weeks_not_in_chip_appears_only_when_a_week_is_missing(): void
    {
        $cat = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Dev', 'requires_project' => false]);
        [,$emp] = $this->createEmployee('Charlie', $this->position);

        // Submit all 5 weeks for HR employee
        foreach (['2026-06-01', '2026-06-08', '2026-06-15', '2026-06-22', '2026-06-29'] as $d) {
            $this->createTimesheetWithEntry($this->hrEmployee, $cat, $d, 100, 'submitted');
        }

        // Submit weeks June 1, 8, 15, 29 for Charlie, leave June 22 as draft
        $this->createTimesheetWithEntry($emp, $cat, '2026-06-01', 100, 'submitted');
        $this->createTimesheetWithEntry($emp, $cat, '2026-06-08', 100, 'submitted');
        $this->createTimesheetWithEntry($emp, $cat, '2026-06-15', 100, 'submitted');
        $draftTs = $this->createTimesheetWithEntry($emp, $cat, '2026-06-22', 100, 'draft');
        $this->createTimesheetWithEntry($emp, $cat, '2026-06-29', 100, 'submitted');

        $res1 = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports?from=2026-06-01&to=2026-06-30');

        $res1->assertOk()
            ->assertSee('WEEKS NOT IN');

        // Submit the missing draft week
        $draftTs->update(['status' => 'submitted']);

        $res2 = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports?from=2026-06-01&to=2026-06-30');

        $res2->assertOk()
            ->assertDontSee('WEEKS NOT IN');
    }

    public function test_the_empty_state_renders_for_a_filter_with_no_matches(): void
    {
        $cat = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'EmptyCat', 'requires_project' => false]);

        $response = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports?from=2026-06-01&to=2026-06-30&category='.$cat->id);

        $response->assertOk()
            ->assertSee('No submitted time matches this filter');
    }

    public function test_a_manager_and_a_plain_employee_are_both_forbidden(): void
    {
        [$mgrUser] = $this->createEmployee('Manager Mary', $this->position, 'manager');
        [$empUser] = $this->createEmployee('Employee Ed', $this->position, 'employee');

        $this->actingAs($mgrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports')
            ->assertForbidden();

        $this->actingAs($empUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports')
            ->assertForbidden();
    }

    public function test_the_project_lens_explains_itself_when_no_time_is_project_linked(): void
    {
        $cat = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'General Admin', 'requires_project' => false]);
        [,$emp] = $this->createEmployee('Alice', $this->position);
        $this->createTimesheetWithEntry($emp, $cat, '2026-06-15', 100);

        $response = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports?from=2026-06-01&to=2026-06-30');

        $response->assertOk()
            ->assertSee('No project-linked time in this period.');
    }

    public function test_the_empty_state_names_the_active_filter(): void
    {
        $cat = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'EmptyCat', 'requires_project' => false]);

        $response = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports?from=2026-06-01&to=2026-06-30&category='.$cat->id);

        $response->assertOk()
            ->assertSee('No submitted time matches this filter')
            ->assertSee('EmptyCat');
    }

    public function test_the_rail_payload_carries_a_stored_note(): void
    {
        $cat = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Dev', 'requires_project' => false]);
        [,$emp] = $this->createEmployee('Alice', $this->position);
        $ts = $this->createTimesheetWithEntry($emp, $cat, '2026-06-15', 100);
        $ts->entries()->first()->update(['description' => 'Detailed work description note']);

        $response = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports?from=2026-06-01&to=2026-06-30');

        $response->assertOk()
            ->assertSee('Detailed work description note', false);
    }

    public function test_the_rail_payload_carries_the_week_label_and_line_label(): void
    {
        $cat = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Dev', 'requires_project' => false]);
        [,$emp] = $this->createEmployee('Alice', $this->position);
        $this->createTimesheetWithEntry($emp, $cat, '2026-06-15', 100);

        $response = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports?from=2026-06-01&to=2026-06-30');

        $response->assertOk()
            ->assertSee('Week 25', false)
            ->assertSee('Dev', false);
    }

    public function test_a_person_with_a_draft_week_carries_it_in_missing_weeks(): void
    {
        // The "is not here: no sheet was ever submitted." sentence is built client-side
        // (formatMissingWeeks() in resources/js/timesheet-report.js, covered by
        // resources/js/timesheet-report.test.js) from this missingWeeks array — this
        // test is the server side of that split: the week itself must reach the payload.
        $cat = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Dev', 'requires_project' => false]);
        [,$emp] = $this->createEmployee('Charlie', $this->position);

        $this->createTimesheetWithEntry($emp, $cat, '2026-06-15', 100, 'submitted');
        $this->createTimesheetWithEntry($emp, $cat, '2026-06-22', 100, 'draft');

        $response = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports?from=2026-06-01&to=2026-06-30');

        $response->assertOk()
            ->assertSee('missingWeeks', false)
            ->assertSee('Week 26', false);
    }

    public function test_the_rail_renders_a_panel_element(): void
    {
        $cat = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Dev', 'requires_project' => false]);
        [,$emp] = $this->createEmployee('Alice', $this->position);
        $this->createTimesheetWithEntry($emp, $cat, '2026-06-15', 100);

        $response = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports?from=2026-06-01&to=2026-06-30');

        $response->assertOk()
            ->assertSee('uj-tr-panel')
            ->assertSee('uj-tr-lens');
    }
}
