<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Attendance\ClockService;
use App\Attendance\ScheduleResolver;
use App\Attendance\SiteSpec;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ClockService business rules — the #1 daily-driver flow. Geofence in/out,
 * justification enforcement, punctuality flags, home capture, and the
 * double-tap noops. The resolver is stubbed so each test crafts its site.
 */
class ClockServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Clocker', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    /** ClockService whose resolver always returns the given site. */
    private function service(SiteSpec $site): ClockService
    {
        $resolver = new class($site) extends ScheduleResolver
        {
            public function __construct(private SiteSpec $site) {}

            public function resolve(Employee $employee, CarbonInterface $date): SiteSpec
            {
                return $this->site;
            }
        };

        return new ClockService($resolver);
    }

    /** An office site geofenced at (3.10, 101.60) r=200m, hours 09:00–18:00, min 8h. */
    private function office(): SiteSpec
    {
        return new SiteSpec('office', 'HQ', 3.10, 101.60, 200, '09:00', '18:00', 8.0);
    }

    /** A security-guard-style overnight site: same geofence, shift 22:00–06:00 next day. */
    private function overnightSite(): SiteSpec
    {
        return new SiteSpec('office', 'HQ Night Shift', 3.10, 101.60, 200, '22:00', '06:00', 7.0);
    }

    public function test_in_radius_on_time_clock_in_creates_an_on_time_record(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($this->office())->clockIn($this->employee, 3.1001, 101.6001, null, null, $now);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertNotNull($record);
        $this->assertSame('on_time', $record->status);
        $this->assertTrue($record->in_radius);
        $this->assertSame([], $record->flags ?? []);
    }

    public function test_out_of_radius_clock_in_requires_justification(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');

        // ~11km away from the office pin.
        $res = $this->service($this->office())->clockIn($this->employee, 3.20, 101.60, null, null, $now);

        $this->assertSame('needs_justification', $res['status']);
        $this->assertNull($this->employee->attendanceRecords()->onDate($now)->first());
    }

    public function test_out_of_radius_clock_in_requires_a_selfie_even_with_a_justification(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($this->office())->clockIn($this->employee, 3.20, 101.60, 'Client meeting first', null, $now);

        $this->assertSame('needs_photo', $res['status']);
        $this->assertNull($this->employee->attendanceRecords()->onDate($now)->first());
    }

    public function test_in_radius_clock_in_needs_no_selfie(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($this->office())->clockIn($this->employee, 3.1001, 101.6001, null, null, $now);

        $this->assertSame('ok', $res['status']);
    }

    public function test_out_of_radius_clock_in_with_justification_and_selfie_is_flagged_not_blocked(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($this->office())->clockIn($this->employee, 3.20, 101.60, 'Client meeting first', 'attendance-photos/a.jpg', $now);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertFalse($record->in_radius);
        $this->assertContains('out_of_radius_in', $record->flags);
        $this->assertSame('Client meeting first', $record->clock_in_justification);
        $this->assertSame('attendance-photos/a.jpg', $record->photo_path);
    }

    public function test_off_site_clock_out_requires_a_selfie_but_an_early_in_radius_one_does_not(): void
    {
        $svc = $this->service($this->office());
        $svc->clockIn($this->employee, 3.10, 101.60, null, null, Carbon::parse('2026-07-02 09:00:00'));
        $early = Carbon::parse('2026-07-02 15:00:00');

        $offSite = $svc->clockOut($this->employee, 3.20, 101.60, 'Left from the client site', null, $early);
        $this->assertSame('needs_photo', $offSite['status']);

        // Same early exit from inside the fence needs the reason only.
        $inFence = $svc->clockOut($this->employee, 3.10, 101.60, 'Site visit ended early', null, $early);
        $this->assertSame('ok', $inFence['status']);
    }

    public function test_clock_in_after_work_start_is_marked_late_and_needs_a_reason(): void
    {
        $now = Carbon::parse('2026-07-02 09:20:00');
        $svc = $this->service($this->office());

        // Lateness used to be recorded in silence. It now costs a reason, like every other
        // irregular punch.
        $this->assertSame('needs_justification', $svc->clockIn($this->employee, 3.10, 101.60, null, null, $now)['status']);
        $this->assertNull($this->employee->attendanceRecords()->onDate($now)->first());

        $res = $svc->clockIn($this->employee, 3.10, 101.60, 'Traffic jam on the LDP', null, $now);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertSame('late', $record->status);
        $this->assertContains('late', $record->flags);
        $this->assertSame('Traffic jam on the LDP', $record->clock_in_justification);
    }

    public function test_clock_in_within_tenant_grace_period_is_on_time(): void
    {
        $this->tenant->update(['late_grace_minutes' => 5]);
        $now = Carbon::parse('2026-07-02 09:04:00');

        $res = $this->service($this->office())->clockIn($this->employee, 3.10, 101.60, null, null, $now);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertSame('on_time', $record->status);
        $this->assertNotContains('late', $record->flags ?? []);
    }

    public function test_clock_in_past_tenant_grace_period_is_still_late(): void
    {
        $this->tenant->update(['late_grace_minutes' => 5]);
        $now = Carbon::parse('2026-07-02 09:06:00');

        $res = $this->service($this->office())->clockIn($this->employee, 3.10, 101.60, 'Overslept', null, $now);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertSame('late', $record->status);
        $this->assertContains('late', $record->flags);
    }

    /**
     * A punch inside the grace window is not late, so it must not be interrogated. This is
     * the test that stops the feature from asking the whole company for a reason every day.
     */
    public function test_clock_in_within_the_grace_window_is_never_asked_for_a_reason(): void
    {
        $this->tenant->update(['late_grace_minutes' => 15]);
        $now = Carbon::parse('2026-07-02 09:14:00');

        $res = $this->service($this->office())->clockIn($this->employee, 3.10, 101.60, null, null, $now);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('on_time', $this->employee->attendanceRecords()->onDate($now)->first()->status);
    }

    /**
     * A site with no configured start time has no bar to be late against, so the gate must
     * stay silent rather than demand a reason nobody can give.
     */
    public function test_a_site_with_no_work_start_never_demands_a_late_reason(): void
    {
        $noHours = new SiteSpec('office', 'HQ', 3.10, 101.60, 200, null, null, null);
        $now = Carbon::parse('2026-07-02 23:59:00');

        $res = $this->service($noHours)->clockIn($this->employee, 3.10, 101.60, null, null, $now);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('on_time', $this->employee->attendanceRecords()->onDate($now)->first()->status);
    }

    /**
     * Late and off-site at once. The fence check runs first and returns before the late
     * gate is reached, so the employee is told about their location. This pins that
     * ordering: it fails if the late gate is ever moved above the fence check.
     */
    public function test_a_late_off_site_punch_reports_the_fence_not_the_lateness(): void
    {
        $now = Carbon::parse('2026-07-02 09:20:00');

        // ~11km away from the office pin, and 20 minutes past the 09:00 start.
        $res = $this->service($this->office())->clockIn($this->employee, 3.20, 101.60, null, null, $now);

        $this->assertSame('needs_justification', $res['status']);
        $this->assertStringContainsString('outside', $res['message']);
    }

    /**
     * A tenant nobody has configured must still get a usable grace. Without a column
     * default, every new workspace starts at zero and its staff are late at 09:00:15 —
     * which, with the late-remark gate, means a typed reason every single morning.
     */
    public function test_a_new_tenant_gets_the_default_grace_period(): void
    {
        $fresh = Tenant::create(['slug' => 'brandnew', 'name' => 'Brand New', 'initials' => 'BN']);

        $this->assertSame(15, $fresh->fresh()->late_grace_minutes);
    }

    /**
     * No coordinates at all (a desk machine that cannot locate itself). Never a lockout, but
     * never free either: the punch costs a reason, a selfie and a permanent flag.
     */
    public function test_a_punch_with_no_coordinates_costs_a_reason_a_selfie_and_a_flag(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');
        $svc = $this->service($this->office());

        $this->assertSame('needs_justification', $svc->clockIn($this->employee, null, null, null, null, $now)['status']);
        $this->assertSame('needs_photo', $svc->clockIn($this->employee, null, null, 'Office PC cannot find GPS', null, $now)['status']);
        $this->assertNull($this->employee->attendanceRecords()->onDate($now)->first());

        $res = $svc->clockIn($this->employee, null, null, 'Office PC cannot find GPS', 'attendance-photos/a.jpg', $now);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertNull($record->in_radius);
        $this->assertContains('no_location', $record->flags);
        $this->assertSame('Office PC cannot find GPS', $record->clock_in_justification);
    }

    public function test_clock_out_with_no_coordinates_is_flagged_too(): void
    {
        $svc = $this->service($this->office());
        $svc->clockIn($this->employee, 3.10, 101.60, null, null, Carbon::parse('2026-07-02 09:00:00'));
        $end = Carbon::parse('2026-07-02 18:00:00');

        $this->assertSame('needs_justification', $svc->clockOut($this->employee, null, null, null, null, $end)['status']);

        $res = $svc->clockOut($this->employee, null, null, 'GPS died on the way out', 'attendance-photos/b.jpg', $end);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($end)->first();
        $this->assertContains('no_location', $record->flags);
        $this->assertNotContains('out_of_radius_out', $record->flags);
    }

    public function test_second_clock_in_same_day_is_a_noop(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');
        $svc = $this->service($this->office());

        $svc->clockIn($this->employee, 3.10, 101.60, null, null, $now);
        $res = $svc->clockIn($this->employee, 3.10, 101.60, null, null, $now->copy()->addMinutes(5));

        $this->assertSame('noop', $res['status']);
        $this->assertSame(1, $this->employee->attendanceRecords()->count());
    }

    public function test_clock_out_without_clock_in_is_a_noop(): void
    {
        $res = $this->service($this->office())->clockOut($this->employee, 3.10, 101.60, null, null, Carbon::parse('2026-07-02 18:00:00'));

        $this->assertSame('noop', $res['status']);
    }

    /**
     * A shift crossing midnight (in 23:00, out 01:30 next day) used to look up the clock-out
     * record by "today", find nothing under yesterday's date, and tell the employee they had
     * never clocked in. Worked minutes must also span the date boundary correctly, not clamp
     * to 0 from anchoring the clock-in time onto the wrong day.
     *
     * A justification is supplied because isEarly()/isLate() have their own separate overnight
     * wraparound bug (an 18:00 expected end still reads as "not yet reached" at 01:30 the next
     * day) — not what this test is isolating. The clock-in also needs one: 23:00 is well past
     * this employee's 09:00 office start, so it is genuinely late and the new late gate demands
     * a reason before it — again, not what this test is isolating.
     */
    public function test_overnight_clock_out_finds_the_open_punch_and_computes_real_hours(): void
    {
        $svc = $this->service($this->office());
        $svc->clockIn($this->employee, 3.10, 101.60, 'Overnight on-call shift', null, Carbon::parse('2026-07-02 23:00:00'));

        $res = $svc->clockOut($this->employee, 3.10, 101.60, 'Overnight on-call shift', null, Carbon::parse('2026-07-03 01:30:00'));

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate(Carbon::parse('2026-07-02'))->first();
        $this->assertNotNull($record);
        $this->assertSame(150, $record->worked_minutes);
    }

    /**
     * A shift assigned 22:00-06:00 (next day). Clocking in right after midnight — well past
     * the 22:00 start — used to compare against a start still hours in the future on the
     * clock-in's own date and read as on-time. It must anchor the start onto the previous
     * calendar day instead, so a genuinely late arrival is still flagged late.
     *
     * A justification is supplied because this arrival is genuinely late and the late gate
     * now demands a reason before it — not what this test is isolating.
     */
    public function test_overnight_shift_clock_in_after_midnight_is_still_marked_late(): void
    {
        $now = Carbon::parse('2026-07-03 00:30:00'); // 2.5h after the 22:00 start, previous evening

        $res = $this->service($this->overnightSite())->clockIn($this->employee, 3.10, 101.60, 'Delayed relief handover', null, $now);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertSame('late', $record->status);
        $this->assertContains('late', $record->flags);
    }

    /** Same shift, clocking in right on the 22:00 start (same calendar day) is on time. */
    public function test_overnight_shift_clock_in_at_start_time_is_on_time(): void
    {
        $now = Carbon::parse('2026-07-02 22:00:00');

        $res = $this->service($this->overnightSite())->clockIn($this->employee, 3.10, 101.60, null, null, $now);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertSame('on_time', $record->status);
    }

    /**
     * Same overnight shift, clocking out at 05:30 the next morning (before the 06:00 end) is
     * genuinely early and must be flagged. This case already worked before the fix — $now was
     * already on the correct calendar day for the 06:00 boundary — kept as a regression guard.
     */
    public function test_overnight_shift_clock_out_before_end_next_morning_is_early(): void
    {
        $svc = $this->service($this->overnightSite());
        $svc->clockIn($this->employee, 3.10, 101.60, null, null, Carbon::parse('2026-07-02 22:00:00'));

        $blocked = $svc->clockOut($this->employee, 3.10, 101.60, null, null, Carbon::parse('2026-07-03 05:30:00'));
        $this->assertSame('needs_justification', $blocked['status']);

        $ok = $svc->clockOut($this->employee, 3.10, 101.60, 'Relieved early by the next shift', null, Carbon::parse('2026-07-03 05:30:00'));
        $this->assertSame('ok', $ok['status']);
        $record = $this->employee->attendanceRecords()->onDate(Carbon::parse('2026-07-02'))->first();
        $this->assertContains('early_out', $record->flags);
    }

    /**
     * Same overnight shift, clocking out at 23:50 — before midnight, hours before the 06:00
     * end the next day — used to compare against a 06:00 already hours in the past on that
     * same calendar day and read as "not early" (technically past it). Must anchor the end
     * onto the next calendar day and flag it early.
     */
    public function test_overnight_shift_clock_out_before_midnight_is_flagged_early(): void
    {
        $svc = $this->service($this->overnightSite());
        $svc->clockIn($this->employee, 3.10, 101.60, null, null, Carbon::parse('2026-07-02 22:00:00'));

        $blocked = $svc->clockOut($this->employee, 3.10, 101.60, null, null, Carbon::parse('2026-07-02 23:50:00'));
        $this->assertSame('needs_justification', $blocked['status']);

        $ok = $svc->clockOut($this->employee, 3.10, 101.60, 'Called off early', null, Carbon::parse('2026-07-02 23:50:00'));
        $this->assertSame('ok', $ok['status']);
        $record = $this->employee->attendanceRecords()->onDate(Carbon::parse('2026-07-02'))->first();
        $this->assertContains('early_out', $record->flags);
    }

    /** Same overnight shift, clocking out right at 06:00 the next morning is on time, not early. */
    public function test_overnight_shift_clock_out_at_end_time_is_not_early(): void
    {
        $svc = $this->service($this->overnightSite());
        $svc->clockIn($this->employee, 3.10, 101.60, null, null, Carbon::parse('2026-07-02 22:00:00'));

        $res = $svc->clockOut($this->employee, 3.10, 101.60, null, null, Carbon::parse('2026-07-03 06:00:00'));

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate(Carbon::parse('2026-07-02'))->first();
        $this->assertNotContains('early_out', $record->flags);
    }

    /** A genuinely forgotten clock-out from days ago is not silently attributed to today. */
    public function test_clock_out_does_not_reach_back_past_yesterdays_open_punch(): void
    {
        $svc = $this->service($this->office());
        $svc->clockIn($this->employee, 3.10, 101.60, null, null, Carbon::parse('2026-06-28 09:00:00'));

        $res = $svc->clockOut($this->employee, 3.10, 101.60, null, null, Carbon::parse('2026-07-02 18:00:00'));

        $this->assertSame('noop', $res['status']);
    }

    public function test_early_clock_out_requires_justification_then_flags_it(): void
    {
        $svc = $this->service($this->office());
        $svc->clockIn($this->employee, 3.10, 101.60, null, null, Carbon::parse('2026-07-02 09:00:00'));

        $early = Carbon::parse('2026-07-02 15:00:00'); // before 18:00 AND under 8h
        $blocked = $svc->clockOut($this->employee, 3.10, 101.60, null, null, $early);
        $this->assertSame('needs_justification', $blocked['status']);

        $ok = $svc->clockOut($this->employee, 3.10, 101.60, 'Site visit ended early', null, $early);
        $this->assertSame('ok', $ok['status']);

        $record = $this->employee->attendanceRecords()->onDate($early)->first();
        $this->assertContains('early_out', $record->flags);
        $this->assertContains('short_hours', $record->flags);
        $this->assertSame(360, $record->worked_minutes);
    }

    public function test_first_home_clock_in_registers_and_locks_the_home_location(): void
    {
        $home = new SiteSpec('home', 'Home', null, null, 200, '09:00', '18:00', 8.0, needsHomeCapture: true);
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($home)->clockIn($this->employee, 3.15, 101.70, null, null, $now);

        $this->assertSame('ok', $res['status']);
        $fresh = $this->employee->fresh();
        $this->assertSame(3.15, (float) $fresh->home_latitude);
        $this->assertSame(101.70, (float) $fresh->home_longitude);
        $this->assertNotNull($fresh->home_locked_at);
        $this->assertSame('wfh', $this->employee->attendanceRecords()->onDate($now)->first()->type);
    }

    /**
     * Staff do not pick their location. Standing inside a different configured branch is
     * matched automatically, so a Klang visit is not an off-site punch against PJ HQ.
     */
    public function test_clock_in_matches_another_configured_branch_the_staff_is_standing_in(): void
    {
        Branch::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Klang',
            'latitude' => 3.0449, 'longitude' => 101.4451, 'radius_m' => 200,
            'work_start' => '08:00', 'work_end' => '17:00', 'min_hours' => 6.0,
        ]);
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($this->office())->clockIn($this->employee, 3.0450, 101.4452, null, null, $now);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertSame('Klang', $record->location);
        $this->assertTrue((bool) $record->in_radius);
        $this->assertSame([], $record->flags ?? []);
        // Hours stay the employee's own (09:00 start), not Klang's 08:00 — so 08:55 is early,
        // not late, even though the branch they walked into opens earlier. Compared through
        // Carbon because a TIME column reads back as 'H:i:s' on MySQL but echoes the written
        // 'H:i' on sqlite, and the schedule is what this asserts, not the driver's formatting.
        $this->assertSame('09:00', Carbon::parse($record->expected_start)->format('H:i'));
        $this->assertSame('on_time', $record->status);
    }

    /** A fix inside no configured site still falls back to the assigned one. */
    public function test_clock_in_far_from_every_configured_site_stays_off_site(): void
    {
        Branch::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Klang',
            'latitude' => 3.0449, 'longitude' => 101.4451, 'radius_m' => 200,
        ]);
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($this->office())->clockIn($this->employee, 4.50, 100.30, null, null, $now);

        $this->assertSame('needs_justification', $res['status']);
        $this->assertStringContainsString('HQ', $res['message']);
    }

    /** Another tenant's branch is never a match, even at the exact same coordinates. */
    public function test_a_branch_in_another_tenant_is_not_matched(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        Branch::create([
            'tenant_id' => $other->id, 'name' => 'Their Office',
            'latitude' => 3.0449, 'longitude' => 101.4451, 'radius_m' => 200,
        ]);
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($this->office())->clockIn($this->employee, 3.0450, 101.4452, null, null, $now);

        $this->assertSame('needs_justification', $res['status']);
    }

    /**
     * A home day spent at the office must clock against the office. If the match ran after
     * home capture instead of before it, the office would be locked in as the staff's home.
     */
    public function test_a_home_day_at_the_office_does_not_register_the_office_as_home(): void
    {
        Branch::create([
            'tenant_id' => $this->tenant->id, 'name' => 'PJ HQ',
            'latitude' => 3.1073, 'longitude' => 101.6067, 'radius_m' => 200,
        ]);
        $home = new SiteSpec('home', 'Work from home', null, null, 200, '09:00', '18:00', 8.0, needsHomeCapture: true);
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($home)->clockIn($this->employee, 3.1074, 101.6068, null, null, $now);

        $this->assertSame('ok', $res['status']);
        $this->assertNull($this->employee->fresh()->home_latitude);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertSame('PJ HQ', $record->location);
        $this->assertSame('standard', $record->type);
    }
}
