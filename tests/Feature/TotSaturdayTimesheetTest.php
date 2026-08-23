<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use App\Models\User;
use App\Timesheet\DayCapacity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Unijaya works Mon–Fri plus the first Saturday of the month, which is the TOT day and
 * runs as a half day. That Saturday is full at 50%, so the submit gate must accept 50 and
 * refuse 100 there — the mirror of what the capture screen shows.
 *
 * The week under test is Mon 2026-07-27 to Sat 2026-08-01 (the first Saturday of August).
 */
class TotSaturdayTimesheetTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TimesheetCategory $work;

    private Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // The Monday after the week under test, so every day in it is in the past and the
        // week's cutoff (that Saturday) has been reached.
        Carbon::setTestNow('2026-08-03 09:00:00');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->work = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Others', 'requires_project' => false]);

        $user = User::create(['name' => 'Staffer', 'email' => 'staffer@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->staff = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Staffer', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Mon–Fri at 100% each, plus whatever the Saturday should carry. */
    private function submitWeek(float $saturdayPercent): TestResponse
    {
        $entries = [];

        foreach (['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31'] as $day) {
            $entries[] = ['entry_date' => $day, 'category_id' => $this->work->id, 'percentage' => 100];
        }

        $entries[] = ['entry_date' => '2026-08-01', 'category_id' => $this->work->id, 'percentage' => $saturdayPercent];

        return $this->post('/app/timesheets', [
            'week_start' => '2026-07-27',
            'entries' => $entries,
            'submit_now' => 1,
        ]);
    }

    public function test_the_tot_saturday_submits_at_fifty_percent(): void
    {
        $this->submitWeek(50)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('timesheets', ['employee_id' => $this->staff->id, 'status' => 'submitted']);
    }

    public function test_a_full_hundred_on_the_tot_saturday_is_refused(): void
    {
        $this->submitWeek(100)->assertSessionHasErrors('submit');

        $this->assertDatabaseMissing('timesheets', ['employee_id' => $this->staff->id, 'status' => 'submitted']);
    }

    public function test_capacity_is_fifty_only_on_the_first_saturday(): void
    {
        $this->assertEqualsWithDelta(50.0, DayCapacity::for('2026-08-01'), 0.001);   // first Saturday
        $this->assertEqualsWithDelta(100.0, DayCapacity::for('2026-08-08'), 0.001);  // second Saturday
        $this->assertEqualsWithDelta(100.0, DayCapacity::for('2026-07-30'), 0.001);  // a Thursday
    }
}
