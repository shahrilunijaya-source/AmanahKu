<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithEmployees(string $slug, int $count): Tenant
    {
        $tenant = Tenant::create(['slug' => $slug, 'name' => ucfirst($slug), 'initials' => strtoupper(substr($slug, 0, 2))]);
        for ($i = 1; $i <= $count; $i++) {
            Employee::create(['tenant_id' => $tenant->id, 'name' => "$slug emp $i", 'status' => 'active', 'workload' => 'green']);
        }

        return $tenant;
    }

    public function test_guests_are_redirected_from_the_app(): void
    {
        $this->get('/app/dash')->assertRedirect('/login');
    }

    public function test_a_user_can_log_in_and_is_sent_to_tenant_select(): void
    {
        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);

        $response = $this->post('/login', ['email' => 'demo@example.com', 'password' => 'password']);

        $response->assertRedirect('/tenant');
        $this->assertAuthenticatedAs($user);
    }

    public function test_bad_credentials_are_rejected(): void
    {
        User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);

        $this->post('/login', ['email' => 'demo@example.com', 'password' => 'wrong']);

        $this->assertGuest();
    }

    public function test_global_scope_isolates_employees_by_active_tenant(): void
    {
        $a = $this->makeTenantWithEmployees('alpha', 3);
        $this->makeTenantWithEmployees('beta', 5);

        // No active tenant → no scope → all rows visible.
        $this->assertSame(8, Employee::count());

        // Activate tenant A → only its rows are visible.
        app(CurrentTenant::class)->set($a);
        $this->assertSame(3, Employee::count());
        $this->assertTrue(Employee::get()->every(fn ($e) => $e->tenant_id === $a->id));
    }

    public function test_new_records_auto_fill_the_active_tenant(): void
    {
        $a = $this->makeTenantWithEmployees('alpha', 0);
        app(CurrentTenant::class)->set($a);

        $e = Employee::create(['name' => 'Auto', 'status' => 'active', 'workload' => 'green']);

        $this->assertSame($a->id, $e->tenant_id);
    }
}
