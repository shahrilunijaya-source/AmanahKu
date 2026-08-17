<?php

namespace Tests\Feature;

use App\Http\Controllers\TimesheetController;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Project;
use App\Models\SubPillar;
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

class TimesheetReportLensTest extends TestCase
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

    private function createEmployee(string $name, ?Position $position = null): Employee
    {
        $this->seq++;
        $user = User::create([
            'name' => $name,
            'email' => "user{$this->seq}@example.com",
            'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        return Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'name' => $name,
            'status' => 'active',
            'workload' => 'green',
            'position_id' => $position?->id,
        ]);
    }

    private function createTimesheetWithEntry(
        Employee $employee,
        TimesheetCategory $category,
        ?Project $project = null,
        string $date = '2026-06-15',
        float $percentage = 100,
        float $hours = 8
    ): Timesheet {
        $ts = Timesheet::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'week_start' => '2026-06-15',
            'status' => 'submitted',
            'total_hours' => $hours,
        ]);

        $ts->entries()->create([
            'tenant_id' => $this->tenant->id,
            'entry_date' => $date,
            'category_id' => $category->id,
            'project_id' => $project?->id,
            'percentage' => $percentage,
            'hours' => $hours,
        ]);

        return $ts;
    }

    private function getReportData(): array
    {
        app(CurrentTenant::class)->set($this->tenant);

        $request = Request::create('/app/timesheet-reports', 'GET', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
        ]);
        $request->attributes->set('tenantRole', 'hr');
        $request->attributes->set('tenantScope', 'company');

        return app(TimesheetController::class)->reportData($request, $this->hrEmployee);
    }

    public function test_two_employees_sharing_a_name_stay_two_rows(): void
    {
        $emp1 = $this->createEmployee('Mei Ling', $this->position);
        $emp2 = $this->createEmployee('Mei Ling', $this->position);

        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Development',
            'requires_project' => false,
        ]);

        $this->createTimesheetWithEntry($emp1, $category, null, '2026-06-15', 100, 8);
        $this->createTimesheetWithEntry($emp2, $category, null, '2026-06-16', 50, 4);

        $data = $this->getReportData();

        $lensStaff = $data['lensStaff'];
        $this->assertCount(2, $lensStaff);

        $ids = collect($lensStaff)->pluck('id')->all();
        $this->assertCount(2, array_unique($ids));
        $this->assertContains($emp1->id, $ids);
        $this->assertContains($emp2->id, $ids);

        $days = collect($lensStaff)->pluck('days')->all();
        $this->assertContains(1.0, $days);
        $this->assertContains(0.5, $days);
    }

    public function test_slice_members_are_keyed_by_employee_id(): void
    {
        $emp1 = $this->createEmployee('Alice', $this->position);
        $emp2 = $this->createEmployee('Bob', $this->position);

        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Testing',
            'requires_project' => false,
        ]);

        $this->createTimesheetWithEntry($emp1, $category, null, '2026-06-15', 100, 8);
        $this->createTimesheetWithEntry($emp2, $category, null, '2026-06-16', 100, 8);

        $data = $this->getReportData();

        $lensCategory = $data['lensCategory'];
        $this->assertCount(1, $lensCategory);
        $catRow = $lensCategory[0];

        $members = $catRow['members'];
        $this->assertCount(2, $members);

        $memberIds = collect($members)->pluck('id')->all();
        $this->assertCount(2, array_unique($memberIds));
        $this->assertContains($emp1->id, $memberIds);
        $this->assertContains($emp2->id, $memberIds);

        $totalPct = collect($members)->sum('pct');
        $this->assertTrue(abs($totalPct - 100) <= 1, "Total pct {$totalPct} should sum to 100 within 1 of rounding error.");
    }

    public function test_unbanded_employee_is_uncosted_not_zero(): void
    {
        $unbandedEmp = $this->createEmployee('Charlie', null);

        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Design',
            'requires_project' => false,
        ]);

        $this->createTimesheetWithEntry($unbandedEmp, $category, null, '2026-06-15', 100, 8);

        $data = $this->getReportData();

        $lensStaff = $data['lensStaff'];
        $staffRow = collect($lensStaff)->firstWhere('id', $unbandedEmp->id);

        $this->assertNotNull($staffRow);
        $this->assertFalse($staffRow['costed']);
        $this->assertNull($staffRow['rate']);
        $this->assertSame(0.0, $staffRow['cost']);
        $this->assertSame(1.0, $staffRow['days']);

        $lensCategory = $data['lensCategory'];
        $catRow = collect($lensCategory)->firstWhere('id', $category->id);
        $this->assertNotNull($catRow);
        $this->assertSame(1.0, $catRow['days']);
        $member = collect($catRow['members'])->firstWhere('id', $unbandedEmp->id);
        $this->assertNotNull($member);
        $this->assertSame(1.0, $member['days']);
    }

    public function test_project_rows_exclude_entries_with_no_project(): void
    {
        $emp1 = $this->createEmployee('Diana', $this->position);
        $emp2 = $this->createEmployee('Edward', $this->position);

        $categoryNoProj = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General Admin',
            'requires_project' => false,
        ]);

        $categoryWithProj = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Client Work',
            'requires_project' => true,
        ]);

        $project = Project::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Project Alpha',
        ]);

        $this->createTimesheetWithEntry($emp1, $categoryNoProj, null, '2026-06-15', 100, 8);
        $this->createTimesheetWithEntry($emp2, $categoryWithProj, $project, '2026-06-16', 100, 8);

        $data = $this->getReportData();

        $lensProject = $data['lensProject'];
        $this->assertCount(1, $lensProject);
        $this->assertSame($project->id, $lensProject[0]['id']);

        $memberIds = collect($lensProject[0]['members'])->pluck('id')->all();
        $this->assertContains($emp2->id, $memberIds);
        $this->assertNotContains($emp1->id, $memberIds);
    }

    public function test_a_draft_week_is_missing_not_counted(): void
    {
        $emp = $this->createEmployee('Eve', $this->position);

        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Development',
            'requires_project' => false,
        ]);

        $this->createTimesheetWithEntry($emp, $category, null, '2026-06-15', 100, 8);

        $draftTs = Timesheet::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $emp->id,
            'week_start' => '2026-06-22',
            'status' => 'draft',
            'total_hours' => 8,
        ]);
        $draftTs->entries()->create([
            'tenant_id' => $this->tenant->id,
            'entry_date' => '2026-06-22',
            'category_id' => $category->id,
            'percentage' => 100,
            'hours' => 8,
        ]);

        $data = $this->getReportData();

        $lensStaffRow = collect($data['lensStaff'])->firstWhere('id', $emp->id);
        $this->assertNotNull($lensStaffRow);

        $this->assertSame(1.0, $lensStaffRow['days']);
        $this->assertSame(1, $lensStaffRow['weeksIn']);
        $this->assertContains('Week 26', $lensStaffRow['missingWeeks']);
    }

    public function test_weeks_before_the_join_date_are_not_missing(): void
    {
        $emp = $this->createEmployee('Frank', $this->position);
        $emp->update(['joined_at' => '2026-06-15']);

        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Development',
            'requires_project' => false,
        ]);
        $this->createTimesheetWithEntry($emp, $category, null, '2026-06-15', 100, 8);

        $data = $this->getReportData();

        $lensStaffRow = collect($data['lensStaff'])->firstWhere('id', $emp->id);
        $this->assertNotNull($lensStaffRow);

        $this->assertSame(3, $lensStaffRow['weeksTotal']);
        $this->assertNotContains('Week 23', $lensStaffRow['missingWeeks']);
        $this->assertNotContains('Week 24', $lensStaffRow['missingWeeks']);
    }

    public function test_staff_weeks_carry_the_line_note_and_label(): void
    {
        $emp = $this->createEmployee('Grace', $this->position);

        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Development',
            'requires_project' => true,
        ]);
        $project = Project::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'KPT: RMS',
        ]);
        $subPillar = SubPillar::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Reporting module',
        ]);

        $ts = Timesheet::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $emp->id,
            'week_start' => '2026-06-15',
            'status' => 'submitted',
            'total_hours' => 8,
        ]);

        $ts->entries()->create([
            'tenant_id' => $this->tenant->id,
            'entry_date' => '2026-06-15',
            'category_id' => $category->id,
            'project_id' => $project->id,
            'sub_pillar_id' => $subPillar->id,
            'percentage' => 100,
            'hours' => 8,
            'description' => 'Built the reporting engine',
        ]);

        $data = $this->getReportData();

        $this->assertArrayHasKey('staffWeeks', $data);
        $this->assertArrayHasKey($emp->id, $data['staffWeeks']);

        $empWeeks = $data['staffWeeks'][$emp->id];
        $this->assertCount(1, $empWeeks);

        $week = $empWeeks[0];
        $this->assertSame('Week 25', $week['label']);
        $this->assertSame('15 – 19 Jun', $week['dates']);
        $this->assertSame(1.0, $week['days']);
        $this->assertCount(1, $week['lines']);

        $line = $week['lines'][0];
        $this->assertSame('Development · KPT: RMS · Reporting module', $line['label']);
        $this->assertSame('Built the reporting engine', $line['note']);
        $this->assertSame(1.0, $line['days']);
    }

    public function test_weeks_not_in_counts_an_employee_with_no_entries(): void
    {
        $noEntryEmp = $this->createEmployee('Hannah', $this->position);

        $empWithEntry = $this->createEmployee('Ian', $this->position);
        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Support',
            'requires_project' => false,
        ]);
        $this->createTimesheetWithEntry($empWithEntry, $category, null, '2026-06-15', 100, 8);

        $data = $this->getReportData();

        $totals = $data['reportTotals'];
        $this->assertArrayHasKey('weeksTotal', $totals);
        $this->assertArrayHasKey('weeksNotIn', $totals);

        $this->assertSame(5, $totals['weeksTotal']);
        $this->assertSame(14, $totals['weeksNotIn']);

        // weeksNotIn spans every visible employee, not only the ones with a lensStaff row.
        // Two here have no row at all — the HR viewer created in setUp(), and Hannah — and
        // each owes every week of the period. Derive that second term instead of hardcoding
        // it, or the assertion passes for the wrong reason when attribution shifts.
        $rowMissing = collect($data['lensStaff'])->sum(fn ($r) => count($r['missingWeeks']));
        $rowlessMissing = 2 * $totals['weeksTotal'];
        $this->assertSame($totals['weeksNotIn'], $rowMissing + $rowlessMissing);
    }

    public function test_week_dates_span_two_months_when_the_week_does(): void
    {
        $emp = $this->createEmployee('Jack', $this->position);
        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ops',
            'requires_project' => false,
        ]);

        $this->createTimesheetWithEntry($emp, $category, null, '2026-06-29', 100, 8);

        app(CurrentTenant::class)->set($this->tenant);
        $request = Request::create('/app/timesheet-reports', 'GET', [
            'from' => '2026-06-29',
            'to' => '2026-07-05',
        ]);
        $request->attributes->set('tenantRole', 'hr');
        $request->attributes->set('tenantScope', 'company');

        $data = app(TimesheetController::class)->reportData($request, $this->hrEmployee);

        $empWeeks = $data['staffWeeks'][$emp->id];
        $this->assertCount(1, $empWeeks);
        $this->assertSame('29 Jun – 3 Jul', $empWeeks[0]['dates']);
    }
}
