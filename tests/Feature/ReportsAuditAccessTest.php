<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
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

    public function test_manager_sidebar_shows_the_reports_and_audit_group(): void
    {
        $this->actAs($this->userWithRole('manager'), 'manager');

        $this->get('/app/dash')->assertOk()->assertSee('Reports & Audit');
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
}
