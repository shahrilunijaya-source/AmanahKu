<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Requests that previously created a record but pinged nobody now notify the right party
 * on submit (AK-PROC-02): money requests notify the requester's immediate superior; a
 * shift swap notifies the named counterpart to accept.
 */
class SubmitNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $managerUser;

    private Employee $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->managerUser = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->managerUser->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        $this->manager = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->managerUser->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    /** A subordinate whose reports_to_id points at the manager. */
    private function subordinate(): User
    {
        $user = User::create(['name' => 'Sub', 'email' => 'sub@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'reports_to_id' => $this->manager->id,
            'name' => 'Sub', 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    public function test_loan_submit_notifies_the_immediate_superior(): void
    {
        $sub = $this->subordinate();

        $this->actingAs($sub)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/loans', ['type' => 'loan', 'amount' => 500, 'reason' => 'Emergency', 'installments' => 5])
            ->assertRedirect();

        $this->assertDatabaseHas('app_notifications', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->managerUser->id,
            'title' => 'Loan request awaiting approval',
        ]);
    }

    public function test_shift_swap_notifies_the_named_counterpart(): void
    {
        $requesterUser = User::create(['name' => 'Req', 'email' => 'req@example.com', 'password' => Hash::make('password')]);
        $requesterUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $requester = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $requesterUser->id,
            'name' => 'Req', 'status' => 'active', 'workload' => 'green',
        ]);
        $counterpartUser = User::create(['name' => 'Mate', 'email' => 'mate@example.com', 'password' => Hash::make('password')]);
        $counterpartUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $counterpart = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $counterpartUser->id,
            'name' => 'Mate', 'status' => 'active', 'workload' => 'green',
        ]);
        $shift = Shift::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $requester->id,
            'date' => now()->addDay()->toDateString(), 'start_time' => '09:00', 'end_time' => '18:00',
            'location' => 'HQ', 'status' => 'scheduled',
        ]);

        $this->actingAs($requesterUser)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/shiftswap', ['shift_id' => $shift->id, 'counterpart_employee_id' => $counterpart->id])
            ->assertRedirect();

        $this->assertDatabaseHas('app_notifications', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $counterpartUser->id,
            'title' => 'Shift swap needs your response',
        ]);
    }
}
