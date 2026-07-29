<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Attendance Reports, Timesheet Reports and Audit Logs were moved out of the
 * Administration group into a dedicated "Reports & Audit" oversight section that
 * is open to managers, management and HR — but hidden and blocked for plain staff.
 */
class ReportsAuditAccessTest extends TestCase
{
    use RefreshDatabase;

    private const SCREENS = ['attendance-report', 'timesheet-reports', 'audit'];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->tenant = Tenant::where('slug', 'unijaya')->firstOrFail();
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => 'Test '.$role,
            'email' => $role.'@reports-audit.test',
            'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Test '.$role, 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    private function actAs(User $user, string $persona): void
    {
        $this->actingAs($user)->withSession([
            'current_tenant' => $this->tenant->id,
            'persona' => $persona,
        ]);
    }

    public function test_manager_reaches_every_reports_and_audit_screen(): void
    {
        $this->actAs($this->userWithRole('manager'), 'manager');

        foreach (self::SCREENS as $screen) {
            $this->get("/app/{$screen}")->assertOk();
        }
    }

    public function test_manager_sidebar_shows_the_oversight_group(): void
    {
        $this->actAs($this->userWithRole('manager'), 'manager');

        // The group sits under the Insights section and is labelled "Oversight";
        // it used to be a top-level section of its own called "Reports & Audit".
        $this->get('/app/dash')->assertOk()->assertSee('Oversight');
    }

    public function test_plain_employee_is_blocked_from_every_reports_and_audit_screen(): void
    {
        $this->actAs($this->userWithRole('employee'), 'employee');

        foreach (self::SCREENS as $screen) {
            $this->get("/app/{$screen}")->assertForbidden();
        }
    }

    public function test_plain_employee_sidebar_hides_the_reports_and_audit_group(): void
    {
        $this->actAs($this->userWithRole('employee'), 'employee');

        $this->get('/app/dash')->assertOk()->assertDontSee('Reports & Audit');
    }

    /**
     * A clock remark is free text a staff member writes about their own day, so who reads it
     * matters. It must reach an overseer through the report drill-down, and must never reach
     * a peer. The screen gate above already blocks the peer; this pins both ends to the
     * remark text itself, which is the thing that would actually leak.
     */
    public function test_clock_remark_is_visible_to_an_overseer_and_hidden_from_a_peer(): void
    {
        // The record is created outside a request, so the tenant the BelongsToTenant trait
        // stamps and scopes by has to be set by hand first.
        app(CurrentTenant::class)->set($this->tenant);

        $subject = Employee::where('tenant_id', $this->tenant->id)->active()->firstOrFail();
        $remark = 'Handover at the Kuantan depot, back after lunch.';

        // Yesterday, not today: on SQLite the `date` cast writes "Y-m-d H:i:s", so a row dated
        // today string-compares past the report window's end bound and vanishes. MySQL's DATE
        // column truncates and has no such edge, so this only bites the test DB.
        $subject->attendanceRecords()->create([
            'tenant_id' => $this->tenant->id,
            'date' => now()->subDay()->toDateString(),
            'clock_in' => '09:00:00',
            'status' => 'on_time',
            'location' => 'PJ HQ',
            'clock_in_justification' => $remark,
        ]);

        $this->assertDatabaseHas('attendance_records', ['employee_id' => $subject->id, 'clock_in_justification' => $remark]);

        $this->actAs($this->userWithRole('manager'), 'manager');
        $this->get('/app/attendance-report?emp='.$subject->id)->assertOk()->assertSee($subject->name)->assertSee($remark, false);

        $this->actAs($this->userWithRole('employee'), 'employee');
        $this->get('/app/attendance-report?emp='.$subject->id)->assertForbidden();
        // The peer's own attendance screen is scoped to their own records, so the remark
        // is absent there too — not merely hidden behind a role check.
        $this->get('/app/attendance')->assertOk()->assertDontSee($remark, false);
    }
}
