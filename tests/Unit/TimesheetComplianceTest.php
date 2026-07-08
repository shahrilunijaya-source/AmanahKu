<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Tenancy\CurrentTenant;
use App\Timesheet\TimesheetCompliance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit coverage for TimesheetCompliance. The "current" week is Mon 2026-06-22
 * (deadline Fri 2026-06-26 17:00). Time is pinned per test.
 */
class TimesheetComplianceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TimesheetCompliance $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
        $this->svc = app(TimesheetCompliance::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function employee(array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green',
        ], $attrs));
    }

    /** Build a timesheet for $emp with a percentage for each given date. */
    private function sheet(Employee $emp, string $weekStart, array $dayPct): Timesheet
    {
        $cat = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Others'.uniqid(), 'requires_project' => false]);
        $ts = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $emp->id,
            'week_start' => $weekStart, 'status' => 'draft', 'total_hours' => 0,
        ]);
        foreach ($dayPct as $date => $pct) {
            $ts->entries()->create([
                'tenant_id' => $this->tenant->id, 'entry_date' => $date,
                'category_id' => $cat->id, 'percentage' => $pct, 'hours' => 0,
            ]);
        }

        return $ts;
    }

    public function test_week_start_is_the_monday_of_the_reference_day(): void
    {
        $monday = $this->svc->weekStart(Carbon::parse('2026-06-24 09:00')); // Wed
        $this->assertSame('2026-06-22', $monday->toDateString());
    }

    public function test_deadline_is_friday_1700_of_the_week(): void
    {
        $deadline = $this->svc->deadline(Carbon::parse('2026-06-22'));
        $this->assertSame('2026-06-26 17:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    public function test_full_week_all_five_weekdays_at_100_is_complete(): void
    {
        $emp = $this->employee();
        $this->sheet($emp, '2026-06-22', [
            '2026-06-22' => 100, '2026-06-23' => 100, '2026-06-24' => 100,
            '2026-06-25' => 100, '2026-06-26' => 100,
        ]);
        $this->assertTrue($this->svc->isComplete($emp, Carbon::parse('2026-06-22')));
    }

    public function test_a_weekday_below_100_is_incomplete(): void
    {
        $emp = $this->employee();
        $this->sheet($emp, '2026-06-22', [
            '2026-06-22' => 100, '2026-06-23' => 100, '2026-06-24' => 80,
            '2026-06-25' => 100, '2026-06-26' => 100,
        ]);
        $this->assertFalse($this->svc->isComplete($emp, Carbon::parse('2026-06-22')));
    }

    public function test_no_timesheet_row_is_incomplete(): void
    {
        $emp = $this->employee();
        $this->assertFalse($this->svc->isComplete($emp, Carbon::parse('2026-06-22')));
    }

    public function test_weekend_entries_do_not_substitute_for_a_missing_weekday(): void
    {
        // Mon–Thu at 100%, Friday missing, but Saturday filled — still incomplete.
        $emp = $this->employee();
        $this->sheet($emp, '2026-06-22', [
            '2026-06-22' => 100, '2026-06-23' => 100, '2026-06-24' => 100,
            '2026-06-25' => 100, '2026-06-27' => 100,
        ]);
        $this->assertFalse($this->svc->isComplete($emp, Carbon::parse('2026-06-22')));
    }

    public function test_is_late_only_after_the_friday_deadline(): void
    {
        $emp = $this->employee(); // no sheet → incomplete

        Carbon::setTestNow('2026-06-26 16:59:00'); // before deadline
        $this->assertFalse($this->svc->isLate($emp, Carbon::parse('2026-06-22')));

        Carbon::setTestNow('2026-06-26 17:30:00'); // after deadline
        $this->assertTrue($this->svc->isLate($emp, Carbon::parse('2026-06-22')));
    }

    public function test_a_complete_week_is_never_late(): void
    {
        $emp = $this->employee();
        $this->sheet($emp, '2026-06-22', [
            '2026-06-22' => 100, '2026-06-23' => 100, '2026-06-24' => 100,
            '2026-06-25' => 100, '2026-06-26' => 100,
        ]);
        Carbon::setTestNow('2026-06-26 18:00:00');
        $this->assertFalse($this->svc->isLate($emp, Carbon::parse('2026-06-22')));
    }

    public function test_roster_marks_done_pending_late_and_excludes_new_joiners_and_inactive(): void
    {
        Carbon::setTestNow('2026-06-26 17:30:00'); // past deadline → not-done = late

        $done = $this->employee(['name' => 'Aaron']);
        $this->sheet($done, '2026-06-22', [
            '2026-06-22' => 100, '2026-06-23' => 100, '2026-06-24' => 100,
            '2026-06-25' => 100, '2026-06-26' => 100,
        ]);
        $late = $this->employee(['name' => 'Bella']); // no sheet
        $newJoiner = $this->employee(['name' => 'Cara', 'joined_at' => '2026-06-25']); // joined mid-week
        $inactive = $this->employee(['name' => 'Dan', 'status' => 'inactive']);

        $roster = $this->svc->roster($this->tenant, Carbon::parse('2026-06-22'));
        $byName = $roster->keyBy(fn ($r) => $r['employee']->name);

        $this->assertSame('done', $byName['Aaron']['status']);
        $this->assertSame('late', $byName['Bella']['status']);
        $this->assertArrayNotHasKey('Cara', $byName->all());
        $this->assertArrayNotHasKey('Dan', $byName->all());
    }

    public function test_pending_returns_only_not_done_employees(): void
    {
        $done = $this->employee(['name' => 'Aaron']);
        $this->sheet($done, '2026-06-22', [
            '2026-06-22' => 100, '2026-06-23' => 100, '2026-06-24' => 100,
            '2026-06-25' => 100, '2026-06-26' => 100,
        ]);
        $notDone = $this->employee(['name' => 'Bella']);

        $pending = $this->svc->pending($this->tenant, Carbon::parse('2026-06-22'));

        $this->assertCount(1, $pending);
        $this->assertSame('Bella', $pending->first()->name);
    }

    public function test_is_late_is_false_for_an_employee_who_joined_after_the_week_started(): void
    {
        $emp = $this->employee(['joined_at' => '2026-06-25']); // joined Thu, no sheet
        Carbon::setTestNow('2026-06-26 17:30:00'); // past the Friday deadline
        $this->assertFalse($this->svc->isLate($emp, Carbon::parse('2026-06-22')));
    }

    public function test_is_late_is_false_for_an_inactive_employee(): void
    {
        $emp = $this->employee(['status' => 'inactive']);
        Carbon::setTestNow('2026-06-26 17:30:00');
        $this->assertFalse($this->svc->isLate($emp, Carbon::parse('2026-06-22')));
    }
}
