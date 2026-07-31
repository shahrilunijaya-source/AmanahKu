<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function management(): User
    {
        $mgmt = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $mgmt->tenants()->attach($this->tenant->id, ['role' => 'management']);

        return $mgmt;
    }

    public function test_approving_leave_notifies_the_employee(): void
    {
        // Two-step gate: a request reaches management already verified, then is approved.
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 18]);
        $req = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'date_from' => '2026-07-01', 'date_to' => '2026-07-02', 'days' => 2, 'status' => 'verified',
        ]);

        $this->actingAs($this->management())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/leave/{$req->id}/approve")->assertRedirect();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->user->id, 'tenant_id' => $this->tenant->id, 'title' => 'Leave approved',
        ]);
    }

    public function test_user_can_mark_notifications_read(): void
    {
        AppNotification::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'title' => 'Test', 'body' => 'x',
        ]);

        $this->actingInTenant()->post('/app/notifications/read')->assertRedirect();

        $this->assertNotNull(AppNotification::where('user_id', $this->user->id)->first()->read_at);
    }
}
