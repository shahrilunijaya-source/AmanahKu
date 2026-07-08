<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OffboardingCase;
use App\Models\Resignation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `staff:archive-departed` auto-archives staff whose acknowledged resignation last working
 * day has passed — running the full detach cascade — but never before their last day, and
 * never for withdrawn resignations.
 */
class ArchiveDepartedStaffTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function emp(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'active', 'workload' => 'green',
        ], $attrs));
    }

    private function resignation(Employee $e, string $lastDay, string $status = 'acknowledged'): Resignation
    {
        return Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'submitted_at' => now()->subMonth(), 'last_working_date' => $lastDay,
            'notice_days' => 30, 'reason' => 'New opportunity', 'status' => $status,
        ]);
    }

    public function test_it_archives_staff_whose_last_working_day_has_passed_and_runs_the_cascade(): void
    {
        $manager = $this->emp('Manager');
        $leaver = $this->emp('Leaver', ['reports_to_id' => $manager->id]);
        $report = $this->emp('Report', ['reports_to_id' => $leaver->id]);
        $pendingLeave = LeaveRequest::create(['tenant_id' => $this->tenant->id, 'employee_id' => $leaver->id, 'date_from' => '2026-07-06', 'date_to' => '2026-07-07', 'days' => 2, 'status' => 'submitted']);
        $r = $this->resignation($leaver, now()->subDay()->toDateString());

        $this->artisan('staff:archive-departed')->assertExitCode(0);

        $this->assertNotNull($leaver->fresh()->archived_at);              // archived
        $this->assertSame('completed', $r->fresh()->status);             // resignation closed
        $this->assertSame($manager->id, $report->fresh()->reports_to_id); // cascade: report repointed up
        $this->assertSame('rejected', $pendingLeave->fresh()->status);    // cascade: pending request closed
    }

    public function test_it_does_not_archive_before_the_last_working_day(): void
    {
        $servingToday = $this->emp('Serving Notice Today');
        $servingFuture = $this->emp('Serving Notice Future');
        $this->resignation($servingToday, now()->toDateString());          // last day IS today — still working
        $this->resignation($servingFuture, now()->addDays(5)->toDateString());

        $this->artisan('staff:archive-departed')->assertExitCode(0);

        $this->assertNull($servingToday->fresh()->archived_at);
        $this->assertNull($servingFuture->fresh()->archived_at);
    }

    public function test_it_ignores_withdrawn_resignations(): void
    {
        $stayed = $this->emp('Stayed');
        $r = $this->resignation($stayed, now()->subWeek()->toDateString(), 'withdrawn');

        $this->artisan('staff:archive-departed')->assertExitCode(0);

        $this->assertNull($stayed->fresh()->archived_at);
        $this->assertSame('withdrawn', $r->fresh()->status); // left untouched
    }

    public function test_it_is_idempotent_for_an_already_archived_leaver(): void
    {
        $gone = $this->emp('Already Gone', ['archived_at' => now()]);
        $r = $this->resignation($gone, now()->subWeek()->toDateString());

        $this->artisan('staff:archive-departed')->assertExitCode(0);

        // Already archived → still closed, no double-processing, no error.
        $this->assertSame('completed', $r->fresh()->status);
    }

    private function caseFor(Employee $e, string $lastDay, string $reason, string $status = 'in_progress'): OffboardingCase
    {
        return OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'last_day' => $lastDay, 'reason' => $reason, 'status' => $status,
        ]);
    }

    public function test_it_archives_a_termination_case_with_no_resignation(): void
    {
        $leaver = $this->emp('Terminated');
        $case = $this->caseFor($leaver, now()->subDay()->toDateString(), 'termination');
        $case->clearanceItems()->create(['department' => 'IT', 'title' => 'Revoke access', 'done' => true, 'sort' => 0]);

        $this->artisan('staff:archive-departed')->assertExitCode(0);

        $this->assertNotNull($leaver->fresh()->archived_at);
        $case->refresh();
        $this->assertSame('completed', $case->status);
        $this->assertNotNull($case->completed_at);
    }

    public function test_it_marks_the_linked_resignation_completed(): void
    {
        $leaver = $this->emp('Resigned');
        $r = $this->resignation($leaver, now()->subDay()->toDateString());
        $case = $this->caseFor($leaver, now()->subDay()->toDateString(), 'resignation');
        $case->update(['resignation_id' => $r->id]);

        $this->artisan('staff:archive-departed')->assertExitCode(0);

        $this->assertSame('completed', $r->fresh()->status);
        $this->assertSame('completed', $case->fresh()->status);
    }

    public function test_it_flags_outstanding_clearance_on_archival(): void
    {
        $hr = User::create(['name' => 'HR', 'email' => 'hr@acme.test', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $leaver = $this->emp('Half Cleared');
        $case = $this->caseFor($leaver, now()->subDay()->toDateString(), 'termination');
        $case->clearanceItems()->create(['department' => 'IT', 'title' => 'Collect laptop', 'done' => false, 'sort' => 0]);
        $case->clearanceItems()->create(['department' => 'HR', 'title' => 'Final docs', 'done' => true, 'sort' => 1]);

        $this->artisan('staff:archive-departed')->assertExitCode(0);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $hr->id,
            'title' => 'Offboarding: clearance outstanding',
        ]);
    }

    public function test_it_self_heals_a_legacy_acknowledged_resignation_with_no_case(): void
    {
        $leaver = $this->emp('Legacy Leaver');
        $r = $this->resignation($leaver, now()->subDay()->toDateString()); // acknowledged, no case

        $this->artisan('staff:archive-departed')->assertExitCode(0);

        $this->assertNotNull($leaver->fresh()->archived_at);
        $this->assertSame('completed', $r->fresh()->status);
        $this->assertNotNull($r->fresh()->offboardingCase); // a case was opened for it
    }
}
