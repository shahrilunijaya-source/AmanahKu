<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The nightly sweep that closes punches nobody clocked out of, stamped at the shift end.
 * Unijaya credits nothing past the shift end, so the cap costs the employee no time —
 * but the sweep must never stamp a boundary that has not actually passed.
 */
class AutoClockOutTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Clocker',
            'email' => 'clocker@acme.test', 'password' => bcrypt('password'),
        ]);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Clocker', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function openPunch(string $date, string $clockIn, ?string $start, ?string $end, array $overrides = []): AttendanceRecord
    {
        return AttendanceRecord::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => $date,
            'clock_in' => $clockIn,
            'expected_start' => $start,
            'expected_end' => $end,
            'expected_min_hours' => 8.0,
            'status' => 'on_time',
        ], $overrides));
    }

    private function sweepAt(string $at): void
    {
        Carbon::setTestNow(Carbon::parse($at));
        $this->artisan('attendance:auto-clock-out')->assertSuccessful();
        Carbon::setTestNow();
    }

    public function test_day_shift_left_open_is_closed_at_its_shift_end(): void
    {
        $record = $this->openPunch('2026-07-02', '09:57:00', '09:00:00', '18:00:00');

        $this->sweepAt('2026-07-02 23:59:00');

        $record->refresh();
        $this->assertSame('18:00', Carbon::parse($record->clock_out)->format('H:i'));
        $this->assertSame(483, $record->worked_minutes);
        $this->assertContains('auto_out', $record->flags);
        // Stamped at the end, so by definition not early — and 09:57 to 18:00 still clears
        // the 8h minimum, so nothing else is wrong with the day.
        $this->assertNotContains('early_out', $record->flags);
        $this->assertNotContains('short_hours', $record->flags);
    }

    /**
     * A late clock-in capped at the shift end is genuinely short of its hours. That flag is
     * the only signal HR gets that the auto-closed day was not a full one.
     */
    public function test_a_late_clock_in_capped_at_shift_end_is_flagged_short(): void
    {
        $record = $this->openPunch('2026-07-02', '11:49:00', '09:00:00', '18:00:00');

        $this->sweepAt('2026-07-02 23:59:00');

        $record->refresh();
        $this->assertSame(371, $record->worked_minutes);
        $this->assertContains('auto_out', $record->flags);
        $this->assertContains('short_hours', $record->flags);
    }

    public function test_the_employee_is_told_it_happened(): void
    {
        $this->openPunch('2026-07-02', '09:00:00', '09:00:00', '18:00:00');

        $this->sweepAt('2026-07-02 23:59:00');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->employee->user_id,
            'title' => 'We clocked you out',
        ]);
    }

    /** A second run finds nothing to close, so it cannot bell the same person twice. */
    public function test_a_second_run_changes_nothing(): void
    {
        $record = $this->openPunch('2026-07-02', '09:00:00', '09:00:00', '18:00:00');

        $this->sweepAt('2026-07-02 23:59:00');
        $this->sweepAt('2026-07-02 23:59:30');

        $this->assertSame(1, AppNotification::where('user_id', $this->employee->user_id)->count());
        $this->assertSame(540, $record->refresh()->worked_minutes);
    }

    /**
     * An overnight 22:00–06:00 shift is still mid-shift when the sweep runs at 23:59 on the
     * night it started. It only becomes closable on the following night's run.
     */
    public function test_an_overnight_shift_is_left_alone_on_the_night_it_starts(): void
    {
        $record = $this->openPunch('2026-07-02', '22:00:00', '22:00:00', '06:00:00');

        $this->sweepAt('2026-07-02 23:59:00');

        $this->assertNull($record->refresh()->clock_out);
    }

    public function test_an_overnight_shift_is_closed_the_following_night(): void
    {
        $record = $this->openPunch('2026-07-02', '22:00:00', '22:00:00', '06:00:00');

        $this->sweepAt('2026-07-03 23:59:00');

        $record->refresh();
        $this->assertSame('06:00', Carbon::parse($record->clock_out)->format('H:i'));
        $this->assertSame(480, $record->worked_minutes);
        $this->assertContains('auto_out', $record->flags);
    }

    /** No stamped end time is no boundary to close at. Left open for HR to type in. */
    public function test_a_record_with_no_expected_end_is_left_open(): void
    {
        $record = $this->openPunch('2026-07-02', '09:00:00', null, null, ['expected_min_hours' => null]);

        $this->sweepAt('2026-07-02 23:59:00');

        $this->assertNull($record->refresh()->clock_out);
    }

    /** A real punch is never overwritten, however late it was. */
    public function test_an_already_closed_punch_is_untouched(): void
    {
        $record = $this->openPunch('2026-07-02', '09:00:00', '09:00:00', '18:00:00', [
            'clock_out' => '20:30:00', 'worked_minutes' => 690,
        ]);

        $this->sweepAt('2026-07-02 23:59:00');

        $record->refresh();
        $this->assertSame('20:30', Carbon::parse($record->clock_out)->format('H:i'));
        $this->assertSame(690, $record->worked_minutes);
        $this->assertNotContains('auto_out', $record->flags ?? []);
    }

    /** The close is the point; the bell is a courtesy. No login account must not block it. */
    public function test_a_staffer_with_no_login_account_still_gets_closed(): void
    {
        $accountless = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'No Login', 'status' => 'active', 'workload' => 'green',
        ]);
        $record = $this->openPunch('2026-07-02', '09:00:00', '09:00:00', '18:00:00', [
            'employee_id' => $accountless->id,
        ]);

        $this->sweepAt('2026-07-02 23:59:00');

        $this->assertSame(540, $record->refresh()->worked_minutes);
    }
}
