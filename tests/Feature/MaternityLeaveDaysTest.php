<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Maternity leave is 98 consecutive days, so a calendar-day leave type charges the
 * weekends inside the range, while an ordinary type over the same dates does not.
 */
class MaternityLeaveDaysTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 09:00:00');
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'tot_saturday' => true]);
        app(CurrentTenant::class)->set($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function applyFor(LeaveType $type): LeaveRequest
    {
        $user = User::create(['name' => 'Staff', 'email' => "staff{$type->id}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $employee = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => 'Staff', 'status' => 'active', 'workload' => 'green']);
        LeaveBalance::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'balance' => 98]);

        // Fri 11 Sep – Mon 14 Sep 2026, an ordinary weekend in between.
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id])->post('/app/leave', [
            'reason' => 'Leave.',
            'leave_type_id' => $type->id,
            'date_from' => '2026-09-11',
            'date_to' => '2026-09-14',
        ])->assertSessionHasNoErrors();

        return LeaveRequest::where('employee_id', $employee->id)->sole();
    }

    public function test_maternity_charges_the_weekend(): void
    {
        $maternity = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Maternity', 'entitlement' => 98, 'counts_calendar_days' => true]);

        $this->assertEqualsWithDelta(4.0, (float) $this->applyFor($maternity)->days, 0.001);
    }

    public function test_ordinary_type_skips_the_weekend(): void
    {
        $annual = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 16]);

        $this->assertEqualsWithDelta(2.0, (float) $this->applyFor($annual)->days, 0.001);
    }
}
