<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetDay;
use App\Timesheet\DayRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Acceptance\AlwaysChecks;
use Tests\TestCase;

/**
 * Focused coverage for the CR-03 building blocks not already exercised end to end by
 * tests/Acceptance/CR03Test.php: DayRules' own working-day arithmetic, an unlocked
 * day becoming editable again, recall's submitted-vs-approved split, and approve-week
 * touching only submitted days. Reuses tests/Acceptance/AlwaysChecks' fixture helpers
 * (person/tenant/actingInTenantAs) without modifying that file.
 */
class TimesheetDayTest extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private Employee $staff;

    private Employee $manager;

    private TimesheetCategory $others;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->person('Kussairi', 'manager');
        $this->staff = $this->person('Shazwan', 'employee', ['reports_to_id' => $this->manager->id]);
        $this->others = TimesheetCategory::create(['tenant_id' => $this->tenant()->id, 'name' => 'Others', 'requires_project' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- DayRules::deadlineFor ---------------------------------------------

    public function test_deadline_for_friday_skips_the_weekend_to_monday(): void
    {
        $deadline = (new DayRules)->deadlineFor(Carbon::parse('2026-06-19')); // Friday

        $this->assertSame('2026-06-22 10:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    public function test_deadline_for_a_day_before_a_public_holiday_skips_the_holiday(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant()->id, 'name' => 'Awal Muharram', 'date' => '2026-06-16']);

        // Monday 15th's deadline would normally be Tuesday 16th — a public holiday —
        // so it rolls to Wednesday 17th instead.
        $deadline = (new DayRules)->deadlineFor(Carbon::parse('2026-06-15'));

        $this->assertSame('2026-06-17 10:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    // ---- DayRules::earliestEditable ----------------------------------------

    public function test_earliest_editable_steps_back_three_working_days(): void
    {
        // Friday 19th: 3 working days back is Thu 18, Wed 17, Tue 16.
        $earliest = (new DayRules)->earliestEditable(Carbon::parse('2026-06-19'));

        $this->assertSame('2026-06-16', $earliest->toDateString());
    }

    public function test_earliest_editable_steps_over_a_public_holiday(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant()->id, 'name' => 'Awal Muharram', 'date' => '2026-06-18']);

        // Friday 19th, stepping back 3 working days: Thu 18 is a holiday and does not
        // count, so the three steps land on Wed 17, Tue 16, Mon 15.
        $earliest = (new DayRules)->earliestEditable(Carbon::parse('2026-06-19'));

        $this->assertSame('2026-06-15', $earliest->toDateString());
    }

    // ---- Manager unlock lets a frozen day be edited again ------------------

    public function test_unlocking_an_old_day_lets_the_staffer_edit_it_again(): void
    {
        Carbon::setTestNow('2026-06-19 12:00:00'); // Friday

        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $this->staff->id,
            'week_start' => '2026-06-15', 'status' => 'draft',
        ]);
        $sheet->entries()->create([
            'tenant_id' => $this->tenant()->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->others->id, 'percentage' => 100, 'hours' => 8,
        ]);

        // Monday 15th is more than 3 working days back from Friday 19th: refused.
        $this->actingInTenantAs($this->staff)->postJson('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->others->id, 'percentage' => 60],
            ],
        ])->assertStatus(422);

        // The manager unlocks it.
        $this->actingInTenantAs($this->manager)
            ->postJson("/app/timesheets/{$this->staff->id}/days/2026-06-15/unlock", ['reason' => 'Late correction agreed'])
            ->assertOk();

        $this->assertDatabaseHas('timesheet_days', [
            'timesheet_id' => $sheet->id, 'entry_date' => '2026-06-15',
        ]);
        $day = TimesheetDay::where('timesheet_id', $sheet->id)->where('entry_date', '2026-06-15')->first();
        $this->assertNotNull($day->unlocked_at);

        // The same edit now goes through.
        $this->actingInTenantAs($this->staff)->postJson('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->others->id, 'percentage' => 60],
            ],
        ])->assertOk();

        $this->assertSame(60.0, (float) $sheet->fresh()->entries()->whereDate('entry_date', '2026-06-15')->first()->percentage);
    }

    // ---- recall: submitted days go back to draft, approved days stay -------

    public function test_recall_reopens_submitted_days_but_leaves_approved_ones_locked(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $this->staff->id,
            'week_start' => '2026-06-15', 'status' => 'submitted',
        ]);
        $submittedDay = TimesheetDay::create([
            'tenant_id' => $this->tenant()->id, 'timesheet_id' => $sheet->id,
            'entry_date' => '2026-06-15', 'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $approvedDay = TimesheetDay::create([
            'tenant_id' => $this->tenant()->id, 'timesheet_id' => $sheet->id,
            'entry_date' => '2026-06-16', 'status' => 'approved', 'submitted_at' => now(),
        ]);

        $this->actingInTenantAs($this->staff)
            ->post("/app/timesheets/{$sheet->id}/recall")
            ->assertRedirect();

        $this->assertSame('draft', $submittedDay->fresh()->status);
        $this->assertNull($submittedDay->fresh()->submitted_at);
        $this->assertSame('approved', $approvedDay->fresh()->status);
    }

    // ---- approve-week only touches submitted days ---------------------------

    public function test_approve_week_approves_only_submitted_days(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $this->staff->id,
            'week_start' => '2026-06-15', 'status' => 'submitted',
        ]);
        $submittedDay = TimesheetDay::create([
            'tenant_id' => $this->tenant()->id, 'timesheet_id' => $sheet->id,
            'entry_date' => '2026-06-15', 'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $draftDay = TimesheetDay::create([
            'tenant_id' => $this->tenant()->id, 'timesheet_id' => $sheet->id,
            'entry_date' => '2026-06-16', 'status' => 'draft',
        ]);

        $this->actingInTenantAs($this->manager)
            ->postJson("/app/timesheets/{$this->staff->id}/approve-week", ['week_start' => '2026-06-15'])
            ->assertOk();

        $this->assertSame('approved', $submittedDay->fresh()->status);
        $this->assertSame('draft', $draftDay->fresh()->status, 'a day that was never submitted must not be approved');
    }

    public function test_approve_week_refuses_when_nothing_is_submitted(): void
    {
        Timesheet::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $this->staff->id,
            'week_start' => '2026-06-15', 'status' => 'draft',
        ]);

        $this->actingInTenantAs($this->manager)
            ->postJson("/app/timesheets/{$this->staff->id}/approve-week", ['week_start' => '2026-06-15'])
            ->assertStatus(422);
    }

    // ---- week status derivation ---------------------------------------------

    public function test_week_status_is_submitted_only_once_every_candidate_day_is(): void
    {
        Carbon::setTestNow('2026-06-19 12:00:00');

        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $this->staff->id,
            'week_start' => '2026-06-15', 'status' => 'draft',
        ]);
        foreach (['2026-06-15', '2026-06-16', '2026-06-17', '2026-06-18'] as $iso) {
            TimesheetDay::create([
                'tenant_id' => $this->tenant()->id, 'timesheet_id' => $sheet->id,
                'entry_date' => $iso, 'status' => 'submitted', 'submitted_at' => now(),
            ]);
        }

        $sheet->refreshStatusFromDays();
        $this->assertSame('draft', $sheet->fresh()->status, 'Friday is still missing');

        TimesheetDay::create([
            'tenant_id' => $this->tenant()->id, 'timesheet_id' => $sheet->id,
            'entry_date' => '2026-06-19', 'status' => 'submitted', 'submitted_at' => now(),
        ]);

        $sheet->refreshStatusFromDays();
        $this->assertSame('submitted', $sheet->fresh()->status);
    }

    public function test_week_status_is_approved_only_once_every_candidate_day_is(): void
    {
        Carbon::setTestNow('2026-06-19 12:00:00');

        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $this->staff->id,
            'week_start' => '2026-06-15', 'status' => 'submitted',
        ]);
        foreach (['2026-06-15', '2026-06-16', '2026-06-17', '2026-06-18'] as $iso) {
            TimesheetDay::create([
                'tenant_id' => $this->tenant()->id, 'timesheet_id' => $sheet->id,
                'entry_date' => $iso, 'status' => 'approved', 'submitted_at' => now(),
            ]);
        }
        $lastDay = TimesheetDay::create([
            'tenant_id' => $this->tenant()->id, 'timesheet_id' => $sheet->id,
            'entry_date' => '2026-06-19', 'status' => 'submitted', 'submitted_at' => now(),
        ]);

        $sheet->refreshStatusFromDays();
        $this->assertSame('submitted', $sheet->fresh()->status, 'one day is still only submitted, not approved');

        $lastDay->update(['status' => 'approved']);
        $sheet->refreshStatusFromDays();
        $this->assertSame('approved', $sheet->fresh()->status);
    }
}
