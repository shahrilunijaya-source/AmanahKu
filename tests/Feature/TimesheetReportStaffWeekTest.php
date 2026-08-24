<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The read-only staff week viewer behind "Open week" on the timesheet report's
 * chase tab. It used to be a link to /app/timesheets, which showed the *reader's*
 * own week with an edit form on it.
 */
class TimesheetReportStaffWeekTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hrUser;

    private Employee $hr;

    private Employee $staff;

    private TimesheetCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-19 12:00:00');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hrUser = User::create(['name' => 'HR', 'email' => 'hr@example.com', 'password' => Hash::make('password')]);
        $this->hrUser->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->hr = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->hrUser->id,
            'name' => 'HR', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->staff = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Aiman', 'status' => 'active', 'workload' => 'green',
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

    private function actingAsHr(): self
    {
        $this->actingAs($this->hrUser)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function draftWithOneLine(string $weekStart, string $day): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->staff->id,
            'week_start' => $weekStart, 'status' => 'draft', 'total_hours' => 4,
        ]);
        $sheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => $day,
            'category_id' => $this->category->id, 'percentage' => 50, 'hours' => 4,
            'description' => 'Half a day on Ops',
        ]);
    }

    public function test_it_shows_an_unsubmitted_draft_for_the_person_asked_for(): void
    {
        $this->draftWithOneLine('2026-06-15', '2026-06-16');

        $response = $this->actingAsHr()->get("/app/timesheet-reports/person/{$this->staff->id}");

        $response->assertOk();
        $response->assertSee('Aiman');
        $response->assertSee('Half a day on Ops', false);
        $weeks = $response->viewData('weeks');
        $this->assertSame('2026-06-15', end($weeks)['weekStart']);
        $this->assertSame('draft', end($weeks)['status']);
    }

    public function test_entries_are_not_links_into_anybody_elses_record_tab(): void
    {
        $this->draftWithOneLine('2026-06-15', '2026-06-16');

        $response = $this->actingAsHr()->get("/app/timesheet-reports/person/{$this->staff->id}");

        // baseUrl null = reviewEntryUrl() returns null for every line, so no <a href>
        // can be built into the reader's own capture screen.
        $response->assertSee('baseUrl: null', false);
    }

    public function test_the_week_asked_for_is_always_there_even_with_no_sheet_at_all(): void
    {
        // Only an old week has anything in it — the chase list is about this week.
        $this->draftWithOneLine('2026-06-01', '2026-06-02');

        $response = $this->actingAsHr()->get("/app/timesheet-reports/person/{$this->staff->id}");

        $weeks = $response->viewData('weeks');
        $current = end($weeks);
        $this->assertSame('2026-06-15', $current['weekStart']);
        $this->assertNull($current['status']);
        $this->assertSame([], $current['lines']);
    }

    public function test_the_week_query_picks_which_week_it_opens_on(): void
    {
        $this->draftWithOneLine('2026-06-08', '2026-06-09');
        $this->draftWithOneLine('2026-06-15', '2026-06-16');

        $response = $this->actingAsHr()->get("/app/timesheet-reports/person/{$this->staff->id}?week=2026-06-08");

        $weeks = $response->viewData('weeks');
        $this->assertCount(1, $weeks);
        $this->assertSame('2026-06-08', $weeks[0]['weekStart']);
    }

    public function test_a_plain_employee_cannot_read_a_colleagues_sheet(): void
    {
        $this->draftWithOneLine('2026-06-15', '2026-06-16');

        $nosyUser = User::create(['name' => 'Nosy', 'email' => 'nosy@example.com', 'password' => Hash::make('password')]);
        $nosyUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $nosyUser->id,
            'name' => 'Nosy', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($nosyUser)->withSession(['current_tenant' => $this->tenant->id])
            ->get("/app/timesheet-reports/person/{$this->staff->id}")
            ->assertForbidden();
    }

    public function test_another_tenants_employee_is_refused(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $outsider = Employee::create([
            'tenant_id' => $other->id, 'name' => 'Outsider', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAsHr()
            ->get("/app/timesheet-reports/person/{$outsider->id}")
            ->assertForbidden();
    }
}
