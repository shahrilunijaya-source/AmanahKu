<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\AttendanceReportController;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The exception chips above the ledger: how many rows are a missing clock-out, a
 * no-punch, a short day or a late arrival.
 *
 * Frozen at Wed 15 Jul 2026, so 'week' spans Mon 13 – Wed 15 Jul. A test that cares
 * about one specific day uses a one-day custom range instead, so an unrelated
 * unclocked weekday elsewhere in the window cannot move the number under test.
 */
class AttendanceReportSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));

        $this->tenant = Tenant::create([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'initials' => 'AL',
        ]);

        $user = User::create([
            'name' => 'HR Manager',
            'email' => 'hr@example.com',
            'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->viewer = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'name' => 'HR Manager',
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function screenData(array $query = []): array
    {
        $request = Request::create('/app/attendance-report', 'GET', $query);
        $request->attributes->set('tenantScope', 'company');
        $request->attributes->set('tenantRole', 'hr');
        $request->attributes->set('employee', $this->viewer);

        return app(AttendanceReportController::class)->screenData($request);
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, int>
     */
    private function counts(array $query = []): array
    {
        return $this->screenData($query)['counts'];
    }

    /** A window of exactly one day, so nothing else in the week can move the count. */
    private function onlyDay(string $date): array
    {
        return ['gran' => 'custom', 'from' => $date, 'to' => $date];
    }

    /** @param list<string> $flags */
    private function record(string $date, string $in, ?string $out, string $status = 'on_time', ?int $minutes = 480, array $flags = []): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->viewer->id,
            'date' => $date, 'clock_in' => $in, 'clock_out' => $out,
            'status' => $status, 'worked_minutes' => $out === null ? null : $minutes,
            'flags' => $flags,
        ]);
    }

    public function test_a_missed_day_is_counted_in_the_no_punch_lens(): void
    {
        // No threshold: one unclocked past day is one no-punch row, no more and no less.
        $this->assertSame(1, $this->counts($this->onlyDay('2026-07-14'))['absent']);
    }

    public function test_multiple_missed_days_are_all_counted(): void
    {
        // Mon 13 and Tue 14 are past and unclocked; Wed 15 is today and still pending.
        $this->assertSame(2, $this->counts(['gran' => 'week'])['absent']);
    }

    public function test_late_days_are_counted_in_the_late_lens(): void
    {
        $this->record('2026-07-14', '09:41:00', '18:00:00', status: 'late');

        $this->assertSame(1, $this->counts(['gran' => 'week'])['late']);
    }

    public function test_today_without_a_punch_is_not_counted_as_absent(): void
    {
        // 'pending', not 'absent' — nobody is absent for a day that has not ended.
        $this->assertSame(0, $this->counts(['gran' => 'day'])['absent']);
    }

    public function test_approved_leave_is_not_counted_as_absent(): void
    {
        $type = LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Annual Leave', 'entitlement' => 14,
        ]);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->viewer->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-07-13',
            'date_to' => '2026-07-15', 'days' => 3, 'status' => 'approved',
        ]);

        $this->assertSame(0, $this->counts(['gran' => 'week'])['absent']);
    }

    public function test_an_off_site_day_is_not_counted_as_late(): void
    {
        $this->record('2026-07-14', '08:50:00', '18:00:00', flags: ['out_of_radius_in']);

        $this->assertSame(0, $this->counts($this->onlyDay('2026-07-14'))['late']);
    }

    public function test_the_caption_names_the_period_it_totals(): void
    {
        // A block labelled "this week" over a past week's figures is worse than no label.
        $this->assertSame(
            'This week',
            $this->screenData(['gran' => 'week'])['totals']['caption']['en']
        );
        $this->assertSame(
            'Week 6 – 10 Jul',
            $this->screenData(['gran' => 'week', 'offset' => '-1'])['totals']['caption']['en']
        );

        // Only a past week needs the word: its label is a bare "6 – 10 Jul". A past
        // month already names itself, and prefixing it read "Week June 2026".
        $this->assertSame(
            'June 2026',
            $this->screenData(['gran' => 'month', 'offset' => '-1'])['totals']['caption']['en']
        );
        $this->assertSame(
            'Tue, 14 Jul',
            $this->screenData(['gran' => 'day', 'offset' => '-1'])['totals']['caption']['en']
        );
    }

    public function test_a_fully_present_person_is_in_no_lens(): void
    {
        $this->record('2026-07-13', '08:50:00', '18:00:00');
        $this->record('2026-07-14', '08:50:00', '18:00:00');

        $counts = $this->counts(['gran' => 'week']);

        $this->assertSame(0, $counts['absent']);
        $this->assertSame(0, $counts['late']);
        $this->assertSame(0, $counts['short']);
        $this->assertSame(0, $counts['miss']);
    }

    public function test_lens_counts_equal_the_rows_that_lens_returns(): void
    {
        $this->record('2026-07-14', '09:41:00', '18:00:00', status: 'late');

        $counts = $this->counts(['gran' => 'week']);
        $rows = $this->screenData(['gran' => 'week', 'lens' => 'late'])['rows'];

        $this->assertSame($counts['late'], $rows->count(),
            'a chip that says 1 and a table that shows 3 is a bug the user sees');
    }

    public function test_short_hours_days_are_counted_in_the_short_lens(): void
    {
        $this->record('2026-07-14', '09:00:00', '13:00:00', minutes: 240, flags: ['short_hours']);

        $this->assertSame(1, $this->counts(['gran' => 'week'])['short']);
    }

    public function test_a_short_day_that_started_on_time_is_neither_late_nor_absent(): void
    {
        $this->record('2026-07-14', '08:50:00', '13:00:00', minutes: 250, flags: ['short_hours']);

        $counts = $this->counts($this->onlyDay('2026-07-14'));

        $this->assertSame(1, $counts['short']);
        $this->assertSame(0, $counts['late']);
        $this->assertSame(0, $counts['absent']);
    }

    public function test_a_full_length_day_is_not_counted_as_short(): void
    {
        $this->record('2026-07-14', '09:00:00', '18:00:00', minutes: 540);

        $this->assertSame(0, $this->counts(['gran' => 'week'])['short']);
    }

    public function test_a_missing_clock_out_is_counted_in_the_missing_lens(): void
    {
        $this->record('2026-07-14', '09:00:00', null);

        $this->assertSame(1, $this->counts(['gran' => 'week'])['miss']);
    }
}
