<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * HR "Leave Setup" — the carry-forward opening-balance grid. Proves HR can seed the
 * per-type leave_balances rows, that non-privileged users are gated out, and that a
 * forged foreign id in the payload can never write a balance across tenants.
 */
class LeaveSetupTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $employee;

    private User $hr;

    private Employee $staff;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $this->employee = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $this->employee->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->staff = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->employee->id,
            'name' => 'Worker', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->annual = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 18]);
    }

    private function asHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_hr_sees_the_leave_setup_screen(): void
    {
        $this->asHr()->get('/app/leave-setup')->assertOk();
    }

    public function test_employee_cannot_see_the_leave_setup_screen(): void
    {
        $this->actingAs($this->employee)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/leave-setup')->assertForbidden();
    }

    public function test_hr_sets_an_opening_balance(): void
    {
        $this->asHr()->post('/app/leave-setup', [
            'balances' => [$this->staff->id => [$this->annual->id => 14]],
        ])->assertRedirect();

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $this->staff->id,
            'leave_type_id' => $this->annual->id,
            'balance' => 14.0,
        ]);
    }

    public function test_saving_overwrites_an_existing_balance_and_blanks_are_left_untouched(): void
    {
        $sick = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Sick', 'entitlement' => 14]);
        LeaveBalance::create(['employee_id' => $this->staff->id, 'leave_type_id' => $this->annual->id, 'balance' => 5]);
        LeaveBalance::create(['employee_id' => $this->staff->id, 'leave_type_id' => $sick->id, 'balance' => 8]);

        // Overwrite annual to 12; leave the sick cell blank — it must keep its 8.
        $this->asHr()->post('/app/leave-setup', [
            'balances' => [$this->staff->id => [$this->annual->id => 12, $sick->id => '']],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(12.0, (float) LeaveBalance::where('leave_type_id', $this->annual->id)->value('balance'), 0.001);
        $this->assertEqualsWithDelta(8.0, (float) LeaveBalance::where('leave_type_id', $sick->id)->value('balance'), 0.001);
    }

    public function test_employee_cannot_save_balances(): void
    {
        $this->actingAs($this->employee)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/leave-setup', [
                'balances' => [$this->staff->id => [$this->annual->id => 99]],
            ])->assertForbidden();

        $this->assertDatabaseCount('leave_balances', 0);
    }

    public function test_a_foreign_tenant_leave_type_id_is_ignored(): void
    {
        // A leave type that belongs to a DIFFERENT tenant.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreignType = LeaveType::create(['tenant_id' => $other->id, 'name' => 'Annual', 'entitlement' => 18]);

        $this->asHr()->post('/app/leave-setup', [
            'balances' => [$this->staff->id => [$foreignType->id => 50]],
        ])->assertRedirect();

        // The forged id was whitelisted out — no balance written for the foreign type.
        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $this->staff->id,
            'leave_type_id' => $foreignType->id,
        ]);
    }
}
