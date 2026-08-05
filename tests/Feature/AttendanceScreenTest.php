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

class AttendanceScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_screen_renders_the_shelf_and_week_list_for_an_employee(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'status' => 'on_time',
            'clock_in' => '09:00:00',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('uj-at-shelf');
        $response->assertSee('uj-at-day');
    }

    public function test_week_list_shows_only_current_week_rows(): void
    {
        $thisWeekRecord = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->addDay()->toDateString(), // 2026-07-14 (Tue)
            'status' => 'on_time',
        ]);

        $olderRecord = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->subDays(5)->toDateString(), // 2026-07-08 (Wed)
            'status' => 'on_time',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();

        // Assert the older record is present in the `earlierRecords` view data, not `weekRecords`
        $weekRecords = $response->viewData('weekRecords');
        $earlierRecords = $response->viewData('earlierRecords');
        $this->assertTrue(
            $weekRecords->contains(fn ($r) => $r->id === $thisWeekRecord->id),
            'The current week record must be in weekRecords.'
        );
        $this->assertFalse(
            $weekRecords->contains(fn ($r) => $r->id === $olderRecord->id),
            'The older record must not be in weekRecords.'
        );
        $this->assertTrue(
            $earlierRecords->contains(fn ($r) => $r->id === $olderRecord->id),
            'The older record must be present in earlierRecords view data.'
        );

        // Assert day 14 (this week) appears in a tile
        $response->assertSee('<span class="d">14</span>', false);
    }

    public function test_empty_week_shows_the_empty_state(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('uj-at-empty');
        $response->assertDontSee('uj-at-day');
    }

    public function test_see_all_link_is_gated_on_permission(): void
    {
        // Plain employee should NOT see the see-all link (attendance-report)
        $responseEmp = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $responseEmp->assertOk();
        $responseEmp->assertDontSee('attendance-report');

        // HR user SHOULD see the see-all link
        $hrUser = User::create([
            'name' => 'HR User',
            'email' => 'hr@example.com',
            'password' => Hash::make('password'),
        ]);

        $hrUser->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $hrUser->id,
            'name' => 'HR User',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $responseHr = $this->actingAs($hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $responseHr->assertOk();
        $responseHr->assertSee('attendance-report');
    }

    public function test_clock_form_keeps_its_post_contract(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('action="'.route('attendance.clock').'"', false);
        $response->assertSee('name="action"', false);
        $response->assertSee('name="latitude"', false);
        $response->assertSee('name="longitude"', false);
        $response->assertSee('name="photo"', false);
        $response->assertSee('name="justification"', false);
    }

    /**
     * Smoke check only: it proves the accuracy-aware locator is wired into the screen, not
     * that it picks the better fix — that logic is browser-side and PHPUnit cannot exercise
     * it. A cold GPS reports a coarse network fix first and refines seconds later, so the
     * screen must not punch on the first fix or on a cached one; verify that behaviour by
     * hand against the geofence, per the sequence in the release notes.
     */
    public function test_screen_ships_the_accuracy_aware_locator(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('bestFix(', false);
        $response->assertSee('maximumAge: 0', false);
    }

    /**
     * The clock-without-location fallback is a from-anywhere punch. It must stay hidden
     * until a real attempt has failed, otherwise a browser that refuses location on page
     * load puts it on screen before the staff member has tried to clock at all.
     */
    public function test_no_location_fallback_is_gated_behind_an_actual_attempt(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('attempted: false', false);
        $response->assertSee('x-show="attempted"', false);
    }

    public function test_status_tone_is_correct_on_every_row(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->toDateString(),
            'status' => 'on_time',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->addDay()->toDateString(),
            'status' => 'late',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->addDays(2)->toDateString(),
            'status' => 'on_time',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertSame(
            2,
            substr_count($content, 'class="uj-at-status" data-tone="amber"'),
            'Every row status pill must carry its own tone; a shared variable overwritten in the loop makes later rows fall back to muted.'
        );
        $this->assertSame(
            4,
            substr_count($content, 'class="uj-at-status" data-tone="success"'),
            'Every row status pill must carry its own tone; a shared variable overwritten in the loop makes later rows fall back to muted.'
        );
    }

    public function test_geofence_sentence_is_not_claimed_without_evidence(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->toDateString(),
            'in_radius' => null,
            'out_radius' => null,
            'flags' => [],
            'location' => null,
            'status' => 'on_time',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('Location was not recorded.');
        $response->assertDontSee('inside the expected geofence');
    }

    public function test_geofence_sentence_reports_inside_when_both_punches_were_inside(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->toDateString(),
            'in_radius' => true,
            'out_radius' => true,
            'flags' => [],
            'status' => 'on_time',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('Both punches landed inside the expected geofence');
        $response->assertDontSee('outside the expected geofence');
    }

    public function test_earlier_days_does_not_link_to_the_staff_report(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->toDateString(),
            'status' => 'on_time',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->subDays(5)->toDateString(),
            'status' => 'on_time',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertDontSee(route('app.screen', 'attendance-report'));
    }
}
