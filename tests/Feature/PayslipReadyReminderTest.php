<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PayslipPublished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * payroll:payslip-ready emails staff on the 5th about last month's published payslip only.
 */
class PayslipReadyReminderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $staffUser;

    private Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->staffUser = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $this->staffUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->staff = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->staffUser->id,
            'name' => 'Worker', 'status' => 'active', 'workload' => 'green']);
    }

    private function slipIn(string $period, bool $published, ?Employee $employee = null): Payslip
    {
        $run = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => $period, 'kind' => 'monthly',
            'status' => 'finalized', 'published_at' => $published ? now() : null]);

        return Payslip::forceCreate(['tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id,
            'employee_id' => ($employee ?? $this->staff)->id, 'net_pay' => 2500]);
    }

    public function test_emails_last_months_published_payslip(): void
    {
        $this->slipIn('2026-09', true);
        $this->slipIn('2026-08', true); // older month, not repeated

        $this->travelTo('2026-10-05 08:00:00');
        $this->artisan('payroll:payslip-ready')->assertExitCode(0);

        Notification::assertSentToTimes($this->staffUser, PayslipPublished::class, 1);
    }

    public function test_skips_unpublished_runs_and_staff_without_a_login(): void
    {
        $this->slipIn('2026-09', false);
        $noLogin = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Nobody', 'status' => 'active', 'workload' => 'green']);
        $this->slipIn('2026-09', true, $noLogin);

        $this->travelTo('2026-10-05 08:00:00');
        $this->artisan('payroll:payslip-ready')->assertExitCode(0);

        Notification::assertNothingSent();
    }
}
