<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttendanceScreenDataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // The assertions depend on where fixtures fall relative to both the week
        // boundary and the 30-day fetch window, and a floating now() silently
        // moves fixtures outside that window on some calendar dates.
        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));

        $this->tenant = Tenant::create([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'initials' => 'AL',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'name' => 'Test User',
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    public function test_week_records_exclude_days_before_this_week(): void
    {
        $thisWeekRecord = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->addDay()->toDateString(),
            'status' => 'on_time',
        ]);

        $olderRecord = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->subDays(3)->toDateString(),
            'status' => 'on_time',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();

        $weekRecords = $response->viewData('weekRecords');
        $earlierRecords = $response->viewData('earlierRecords');

        $this->assertCount(1, $weekRecords);
        $this->assertEquals($thisWeekRecord->id, $weekRecords->first()->id);

        $this->assertCount(1, $earlierRecords);
        $this->assertEquals($olderRecord->id, $earlierRecords->first()->id);
    }

    public function test_week_worked_minutes_sums_only_current_week(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->toDateString(),
            'status' => 'on_time',
            'worked_minutes' => 480,
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->addDays(1)->toDateString(),
            'status' => 'on_time',
            'worked_minutes' => 300,
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->subDays(3)->toDateString(),
            'status' => 'on_time',
            'worked_minutes' => 400,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();

        $this->assertSame(780, $response->viewData('weekWorkedMinutes'));
    }

    public function test_week_baseline_delta_uses_expected_min_hours_of_completed_days(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->toDateString(),
            'status' => 'on_time',
            'worked_minutes' => 550,
            'expected_min_hours' => 9.0,
            'clock_in' => '08:00:00',
            'clock_out' => '17:10:00',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->addDays(1)->toDateString(),
            'status' => 'on_time',
            'worked_minutes' => null,
            'expected_min_hours' => 9.0,
            'clock_in' => '08:00:00',
            'clock_out' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();

        $this->assertSame(10, $response->viewData('weekBaselineDeltaMinutes'));
    }

    public function test_late_this_month_counts_only_current_month_late_records(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfMonth()->addDays(1)->toDateString(),
            'status' => 'late',
        ]);

        $lastMonthRecord = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfMonth()->subDays(2)->toDateString(),
            'status' => 'late',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfMonth()->addDays(2)->toDateString(),
            'status' => 'on_time',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();

        $records = $response->viewData('records');
        $this->assertTrue(
            $records->contains(fn ($r) => $r->id === $lastMonthRecord->id),
            'The previous-month record must be inside the 30-day fetch, or the month filter is never exercised.'
        );

        $this->assertSame(1, $response->viewData('lateThisMonth'));
    }

    public function test_off_site_this_month_counts_either_radius_flag(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfMonth()->addDays(1)->toDateString(),
            'status' => 'on_time',
            'flags' => ['out_of_radius_in'],
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfMonth()->addDays(2)->toDateString(),
            'status' => 'on_time',
            'flags' => ['out_of_radius_out'],
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfMonth()->addDays(3)->toDateString(),
            'status' => 'on_time',
            'flags' => [],
        ]);

        $lastMonthRecord = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfMonth()->subDays(2)->toDateString(),
            'status' => 'on_time',
            'flags' => ['out_of_radius_in'],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();

        $records = $response->viewData('records');
        $this->assertTrue(
            $records->contains(fn ($r) => $r->id === $lastMonthRecord->id),
            'The previous-month record must be inside the 30-day fetch, or the month filter is never exercised.'
        );

        $this->assertSame(2, $response->viewData('offSiteThisMonth'));
    }

    /**
     * The screen mirrors the server's lateness rule so a late punch opens the reason drawer
     * in place. It cannot do that without the grace, which lives on the tenant.
     */
    public function test_the_payload_carries_the_tenant_late_grace(): void
    {
        $this->tenant->update(['late_grace_minutes' => 25]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();

        $this->assertSame(25, $response->viewData('lateGraceMinutes'));
    }

    public function test_returns_zeros_for_employee_without_records(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();

        $this->assertEmpty($response->viewData('weekRecords'));
        $this->assertEmpty($response->viewData('earlierRecords'));
        $this->assertSame(0, $response->viewData('weekWorkedMinutes'));
        $this->assertSame(0, $response->viewData('weekBaselineDeltaMinutes'));
        $this->assertSame(0, $response->viewData('lateThisMonth'));
        $this->assertSame(0, $response->viewData('offSiteThisMonth'));
    }
}
