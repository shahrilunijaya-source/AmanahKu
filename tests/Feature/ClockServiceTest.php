<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Attendance\ClockService;
use App\Attendance\ScheduleResolver;
use App\Attendance\SiteSpec;
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

    public function test_out_of_radius_clock_in_with_justification_is_flagged_not_blocked(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($this->office())->clockIn($this->employee, 3.20, 101.60, 'Client meeting first', null, $now);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertFalse($record->in_radius);
        $this->assertContains('out_of_radius_in', $record->flags);
        $this->assertSame('Client meeting first', $record->clock_in_justification);
    }

    public function test_clock_in_after_work_start_is_marked_late(): void
    {
        $now = Carbon::parse('2026-07-02 09:20:00');

        $res = $this->service($this->office())->clockIn($this->employee, 3.10, 101.60, null, null, $now);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertSame('late', $record->status);
        $this->assertContains('late', $record->flags);
    }

    public function test_missing_gps_is_neither_blocked_nor_flagged(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');

        // No coordinates at all (denied browser permission) — never strand staff.
        $res = $this->service($this->office())->clockIn($this->employee, null, null, null, null, $now);

        $this->assertSame('ok', $res['status']);
        $this->assertNull($this->employee->attendanceRecords()->onDate($now)->first()->in_radius);
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
}
