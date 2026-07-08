<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\Asset;
use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LoanRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Support\StuckRequests;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Archiving a staff member must DETACH them from all live access and responsibility:
 * they can no longer act (middleware), their direct reports move up the chain, dotted-line
 * pivots drop, open task cards pass to their manager, and pending requests close. History
 * (approved leave, past records) is deliberately left intact.
 */
class ArchiveDetachmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function emp(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'active', 'workload' => 'green',
        ], $attrs));
    }

    private function hrActor(): User
    {
        $hr = User::create(['name' => 'HR', 'email' => 'hr@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->emp('HR', ['user_id' => $hr->id]);

        return $hr;
    }

    /** Archive through the real HR route so the controller cascade runs. */
    private function archiveViaHr(Employee $e): void
    {
        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/employees/{$e->id}/delete")
            ->assertRedirect('/app/directory');
    }

    // ── Access ────────────────────────────────────────────────────

    public function test_archived_user_is_blocked_from_app_routes(): void
    {
        $user = User::create(['name' => 'Gone', 'email' => 'gone@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->emp('Gone', ['user_id' => $user->id, 'archived_at' => now()]);

        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/directory')
            ->assertStatus(403);
    }

    public function test_active_user_is_not_blocked_by_the_archive_gate(): void
    {
        $user = User::create(['name' => 'Here', 'email' => 'here@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->emp('Here', ['user_id' => $user->id]);

        $response = $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/directory');

        // May be 200 or a downstream redirect (setup/onboarding), but never the archive 403.
        $this->assertNotSame(403, $response->getStatusCode());
    }

    // ── Reporting line ────────────────────────────────────────────

    public function test_archiving_repoints_direct_reports_up_the_chain(): void
    {
        $grandBoss = $this->emp('Grand Boss');
        $manager = $this->emp('Manager', ['reports_to_id' => $grandBoss->id]);
        $report = $this->emp('Report', ['reports_to_id' => $manager->id]);

        $this->archiveViaHr($manager);

        // The report now reports to the archived manager's own manager — never to an archived person.
        $this->assertSame($grandBoss->id, $report->fresh()->reports_to_id);
    }

    public function test_archiving_detaches_dotted_line_manager_pivots_both_ways(): void
    {
        $person = $this->emp('Person');
        $extraManager = $this->emp('Extra Manager');
        $subordinate = $this->emp('Subordinate');

        $person->additionalManagers()->attach($extraManager->id);   // person is managed by extraManager
        $subordinate->additionalManagers()->attach($person->id);    // person manages subordinate (dotted)

        $this->archiveViaHr($person);

        $this->assertDatabaseMissing('employee_manager', ['employee_id' => $person->id]);
        $this->assertDatabaseMissing('employee_manager', ['manager_id' => $person->id]);
    }

    // ── Obligations ───────────────────────────────────────────────

    public function test_archiving_closes_pending_requests_but_keeps_history(): void
    {
        $e = $this->emp('Leaver');
        $pendingLeave = LeaveRequest::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'date_from' => '2026-07-06', 'date_to' => '2026-07-07', 'days' => 2, 'status' => 'submitted']);
        $approvedLeave = LeaveRequest::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'date_from' => '2026-06-01', 'date_to' => '2026-06-02', 'days' => 2, 'status' => 'approved']);
        $pendingClaim = Claim::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'type' => 'expense', 'title' => 'X', 'amount' => 50, 'date' => '2026-07-01', 'status' => 'submitted']);

        $this->archiveViaHr($e);

        $this->assertSame('rejected', $pendingLeave->fresh()->status);
        $this->assertSame('rejected', $pendingClaim->fresh()->status);
        $this->assertSame('approved', $approvedLeave->fresh()->status); // history untouched
    }

    public function test_archiving_closes_pending_loans_and_releases_assets(): void
    {
        $e = $this->emp('Leaver');
        $loan = LoanRequest::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'reason' => 'Emergency', 'amount' => 1000, 'status' => 'submitted']);
        $laptop = Asset::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'name' => 'MacBook', 'status' => 'assigned']);

        $this->archiveViaHr($e);

        $this->assertSame('rejected', $loan->fresh()->status);  // pending loan closed
        $freed = $laptop->fresh();
        $this->assertNull($freed->employee_id);                 // asset released...
        $this->assertSame('available', $freed->status);         // ...back to the pool
    }

    public function test_archiving_moves_open_task_cards_to_the_manager(): void
    {
        $manager = $this->emp('Manager');
        $e = $this->emp('Doer', ['reports_to_id' => $manager->id]);
        $open = WorkItem::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'title' => 'Open task', 'type' => 'task', 'priority' => 'medium', 'status' => 'todo']);
        $done = WorkItem::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'title' => 'Done task', 'type' => 'task', 'priority' => 'medium', 'status' => 'done']);

        $this->archiveViaHr($e);

        $this->assertSame($manager->id, $open->fresh()->employee_id);   // open work transferred
        $this->assertSame($e->id, $done->fresh()->employee_id);         // finished work left as history
    }

    // ── Recognition & visibility feeds ────────────────────────────

    /** A staff member who has left must not linger in any live staff-facing feed. */
    public function test_dashboard_recent_achievements_hide_archived_recipients(): void
    {
        $viewer = User::create(['name' => 'Nabil', 'email' => 'nabil@example.com', 'password' => Hash::make('password')]);
        $viewer->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->emp('Nabil', ['user_id' => $viewer->id]);

        $active = $this->emp('Active Recipient');
        $archived = $this->emp('Archived Recipient', ['archived_at' => now()]);

        Achievement::create(['tenant_id' => $this->tenant->id, 'employee_id' => $active->id, 'title' => 'STILL-HERE-AWARD', 'points' => 10, 'date' => now()]);
        Achievement::create(['tenant_id' => $this->tenant->id, 'employee_id' => $archived->id, 'title' => 'GONE-AWARD', 'points' => 10, 'date' => now()]);

        $response = $this->actingAs($viewer)->withSession(['current_tenant' => $this->tenant->id])->get('/app/dash');

        $response->assertOk();
        $response->assertSee('STILL-HERE-AWARD');      // current staff still recognised
        $response->assertDontSee('GONE-AWARD');        // archived recipient dropped from the card
    }

    /** canSeeAll() must be driven by LIVE direct reports — an archived report is not a report. */
    public function test_archived_only_direct_report_does_not_unlock_manager_screens(): void
    {
        $viewer = User::create(['name' => 'Lead', 'email' => 'lead@example.com', 'password' => Hash::make('password')]);
        $viewer->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $lead = $this->emp('Lead', ['user_id' => $viewer->id]);

        // Positive control: with a LIVE direct report the lead is a de-facto manager → reaches the gated screen.
        $liveReport = $this->emp('Live Report', ['reports_to_id' => $lead->id]);
        $this->actingAs($viewer)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/team-board')->assertOk();

        // Archive that sole report → the lead manages nobody live → gated screen 403s.
        $liveReport->update(['archived_at' => now()]);
        $this->actingAs($viewer)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/team-board')->assertStatus(403);
    }

    // ── Stuck-request detection ───────────────────────────────────

    public function test_stuck_requests_flags_an_active_requester_behind_an_archived_manager(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        $archivedManager = $this->emp('Archived Manager', ['archived_at' => now()]);
        $report = $this->emp('Active Report', ['reports_to_id' => $archivedManager->id]);
        LeaveRequest::create(['tenant_id' => $this->tenant->id, 'employee_id' => $report->id, 'date_from' => '2026-07-06', 'date_to' => '2026-07-07', 'days' => 2, 'status' => 'submitted']);

        $stuck = app(StuckRequests::class)->forCurrentTenant();

        // The request routes only to an archived (dead) verifier → surfaced for HR to re-route.
        $this->assertSame(['Leave'], $stuck->pluck('type')->all());
        $this->assertSame(['Active Report'], $stuck->pluck('employee')->all());
    }
}
