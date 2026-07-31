<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AK-DB-03 — two live employees in a tenant must not share an email / staff id. Enforced
 * in the app layer (MySQL has no partial unique index) among ACTIVE rows only, so an email
 * frees up once its holder is archived.
 */
class EmployeeUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
    }

    private function existing(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => 'Existing', 'email' => 'taken@example.com',
            'status' => 'active', 'workload' => 'green',
        ], $overrides));
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_store_rejects_a_duplicate_active_email(): void
    {
        $this->existing();

        $this->actingHr()->post('/app/employees', [
            'name' => 'Newcomer', 'email' => 'taken@example.com', 'status' => 'active',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, Employee::where('email', 'taken@example.com')->count());
    }

    public function test_store_rejects_a_duplicate_staff_id(): void
    {
        $this->existing(['email' => null, 'staff_id' => 'EMP-001']);

        $this->actingHr()->post('/app/employees', [
            'name' => 'Newcomer', 'staff_id' => 'EMP-001', 'status' => 'active',
        ])->assertSessionHasErrors('staff_id');
    }

    public function test_update_keeps_the_employees_own_email(): void
    {
        $employee = $this->existing();

        $this->actingHr()->post("/app/employees/{$employee->id}", [
            'name' => 'Existing Renamed', 'email' => 'taken@example.com', 'status' => 'active',
        ])->assertSessionDoesntHaveErrors('email')->assertRedirect();

        $this->assertSame('Existing Renamed', $employee->fresh()->name);
    }

    public function test_an_archived_holders_email_can_be_reused(): void
    {
        $this->existing(['archived_at' => now()]);

        $this->actingHr()->post('/app/employees', [
            'name' => 'Newcomer', 'email' => 'taken@example.com', 'status' => 'active',
        ])->assertSessionDoesntHaveErrors('email')->assertRedirect();

        // One archived + one active now hold the email — the active-only rule allows it.
        $this->assertSame(2, Employee::withoutGlobalScopes()->where('email', 'taken@example.com')->count());
    }
}
