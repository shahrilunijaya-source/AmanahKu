<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Helpdesk / IT tickets module.
 * Harness (setUp / actingInTenant / hrActor) copied from CoreWritePathsTest.
 */
class HelpdeskTest extends TestCase
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

    private function hrActor(): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $hr->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $hr;
    }

    // ── Raising tickets ───────────────────────────────────────────

    public function test_employee_raises_a_ticket(): void
    {
        $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'IT',
            'priority' => 'high',
            'subject' => 'VPN keeps dropping',
            'description' => 'My VPN disconnects every few minutes.',
        ])->assertRedirect();

        $this->assertDatabaseHas('tickets', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'subject' => 'VPN keeps dropping',
            'category' => 'IT',
            'priority' => 'high',
            'status' => 'open',
        ]);
    }

    public function test_raising_a_ticket_requires_a_subject(): void
    {
        $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'IT', 'priority' => 'low', 'subject' => '', 'description' => 'x',
        ])->assertSessionHasErrors('subject');
    }

    // ── Privileged updates ────────────────────────────────────────

    public function test_privileged_user_updates_status_assignee_and_resolution(): void
    {
        $hr = $this->hrActor();
        $assignee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'IT Tech', 'status' => 'active', 'workload' => 'green',
        ]);
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'IT', 'priority' => 'high', 'subject' => 'Broken laptop',
            'description' => 'Will not boot.', 'status' => 'open',
        ]);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/helpdesk/{$ticket->id}", [
                'status' => 'resolved',
                'assignee_employee_id' => $assignee->id,
                'resolution' => 'Replaced the SSD; boots fine.',
            ])->assertRedirect();

        $fresh = $ticket->fresh();
        $this->assertSame('resolved', $fresh->status);
        $this->assertSame($assignee->id, $fresh->assignee_employee_id);
        $this->assertSame('Replaced the SSD; boots fine.', $fresh->resolution);
    }

    public function test_plain_employee_cannot_update_a_ticket(): void
    {
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'IT', 'priority' => 'high', 'subject' => 'Broken laptop',
            'description' => 'Will not boot.', 'status' => 'open',
        ]);

        $this->actingInTenant()->post("/app/helpdesk/{$ticket->id}", [
            'status' => 'closed',
            'assignee_employee_id' => $this->employee->id,
            'resolution' => 'I fixed it myself.',
        ])->assertForbidden();

        $fresh = $ticket->fresh();
        $this->assertSame('open', $fresh->status);
        $this->assertNull($fresh->assignee_employee_id);
        $this->assertNull($fresh->resolution);
    }
}
