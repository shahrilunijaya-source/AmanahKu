<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Restores the dashboard coverage that the two-scope rewrite dropped along with the
 * four-persona blocks. DashboardScopeTest pins the DATA contract (who is routed what,
 * which modules gate which rows, the headline arithmetic); this file pins that the data
 * actually REACHES THE PAGE — a queue that is computed correctly but never rendered is
 * the exact regression the old `Action needed` tests existed to catch.
 *
 * Deliberately not restored, because their rendering surface is gone rather than moved:
 *   · recent-achievements archive filtering — `module.performance` is OFF and
 *     screens/achievements.blade.php no longer exists, so nothing renders it.
 *   · the birthday banner and "People this month" card — cut from the redesign; the
 *     surviving half (colleagues on leave) is covered below as the `around` rail card.
 *
 * Dates are relative to now() so the suite stays time-independent.
 */
class DashboardRenderedQueueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function member(string $role, string $name, ?int $reportsToId = null): Employee
    {
        $this->seq++;
        $user = User::create([
            'name' => $name,
            'email' => "user{$this->seq}@example.com",
            'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'name' => $name,
            'status' => 'active',
            'workload' => 'green',
            'reports_to_id' => $reportsToId,
        ]);
    }

    private function dash(Employee $e, ?string $scope = null): TestResponse
    {
        return $this->actingAs($e->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/dash'.($scope ? '?scope='.$scope : ''));
    }

    /**
     * The company queue must render, not merely compute: the requester's name, the
     * stage badge that says which of the two approval steps this is, and an action
     * the viewer can actually press. This replaces the old
     * `test_action_needed_surfaces_the_verify_queue_on_the_dashboard`.
     */
    public function test_company_queue_renders_the_requester_stage_and_action(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);

        Claim::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $report->id,
            'type' => 'mileage',
            'title' => 'Client run',
            'amount' => 40,
            'date' => now()->toDateString(),
            'status' => 'submitted',
        ]);

        $this->dash($manager, 'company')->assertOk()
            ->assertSee('Reportee')
            // the verify/approve distinction is the whole point of merging the personas
            ->assertSee('verify', false)
            ->assertSee('Waiting on you');
    }

    /**
     * A plain employee has no verify/approve queue at all, so the company scope's
     * rows must never leak into their page. Replaces
     * `test_a_plain_employee_with_no_queue_sees_no_action_needed_card`.
     */
    public function test_a_plain_employee_never_sees_another_persons_request(): void
    {
        $manager = $this->member('manager', 'Manager');
        $report = $this->member('employee', 'Reportee', $manager->id);
        $bystander = $this->member('employee', 'Bystander');

        Claim::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $report->id,
            'type' => 'mileage',
            'title' => 'Client run',
            'amount' => 40,
            'date' => now()->toDateString(),
            'status' => 'submitted',
        ]);

        $html = $this->dash($bystander)->assertOk()->getContent();

        $this->assertStringNotContainsString('Waiting on you', $html);
        $this->assertStringNotContainsString('Reportee', $html);
    }

    /**
     * The surviving half of the old "People this month" card: colleagues on approved
     * leave still surface, now through the calendar widget. It affects when the viewer
     * can take their own leave, which is why it stayed through two redesigns.
     */
    public function test_the_calendar_lists_a_colleague_on_approved_leave(): void
    {
        $viewer = $this->member('employee', 'Viewer');
        $onLeave = $this->member('employee', 'Farid OnLeave');

        $type = LeaveType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Annual',
            'entitlement' => 18,
        ]);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $onLeave->id,
            'leave_type_id' => $type->id,
            'date_from' => now()->toDateString(),
            'date_to' => now()->copy()->addDays(2)->toDateString(),
            'days' => 3,
            'status' => 'approved',
        ]);

        $this->dash($viewer)->assertOk()->assertSee('Farid OnLeave');
    }

    /**
     * The viewer DOES see their own approved leave here, which reverses the old
     * "around you" rail card's rule. That card was company news, where reading your
     * own name back was noise; this is a calendar, and a calendar that hides the
     * days you are off is lying about your month.
     */
    public function test_the_calendar_includes_the_viewers_own_leave(): void
    {
        $viewer = $this->member('employee', 'Solo Viewer');

        $type = LeaveType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Annual',
            'entitlement' => 18,
        ]);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $viewer->id,
            'leave_type_id' => $type->id,
            'date_from' => now()->toDateString(),
            'date_to' => now()->copy()->addDays(2)->toDateString(),
            'days' => 3,
            'status' => 'approved',
        ]);

        // Asserted against the widget payload, not the page HTML: the viewer's own name
        // legitimately appears in the header and avatar chrome, so a whole-page string
        // match would pass for reasons unrelated to this rule.
        $out = $this->dash($viewer)->assertOk()->viewData('widgets')['calendar']['outThisMonth'];

        $this->assertContains('Solo Viewer', $out->map(fn ($l) => $l->employee?->name)->all());
    }

    /**
     * A shift clocked in at 23:00 leaves its open record dated *yesterday*, so the
     * dashboard's "on today" lookups found nothing after midnight: the greeting told an
     * employee mid-shift they had "not clocked in yet", and the "You are still clocked
     * in" reminder vanished for exactly the person who needed it. Same yesterday-or-today
     * bound as AttendanceRecord::scopeOpenPunch().
     */
    public function test_dashboard_reads_the_open_overnight_punch_after_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-16 01:30:00'));
        $viewer = $this->member('employee', 'Night Owl');

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $viewer->id,
            'date' => '2026-07-15',
            'status' => 'late',
            'clock_in' => '23:00:00',
        ]);

        $response = $this->dash($viewer)->assertOk();

        $this->assertStringContainsString('clocked in at 23:00', $response->viewData('head')['sub']);

        $this->assertContains(
            'You are still clocked in',
            array_column(collect($response->viewData('widgets')['tasks']['groups'])->firstWhere('id', 'owed')['rows'], 'title'),
            'The open overnight punch must still raise the clock-out reminder after midnight.'
        );
    }
}
