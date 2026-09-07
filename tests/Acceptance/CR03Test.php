<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-03.md (session S08, daily timesheet submission).
 *
 * Shapes the spec leaves open are fixed here and logged in OPEN "QA / CR-03":
 * per-day state lives in `timesheet_days` (timesheet_id, entry_date, status
 * draft | submitted | approved | returned, submitted_at, late, resubmitted, zero_reason,
 * return_reason, unlocked_at, unlocked_by_id). Staff submit through the existing
 * `POST /app/timesheets` grid with `submit_day: <date>` (and `day_reason` for a day with
 * no lines) or `submit_now: 1` for the rest of the week. Manager actions are
 * `POST /app/timesheets/{employee}/days/{date}/return | approve | unlock` with a
 * `reason` where the spec asks for one. Fixture week: Mon 15 Jun 2026 (its Saturday is
 * not the TOT half day), staff reports to a manager, 'Others' is a standalone category.
 */
class CR03Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private Employee $staff;

    private Employee $manager;

    private Employee $hr;

    private TimesheetCategory $others;

    private TimesheetCategory $support;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->person('Kussairi', 'manager');
        $this->hr = $this->person('Hidayah', 'hr');
        $this->staff = $this->person('Shazwan', 'employee', ['reports_to_id' => $this->manager->id]);
        $this->others = TimesheetCategory::create(['tenant_id' => $this->tenant()->id, 'name' => 'Others', 'requires_project' => false]);
        $this->support = TimesheetCategory::create(['tenant_id' => $this->tenant()->id, 'name' => 'Support', 'requires_project' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_acceptance_1_on_friday_submit_friday_only_succeeds_while_monday_to_thursday_are_incomplete(): void
    {
        Carbon::setTestNow('2026-06-19 12:00:00');

        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid([
                '2026-06-15' => [[$this->others, 40]],
                '2026-06-19' => [[$this->others, 100]],
            ], ['submit_day' => '2026-06-19']))
            ->assertOk();

        $sheet = Timesheet::forWeek('2026-06-15')->where('employee_id', $this->staff->id)->first();
        $this->assertNotNull($sheet, 'no timesheet row for the week');
        $this->assertDatabaseHas('timesheet_days', ['timesheet_id' => $sheet->id, 'entry_date' => '2026-06-19', 'status' => 'submitted', 'late' => 0]);
        $this->assertSame(0, DB::table('timesheet_days')->where('timesheet_id', $sheet->id)->where('entry_date', '!=', '2026-06-19')->where('status', 'submitted')->count(), 'another day was submitted');
        $this->assertSame(1, $sheet->entries()->whereDate('entry_date', '2026-06-15')->count(), 'the incomplete Monday draft line was lost');
        $this->assertNotNull(AuditLog::query()->where('subject_type', $sheet->getMorphClass())->where('subject_id', $sheet->id)->where('field', 'day.2026-06-19.status')->where('new_value', json_encode('submitted'))->first(), 'no audit entry for the day submission');
    }

    #[Test]
    public function test_acceptance_2_submitted_day_is_locked_manager_returns_it_with_reason_staff_resubmits_and_the_log_shows_old_to_new(): void
    {
        Carbon::setTestNow('2026-06-19 12:00:00');
        $this->submitFriday();
        $sheet = $this->sheet();

        // locked for the staff
        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid(['2026-06-19' => [[$this->others, 60], [$this->support, 40]]]))
            ->assertStatus(422);
        $this->assertSame(1, $sheet->entries()->whereDate('entry_date', '2026-06-19')->count(), 'a locked day was rewritten');

        // return needs a reason and a manager
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/timesheets/{$this->staff->id}/days/2026-06-19/return", ['reason' => 'Split the line'])
            ->assertStatus(403);
        $this->actingInTenantAs($this->manager)
            ->postJson("/app/timesheets/{$this->staff->id}/days/2026-06-19/return", [])
            ->assertStatus(422);
        $this->actingInTenantAs($this->manager)
            ->postJson("/app/timesheets/{$this->staff->id}/days/2026-06-19/return", ['reason' => 'Split the line between support and others'])
            ->assertOk();
        $this->assertDatabaseHas('timesheet_days', ['timesheet_id' => $sheet->id, 'entry_date' => '2026-06-19', 'status' => 'returned', 'return_reason' => 'Split the line between support and others']);
        $returned = AuditLog::query()->where('subject_type', $sheet->getMorphClass())->where('subject_id', $sheet->id)
            ->where('field', 'day.2026-06-19.status')->where('new_value', json_encode('returned'))->first();
        $this->assertNotNull($returned, 'no audit entry for the return');
        $this->assertSame('Split the line between support and others', $returned->reason);
        $this->assertSame($this->manager->user_id, $returned->user_id);

        // staff edits and resubmits
        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid(['2026-06-19' => [[$this->others, 60], [$this->support, 40]]], ['submit_day' => '2026-06-19']))
            ->assertOk();
        $this->assertDatabaseHas('timesheet_days', ['timesheet_id' => $sheet->id, 'entry_date' => '2026-06-19', 'status' => 'submitted', 'resubmitted' => 1]);
        $this->assertEqualsWithDelta(60.0, (float) $sheet->entries()->whereDate('entry_date', '2026-06-19')->where('category_id', $this->others->id)->value('percentage'), 0.01);

        $lines = AuditLog::query()->where('subject_type', $sheet->getMorphClass())->where('subject_id', $sheet->id)
            ->where('field', 'day.2026-06-19.entries')->latest('id')->first();
        $this->assertNotNull($lines, 'no audit entry for the changed lines');
        $this->assertStringContainsString('100', (string) $lines->old_value);
        $this->assertStringContainsString('60', (string) $lines->new_value);
        $this->assertStringContainsString('40', (string) $lines->new_value);
        $this->assertSame($this->staff->user_id, $lines->user_id);
    }

    #[Test]
    public function test_acceptance_3_submit_week_submits_every_unsubmitted_day_with_per_day_validation_and_skips_leave_and_holidays(): void
    {
        Carbon::setTestNow('2026-06-19 12:00:00');
        PublicHoliday::create(['tenant_id' => $this->tenant()->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);
        $annual = LeaveType::create(['tenant_id' => $this->tenant()->id, 'name' => 'Annual', 'entitlement' => 16]);
        LeaveRequest::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $this->staff->id, 'leave_type_id' => $annual->id,
            'date_from' => '2026-06-16', 'date_to' => '2026-06-16', 'days' => 1, 'status' => 'approved',
        ]);
        $this->submitFriday();
        $sheet = $this->sheet();

        // Thursday totals 60: the week is refused, names Thursday, and nothing new is submitted
        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid([
                '2026-06-15' => [[$this->others, 100]],
                '2026-06-18' => [[$this->others, 60]],
                '2026-06-19' => [[$this->others, 100]],
            ], ['submit_now' => 1]))
            ->assertStatus(422)
            ->assertSee('18 Jun', false);
        $this->assertSame(1, DB::table('timesheet_days')->where('timesheet_id', $sheet->id)->where('status', 'submitted')->count(), 'a day was submitted from a refused week');

        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid([
                '2026-06-15' => [[$this->others, 100]],
                '2026-06-18' => [[$this->others, 100]],
                '2026-06-19' => [[$this->others, 100]],
            ], ['submit_now' => 1]))
            ->assertOk();

        foreach (['2026-06-15', '2026-06-18', '2026-06-19'] as $date) {
            $this->assertDatabaseHas('timesheet_days', ['timesheet_id' => $sheet->id, 'entry_date' => $date, 'status' => 'submitted']);
        }
        foreach (['2026-06-16', '2026-06-17'] as $date) {
            $this->assertSame(0, DB::table('timesheet_days')->where('timesheet_id', $sheet->id)->where('entry_date', $date)->where('status', 'submitted')->count(), "{$date} is leave or a holiday and must be skipped");
        }
        $this->assertSame('submitted', $sheet->fresh()->status, 'every working day is in, the week reads submitted');
    }

    #[Test]
    public function test_acceptance_4_monday_submitted_after_tuesday_ten_am_is_marked_late_submission(): void
    {
        Carbon::setTestNow('2026-06-16 09:59:00');
        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid(['2026-06-15' => [[$this->others, 100]]], ['submit_day' => '2026-06-15']))
            ->assertOk();
        $sheet = $this->sheet();
        $this->assertDatabaseHas('timesheet_days', ['timesheet_id' => $sheet->id, 'entry_date' => '2026-06-15', 'status' => 'submitted', 'late' => 0]);

        // a second person, same Monday, one minute past the deadline
        $late = $this->person('Adri', 'employee', ['reports_to_id' => $this->manager->id]);
        Carbon::setTestNow('2026-06-16 10:01:00');
        $this->actingInTenantAs($late)
            ->postJson('/app/timesheets', $this->grid(['2026-06-15' => [[$this->others, 100]]], ['submit_day' => '2026-06-15']))
            ->assertOk();
        $lateSheet = Timesheet::forWeek('2026-06-15')->where('employee_id', $late->id)->first();
        $this->assertDatabaseHas('timesheet_days', ['timesheet_id' => $lateSheet->id, 'entry_date' => '2026-06-15', 'status' => 'submitted', 'late' => 1]);

        // the manager sees it
        $this->actingInTenantAs($this->manager)
            ->get("/app/timesheet-reports/person/{$late->id}?week=2026-06-15")
            ->assertOk()
            ->assertSee('Late submission');

        // Friday's deadline is the next working day, Monday 10:00
        Carbon::setTestNow('2026-06-22 09:59:00');
        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid(['2026-06-15' => [[$this->others, 100]], '2026-06-19' => [[$this->others, 100]]], ['submit_day' => '2026-06-19']))
            ->assertOk();
        $this->assertDatabaseHas('timesheet_days', ['timesheet_id' => $sheet->id, 'entry_date' => '2026-06-19', 'status' => 'submitted', 'late' => 0]);
    }

    #[Test]
    public function test_acceptance_5_a_working_day_with_no_lines_cannot_be_submitted_without_a_reason(): void
    {
        Carbon::setTestNow('2026-06-19 12:00:00');

        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid(['2026-06-19' => [[$this->others, 100]]], ['submit_day' => '2026-06-18']))
            ->assertStatus(422);
        $this->assertSame(0, DB::table('timesheet_days')->where('entry_date', '2026-06-18')->where('status', 'submitted')->count());

        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid(['2026-06-19' => [[$this->others, 100]]], ['submit_day' => '2026-06-18', 'day_reason' => 'Training offsite, no allocation']))
            ->assertOk();
        $sheet = $this->sheet();
        $this->assertDatabaseHas('timesheet_days', ['timesheet_id' => $sheet->id, 'entry_date' => '2026-06-18', 'status' => 'submitted', 'zero_reason' => 'Training offsite, no allocation']);

        // submit week refuses a blank working day the same way
        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid(['2026-06-18' => [], '2026-06-19' => [[$this->others, 100]]], ['submit_now' => 1]))
            ->assertStatus(422)
            ->assertSee('15 Jun', false);
    }

    #[Test]
    public function test_acceptance_6_staff_cannot_edit_a_day_older_than_three_working_days_without_manager_unlock(): void
    {
        Carbon::setTestNow('2026-06-19 12:00:00');

        // Tuesday is three working days back: editable. Monday is four: closed.
        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid(['2026-06-16' => [[$this->others, 100]]]))
            ->assertOk();
        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid(['2026-06-15' => [[$this->others, 100]], '2026-06-16' => [[$this->others, 100]]]))
            ->assertStatus(422)
            ->assertSee('15 Jun', false);
        $this->assertSame(0, TimesheetEntry::whereDate('entry_date', '2026-06-15')->count());

        // only a manager unlocks, with a reason
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/timesheets/{$this->staff->id}/days/2026-06-15/unlock", ['reason' => 'Forgot'])
            ->assertStatus(403);
        $this->actingInTenantAs($this->manager)
            ->postJson("/app/timesheets/{$this->staff->id}/days/2026-06-15/unlock", [])
            ->assertStatus(422);
        $this->actingInTenantAs($this->manager)
            ->postJson("/app/timesheets/{$this->staff->id}/days/2026-06-15/unlock", ['reason' => 'Was on site Monday, no laptop'])
            ->assertOk();

        $sheet = $this->sheet();
        $this->assertDatabaseHas('timesheet_days', ['timesheet_id' => $sheet->id, 'entry_date' => '2026-06-15', 'unlocked_by_id' => $this->manager->id]);
        $unlock = AuditLog::query()->where('subject_type', $sheet->getMorphClass())->where('subject_id', $sheet->id)->where('field', 'day.2026-06-15.unlocked')->first();
        $this->assertNotNull($unlock, 'no audit entry for the unlock');
        $this->assertSame('Was on site Monday, no laptop', $unlock->reason);

        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid(['2026-06-15' => [[$this->others, 100]], '2026-06-16' => [[$this->others, 100]]], ['submit_day' => '2026-06-15']))
            ->assertOk();
        $this->assertDatabaseHas('timesheet_days', ['timesheet_id' => $sheet->id, 'entry_date' => '2026-06-15', 'status' => 'submitted']);
    }

    #[Test]
    public function test_acceptance_7_dashboard_timesheet_percent_counts_approved_days_only(): void
    {
        Carbon::setTestNow('2026-06-17 12:00:00');
        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid([
                '2026-06-15' => [[$this->others, 100]],
                '2026-06-16' => [[$this->others, 100]],
                '2026-06-17' => [[$this->others, 100]],
            ], ['submit_now' => 1]))
            ->assertOk();

        $this->assertDockPercent('0');

        foreach (['2026-06-15', '2026-06-16'] as $date) {
            $this->actingInTenantAs($this->manager)
                ->postJson("/app/timesheets/{$this->staff->id}/days/{$date}/approve")
                ->assertOk();
        }
        $sheet = $this->sheet();
        $this->assertDatabaseHas('timesheet_days', ['timesheet_id' => $sheet->id, 'entry_date' => '2026-06-15', 'status' => 'approved']);
        $this->assertNotNull(AuditLog::query()->where('subject_type', $sheet->getMorphClass())->where('subject_id', $sheet->id)->where('field', 'day.2026-06-15.status')->where('new_value', json_encode('approved'))->first(), 'no audit entry for the approval');

        // two approved of three working days to date
        $this->assertDockPercent('66.7');

        $this->actingInTenantAs($this->manager)
            ->postJson("/app/timesheets/{$this->staff->id}/days/2026-06-17/approve")
            ->assertOk();
        $this->assertDockPercent('100');

        // staff cannot approve their own day
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/timesheets/{$this->staff->id}/days/2026-06-16/approve")
            ->assertStatus(403);
    }

    #[Test]
    public function test_always_the_four_cross_cutting_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * @param  array<string, list<array{0: TimesheetCategory, 1: int}>>  $days
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function grid(array $days, array $extra = []): array
    {
        $entries = [];
        foreach ($days as $date => $lines) {
            foreach ($lines as [$category, $pct]) {
                $entries[] = ['entry_date' => $date, 'category_id' => $category->id, 'percentage' => $pct];
            }
        }

        return array_merge(['week_start' => '2026-06-15', 'entries' => $entries], $extra);
    }

    private function submitFriday(): void
    {
        $this->actingInTenantAs($this->staff)
            ->postJson('/app/timesheets', $this->grid(['2026-06-19' => [[$this->others, 100]]], ['submit_day' => '2026-06-19']))
            ->assertOk();
    }

    private function sheet(): Timesheet
    {
        $sheet = Timesheet::forWeek('2026-06-15')->where('employee_id', $this->staff->id)->first();
        $this->assertNotNull($sheet, 'no timesheet row for the week');

        return $sheet;
    }

    private function assertDockPercent(string $expected): void
    {
        $html = $this->actingInTenantAs($this->staff)->get('/app/dash')->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/Timesheet<\/span>\s*<span[^>]*>'.preg_quote($expected, '/').'%<\/span>/',
            $html,
            "the sidebar Timesheet figure is not {$expected}%",
        );
    }
}
