<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Employee dashboard people widgets + action queue:
 *   · "People this month" card — colleagues on approved leave (next 7d) and this
 *     month's birthdays (real DOB), each with a one-tap prefilled-DM wish.
 *   · Birthday-today banner.
 *   · "Action needed" — the real user's verify/approve queue, surfaced on the dash.
 *
 * Dates are built relative to now() so the suite is time-independent.
 */
class DashboardPeopleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function member(string $role, string $name, ?int $reportsToId = null, ?string $dob = null): Employee
    {
        $this->seq++;
        $user = User::create(['name' => $name, 'email' => "user{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId, 'date_of_birth' => $dob,
        ]);
    }

    private function actingAsEmployee(Employee $e, string $persona = 'employee'): self
    {
        $this->actingAs($e->user)->withSession(['current_tenant' => $this->tenant->id, 'persona' => $persona]);

        return $this;
    }

    public function test_employee_dashboard_shows_colleague_on_leave_with_wish_and_birthday(): void
    {
        $viewer = $this->member('employee', 'Viewer');
        // Birthday later this month (not today) — a whole-month day that always exists.
        $birthMonthDob = now()->copy()->year(1990)->day(min(now()->day + 1, 28))->format('Y-m-d');
        $celebrant = $this->member('employee', 'Aminah Celebrant', null, $birthMonthDob);

        // A colleague on approved leave spanning today.
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 18]);
        $onLeaver = $this->member('employee', 'Farid OnLeave');
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $onLeaver->id, 'leave_type_id' => $type->id,
            'date_from' => now()->toDateString(), 'date_to' => now()->copy()->addDays(2)->toDateString(),
            'days' => 3, 'status' => 'approved',
        ]);

        $html = $this->actingAsEmployee($viewer)->get('/app/dash')->assertOk()->getContent();

        // People-pulse card + both people present.
        $this->assertStringContainsString('People this month', $html);
        $this->assertStringContainsString('Farid OnLeave', $html);
        $this->assertStringContainsString('Aminah Celebrant', $html);
        // One-tap wish deep-links to messages with a prefilled draft targeting the celebrant.
        $this->assertStringContainsString('to='.$celebrant->id, $html);
        $this->assertStringContainsString('draft=', $html);
    }

    public function test_birthday_today_shows_the_greeting_banner(): void
    {
        $viewer = $this->member('employee', 'Viewer');
        $todayDob = now()->copy()->year(1988)->format('Y-m-d'); // same month+day as today
        $this->member('employee', 'Zaki Today', null, $todayDob);

        $this->actingAsEmployee($viewer)->get('/app/dash')->assertOk()
            ->assertSee('birthday today! Send a wish 🎉', false)
            ->assertSee('Zaki');
    }

    public function test_action_needed_surfaces_the_verify_queue_on_the_dashboard(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        Claim::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'type' => 'mileage', 'title' => 'Client run', 'amount' => 40, 'date' => now()->toDateString(),
            'status' => 'submitted',
        ]);

        // The manager (role manager → own persona) sees the item routed to them to verify.
        $this->actingAsEmployee($manager, 'manager')->get('/app/dash')->assertOk()
            ->assertSee('Action needed')
            ->assertSee('Verify')
            ->assertSee('Reportee');
    }

    public function test_a_plain_employee_with_no_queue_sees_no_action_needed_card(): void
    {
        $viewer = $this->member('employee', 'Viewer');

        $html = $this->actingAsEmployee($viewer)->get('/app/dash')->assertOk()->getContent();
        $this->assertStringNotContainsString('Action needed', $html);
    }

    public function test_wish_deeplink_prefills_the_message_composer(): void
    {
        $viewer = $this->member('employee', 'Viewer');
        $celebrant = $this->member('employee', 'Aminah');

        $this->actingAsEmployee($viewer)
            ->get('/app/messages?to='.$celebrant->id.'&draft='.urlencode('Happy birthday, Aminah! 🎉'))
            ->assertOk()
            ->assertSee('Happy birthday, Aminah!', false);
    }
}
