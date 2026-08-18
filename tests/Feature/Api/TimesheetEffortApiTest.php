<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\Position;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GET /api/v1/timesheet-effort — the per-project, per-position weekly effort Track
 * pulls to replace its hand-typed staff dedication rows.
 *
 * The arithmetic under test: AmanahKu stores a percentage of a day per entry, and
 * Track needs person-days. One employee-day at 100% is 1.0 person-day.
 */
class TimesheetEffortApiTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = '2026-08-03'; // a Monday

    private Tenant $tenant;

    private User $hr;

    private User $staff;

    private Position $senior;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        app(CurrentTenant::class)->set($this->tenant);

        $this->hr = User::create(['name' => 'HR Ann', 'email' => 'hr@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->staff = User::create(['name' => 'Staff Sam', 'email' => 'staff@example.com', 'password' => Hash::make('password')]);
        $this->staff->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->senior = Position::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Senior Engineer', 'status' => 'active', 'max_salary' => 7000,
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'code' => 'KPT', 'name' => 'KPT: RMS', 'is_active' => true,
        ]);

        app(CurrentTenant::class)->set(null);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    public function test_it_sums_percentages_into_person_days_for_one_position(): void
    {
        // Ali full-time all week, Siti at 40% all week. 5.0 + 2.0 = 7.0 person-days.
        $this->submitWeek($this->employee('Ali', $this->senior), [100, 100, 100, 100, 100]);
        $this->submitWeek($this->employee('Siti', $this->senior), [40, 40, 40, 40, 40]);

        $row = $this->effortRow();

        $this->assertSame($this->senior->id, $row['position_id']);
        $this->assertSame('Senior Engineer', $row['position_title']);
        $this->assertSame(2, $row['headcount']);
        $this->assertSame(5, $row['days_present']);
        // Cast: JSON has one number type, so 7.0 arrives as 7.
        $this->assertSame(7.0, (float) $row['person_days']);
        // 7.0 / (2 heads x 5 days) = 70%.
        $this->assertSame(70.0, (float) $row['alloc_pct']);
    }

    public function test_a_draft_week_contributes_nothing(): void
    {
        $this->submitWeek($this->employee('Ali', $this->senior), [100, 100, 100, 100, 100], 'draft');

        $response = $this->getJson('/api/v1/timesheet-effort?week_start='.self::WEEK, $this->bearer($this->hr));

        $response->assertOk();
        $this->assertSame([], $response->json('data.projects'));
    }

    public function test_an_approved_week_counts_the_same_as_a_submitted_one(): void
    {
        // Nothing in the app sets 'approved' today, but TimesheetSeeder does and
        // WeekReconciler treats an approved week as a decided figure that must not be
        // mutated — strictly more final than submitted. Dropping it would silently
        // withhold real effort from Track's ledger.
        $this->submitWeek($this->employee('Ali', $this->senior), [100, 100], 'approved');

        $row = $this->effortRow();

        $this->assertSame(2.0, (float) $row['person_days']);
    }

    public function test_a_rejected_week_is_excluded(): void
    {
        $this->submitWeek($this->employee('Ali', $this->senior), [100, 100], 'rejected');

        $response = $this->getJson('/api/v1/timesheet-effort?week_start='.self::WEEK, $this->bearer($this->hr));

        $this->assertSame([], $response->json('data.projects'));
    }

    public function test_entries_with_no_project_are_excluded(): void
    {
        $employee = $this->employee('Ali', $this->senior);
        // Monday on the project, Tuesday on a non-project category (admin, leave, holiday).
        $this->submitWeek($employee, [100]);
        app(CurrentTenant::class)->set($this->tenant);
        TimesheetEntry::create([
            'tenant_id' => $this->tenant->id,
            'timesheet_id' => Timesheet::forWeek(self::WEEK)->where('employee_id', $employee->id)->sole()->id,
            'entry_date' => '2026-08-04', 'project_id' => null, 'percentage' => 100, 'source' => 'leave',
        ]);
        app(CurrentTenant::class)->set(null);

        $response = $this->getJson('/api/v1/timesheet-effort?week_start='.self::WEEK, $this->bearer($this->hr));
        $projects = collect($response->json('data.projects'));

        // The project-less entry must not surface at all, not even as a phantom group.
        $this->assertSame([$this->project->id], $projects->pluck('project_id')->all());
        $row = $projects->first()['positions'][0];
        $this->assertSame(1.0, (float) $row['person_days']);
        $this->assertSame(1, $row['days_present']);
    }

    public function test_an_employee_with_no_position_band_lands_in_its_own_bucket(): void
    {
        $this->submitWeek($this->employee('Ali', $this->senior), [100]);
        $this->submitWeek($this->employee('Unbanded Umi', null), [100]);

        $response = $this->getJson('/api/v1/timesheet-effort?week_start='.self::WEEK, $this->bearer($this->hr));
        $positions = collect($response->json('data.projects'))->firstWhere('project_id', $this->project->id)['positions'];

        $unbanded = collect($positions)->firstWhere('position_id', null);
        $this->assertNotNull($unbanded, 'The unbanded employee should still be reported.');
        $this->assertNull($unbanded['position_title']);
        $this->assertSame(1.0, (float) $unbanded['person_days']);
    }

    public function test_a_part_day_keeps_its_fraction(): void
    {
        // Half a Monday plus a quarter of a Tuesday is 0.75 person-days. Rounding this
        // to a whole day would inflate the ledger by a third.
        $this->submitWeek($this->employee('Ali', $this->senior), [50, 25]);

        $row = $this->effortRow();

        $this->assertSame(0.75, (float) $row['person_days']);
        $this->assertSame(2, $row['days_present']);
        $this->assertSame(37.5, (float) $row['alloc_pct']);
    }

    public function test_person_days_round_to_two_decimals(): void
    {
        // Three days at 33.33% is 0.9999, which must report as 1.0 rather than 0.9999.
        $this->submitWeek($this->employee('Ali', $this->senior), [33.33, 33.33, 33.33]);

        $row = $this->effortRow();

        $this->assertSame(1.0, (float) $row['person_days']);
        $this->assertSame(33.33, (float) $row['alloc_pct']);
    }

    public function test_it_rejects_a_plain_employee_token(): void
    {
        $this->getJson('/api/v1/timesheet-effort?week_start='.self::WEEK, $this->bearer($this->staff))
            ->assertForbidden();
    }

    public function test_it_requires_a_week_start(): void
    {
        $this->getJson('/api/v1/timesheet-effort', $this->bearer($this->hr))
            ->assertStatus(422);
    }

    public function test_it_is_tenant_isolated(): void
    {
        $other = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BE']);
        app(CurrentTenant::class)->set($other);
        $otherProject = Project::create(['tenant_id' => $other->id, 'code' => 'B1', 'name' => 'Beta Project', 'is_active' => true]);
        $otherEmployee = Employee::create(['tenant_id' => $other->id, 'name' => 'Beta Bob', 'status' => 'active', 'workload' => 'green']);
        $otherSheet = Timesheet::create([
            'tenant_id' => $other->id, 'employee_id' => $otherEmployee->id,
            'week_start' => self::WEEK, 'status' => 'submitted',
        ]);
        TimesheetEntry::create([
            'tenant_id' => $other->id, 'timesheet_id' => $otherSheet->id,
            'entry_date' => self::WEEK, 'project_id' => $otherProject->id, 'percentage' => 100,
        ]);
        app(CurrentTenant::class)->set(null);

        $this->submitWeek($this->employee('Ali', $this->senior), [100]);

        $response = $this->getJson('/api/v1/timesheet-effort?week_start='.self::WEEK, $this->bearer($this->hr));

        $projectIds = collect($response->json('data.projects'))->pluck('project_id');
        $this->assertTrue($projectIds->contains($this->project->id));
        $this->assertFalse($projectIds->contains($otherProject->id));
    }

    // --- helpers -----------------------------------------------------------

    /** Mint a tenant-bound token and return the Bearer auth header array. */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->mintApiToken($this->tenant, 'test')->plainTextToken];
    }

    private function employee(string $name, ?Position $position = null): Employee
    {
        app(CurrentTenant::class)->set($this->tenant);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'active', 'workload' => 'green',
            'position_id' => $position?->id,
        ]);
        app(CurrentTenant::class)->set(null);

        return $employee;
    }

    /**
     * Submit a week for one employee: one entry per weekday at the given percentage,
     * all booked to the shared project. A zero percentage writes no entry.
     *
     * @param  array<int, int|float>  $percentages  Monday first.
     */
    private function submitWeek(Employee $employee, array $percentages, string $status = 'submitted'): Timesheet
    {
        app(CurrentTenant::class)->set($this->tenant);

        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'week_start' => self::WEEK, 'status' => $status,
        ]);

        foreach ($percentages as $offset => $percentage) {
            if ((float) $percentage === 0.0) {
                continue;
            }
            TimesheetEntry::create([
                'tenant_id' => $this->tenant->id, 'timesheet_id' => $timesheet->id,
                'entry_date' => date('Y-m-d', strtotime(self::WEEK.' +'.$offset.' day')),
                'project_id' => $this->project->id, 'percentage' => $percentage,
            ]);
        }

        app(CurrentTenant::class)->set(null);

        return $timesheet;
    }

    /** The single position row for the shared project in the week under test. */
    private function effortRow(?User $as = null): array
    {
        $response = $this->getJson('/api/v1/timesheet-effort?week_start='.self::WEEK, $this->bearer($as ?? $this->hr));
        $response->assertOk()->assertJsonPath('error', null);

        $project = collect($response->json('data.projects'))->firstWhere('project_id', $this->project->id);
        $this->assertNotNull($project, 'No effort returned for the project under test.');

        return $project['positions'][0];
    }
}
