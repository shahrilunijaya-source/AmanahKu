<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Coverage for the leave:accrue and leave:carry-forward automation.
 *
 * Builds a minimal tenant with one active employee and one accruing leave
 * type directly (no full DatabaseSeeder) so the accrual maths is isolated.
 * Time is pinned with Carbon::setTestNow and cleared in tearDown.
 */
class LeaveAccrualTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'initials' => 'AC', 'color' => '#123456',
        ]);

        // Seeders write cross-tenant by bypassing the global scope; tests do the
        // same by setting the active tenant, then clearing it before running the
        // command (which iterates tenants itself).
        app(CurrentTenant::class)->set($this->tenant);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Active Ali',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $this->type = LeaveType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Annual',
            'entitlement' => 18,
            'monthly_accrual_days' => 1.5,
            'max_balance' => 18,
            'max_carry_forward' => 6,
        ]);

        app(CurrentTenant::class)->set(null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);

        parent::tearDown();
    }

    private function balance(): LeaveBalance
    {
        return LeaveBalance::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->where('leave_type_id', $this->type->id)
            ->firstOrFail();
    }

    public function test_accrual_adds_the_monthly_grant(): void
    {
        // Arrange
        Carbon::setTestNow(Carbon::create(2026, 3, 1, 1, 0));

        // Act
        $this->artisan('leave:accrue')->assertSuccessful();

        // Assert
        $balance = $this->balance();
        $this->assertSame(1.5, (float) $balance->balance);
        $this->assertTrue($balance->last_accrued_on->isSameDay(Carbon::create(2026, 3, 1)));
    }

    public function test_accrual_is_idempotent_within_a_calendar_month(): void
    {
        // Arrange
        Carbon::setTestNow(Carbon::create(2026, 3, 1, 1, 0));
        $this->artisan('leave:accrue')->assertSuccessful();

        // Act — re-run later the same month.
        Carbon::setTestNow(Carbon::create(2026, 3, 18, 9, 30));
        $this->artisan('leave:accrue')->assertSuccessful();

        // Assert — still only one grant.
        $this->assertSame(1.5, (float) $this->balance()->balance);
    }

    public function test_accrual_across_two_months_adds_twice(): void
    {
        // Arrange + Act
        Carbon::setTestNow(Carbon::create(2026, 3, 1, 1, 0));
        $this->artisan('leave:accrue')->assertSuccessful();

        Carbon::setTestNow(Carbon::create(2026, 4, 1, 1, 0));
        $this->artisan('leave:accrue')->assertSuccessful();

        // Assert
        $this->assertSame(3.0, (float) $this->balance()->balance);
    }

    public function test_accrual_respects_the_max_balance_cap(): void
    {
        // Arrange — start the balance just below the 18-day cap.
        app(CurrentTenant::class)->set($this->tenant);
        LeaveBalance::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->type->id,
            'balance' => 17.5,
        ]);
        app(CurrentTenant::class)->set(null);

        Carbon::setTestNow(Carbon::create(2026, 3, 1, 1, 0));

        // Act — a 1.5 grant would overshoot to 19; cap holds it at 18.
        $this->artisan('leave:accrue')->assertSuccessful();

        // Assert
        $this->assertSame(18.0, (float) $this->balance()->balance);
    }

    public function test_inactive_employees_do_not_accrue(): void
    {
        // Arrange
        app(CurrentTenant::class)->set($this->tenant);
        $resigned = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Gone Goh',
            'status' => 'resigned',
            'workload' => 'green',
        ]);
        app(CurrentTenant::class)->set(null);

        Carbon::setTestNow(Carbon::create(2026, 3, 1, 1, 0));

        // Act
        $this->artisan('leave:accrue')->assertSuccessful();

        // Assert — no balance row created for the resigned employee.
        $this->assertSame(0, LeaveBalance::withoutGlobalScopes()
            ->where('employee_id', $resigned->id)->count());
    }

    public function test_carry_forward_caps_the_carried_amount_and_expires_the_rest(): void
    {
        // Arrange — 14 days banked, carry cap is 6.
        app(CurrentTenant::class)->set($this->tenant);
        LeaveBalance::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->type->id,
            'balance' => 14,
            'last_accrued_on' => Carbon::create(2026, 12, 1),
        ]);
        app(CurrentTenant::class)->set(null);

        Carbon::setTestNow(Carbon::create(2027, 1, 1, 2, 0));

        // Act
        $this->artisan('leave:carry-forward')->assertSuccessful();

        // Assert — carried capped at 6, accrual tracking reset.
        $balance = $this->balance();
        $this->assertSame(6.0, (float) $balance->balance);
        $this->assertNull($balance->last_accrued_on);
    }

    public function test_carry_forward_keeps_balance_below_the_cap_intact(): void
    {
        // Arrange — only 4 days banked, under the 6-day carry cap.
        app(CurrentTenant::class)->set($this->tenant);
        LeaveBalance::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->type->id,
            'balance' => 4,
        ]);
        app(CurrentTenant::class)->set(null);

        Carbon::setTestNow(Carbon::create(2027, 1, 1, 2, 0));

        // Act
        $this->artisan('leave:carry-forward')->assertSuccessful();

        // Assert — all 4 carried.
        $this->assertSame(4.0, (float) $this->balance()->balance);
    }

    public function test_carry_forward_expires_everything_when_no_carry_policy(): void
    {
        // Arrange — a type that accrues but has no carry-forward allowance.
        app(CurrentTenant::class)->set($this->tenant);
        $noCarry = LeaveType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Replacement',
            'entitlement' => 0,
            'monthly_accrual_days' => 1,
            'max_balance' => 4,
            'max_carry_forward' => null,
        ]);
        LeaveBalance::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $noCarry->id,
            'balance' => 3,
        ]);
        app(CurrentTenant::class)->set(null);

        Carbon::setTestNow(Carbon::create(2027, 1, 1, 2, 0));

        // Act
        $this->artisan('leave:carry-forward')->assertSuccessful();

        // Assert — nothing carries.
        $balance = LeaveBalance::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->where('leave_type_id', $noCarry->id)
            ->firstOrFail();
        $this->assertSame(0.0, (float) $balance->balance);
    }
}
