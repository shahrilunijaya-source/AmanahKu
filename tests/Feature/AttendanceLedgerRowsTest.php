<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Attendance\LedgerBuilder;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AttendanceLedgerRowsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $alice;

    /** @var list<string> */
    private array $days = ['2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-08-20 10:00:00'));

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        $this->alice = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Alice Tan',
            'status' => 'active', 'workload' => 'green',
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function build(): Collection
    {
        return app(LedgerBuilder::class)->build(
            // Mirrors the controller's own employee query: active() only clears the
            // archive, so a resigned person still needs excluding by hand.
            Employee::active()->where('status', '!=', 'resigned')->with('department:id,name')->get(),
            AttendanceRecord::all(),
            LeaveRequest::where('status', 'approved')->with('leaveType:id,name')->get(),
            $this->days,
            CarbonImmutable::parse('2026-08-20'),
        );
    }

    public function test_an_employee_with_no_records_still_gets_a_row_per_working_day(): void
    {
        $rows = $this->build();

        $this->assertCount(4, $rows, 'one row per working day even with zero records');
        $this->assertSame(
            ['absent', 'absent', 'absent', 'pending'],
            $rows->pluck('status')->all(),
            'past days read as no-punch; today is still pending'
        );
    }

    public function test_a_clocked_day_carries_its_times_and_hours(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'date' => '2026-08-18', 'clock_in' => '08:52:00', 'clock_out' => '17:35:00',
            'status' => 'on_time', 'worked_minutes' => 523, 'flags' => [],
        ]);

        $row = $this->build()->firstWhere('date', '2026-08-18');

        $this->assertSame('08:52', $row['in']);
        $this->assertSame('17:35', $row['out']);
        $this->assertSame(8.72, round($row['hours'], 2));
        $this->assertSame('ontime', $row['status']);
    }

    public function test_a_clock_in_with_no_clock_out_on_a_past_day_is_missing_out(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'date' => '2026-08-18', 'clock_in' => '08:52:00', 'clock_out' => null,
            'status' => 'on_time', 'flags' => [],
        ]);

        $row = $this->build()->firstWhere('date', '2026-08-18');

        $this->assertSame('miss', $row['status']);
        $this->assertNull($row['hours']);
    }

    public function test_an_open_punch_today_is_pending_not_broken(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'date' => '2026-08-20', 'clock_in' => '08:52:00', 'clock_out' => null,
            'status' => 'on_time', 'flags' => [],
        ]);

        $row = $this->build()->firstWhere('date', '2026-08-20');

        $this->assertSame('ontime', $row['status'], 'still mid-shift, not a missing clock-out');
    }

    public function test_approved_leave_reads_as_leave_and_carries_its_type(): void
    {
        $type = LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Annual leave', 'entitlement' => 14,
        ]);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-08-18',
            'date_to' => '2026-08-18', 'days' => 1, 'status' => 'approved',
        ]);

        $row = $this->build()->firstWhere('date', '2026-08-18');

        $this->assertSame('leave', $row['status']);
        $this->assertSame('Annual leave', $row['leaveType']);
    }

    public function test_a_short_day_is_flagged_and_a_very_short_one_is_a_half_day(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'date' => '2026-08-17', 'clock_in' => '09:00:00', 'clock_out' => '12:00:00',
            'status' => 'on_time', 'worked_minutes' => 180, 'flags' => ['short_hours'],
        ]);

        $row = $this->build()->firstWhere('date', '2026-08-17');

        $this->assertSame('half', $row['status'], 'under 5 hours reads as a half day');
        $this->assertContains('short', $row['flags']);
    }

    public function test_an_off_site_punch_is_flagged_and_advertises_a_map_point(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'date' => '2026-08-18', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00',
            'status' => 'on_time', 'worked_minutes' => 540,
            'flags' => ['out_of_radius_in'], 'latitude' => 3.1368, 'longitude' => 101.6546,
        ]);

        $row = $this->build()->firstWhere('date', '2026-08-18');

        $this->assertContains('off', $row['flags']);
        $this->assertTrue($row['hasPoint']);
    }

    public function test_an_off_site_flag_without_coordinates_offers_no_map(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'date' => '2026-08-18', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00',
            'status' => 'on_time', 'worked_minutes' => 540,
            'flags' => ['out_of_radius_in'], 'latitude' => null, 'longitude' => null,
        ]);

        $this->assertFalse($this->build()->firstWhere('date', '2026-08-18')['hasPoint']);
    }

    public function test_an_archived_employee_gets_no_rows(): void
    {
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Gone Person',
            'status' => 'resigned', 'workload' => 'green',
        ]);

        $this->assertSame(['Alice Tan'], $this->build()->pluck('name')->unique()->all());
    }

    public function test_rows_carry_the_record_id_only_when_a_record_exists(): void
    {
        $rec = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'date' => '2026-08-18', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00',
            'status' => 'on_time', 'worked_minutes' => 540, 'flags' => [],
        ]);

        $rows = $this->build();

        $this->assertSame($rec->id, $rows->firstWhere('date', '2026-08-18')['recordId']);
        $this->assertNull($rows->firstWhere('date', '2026-08-17')['recordId'],
            'a synthesized no-punch row has nothing to reverse');
    }
}
