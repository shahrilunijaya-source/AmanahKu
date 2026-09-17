<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Adding staff writes a Hired row; the generic edit routes employment fields through the service. */
class EmployeeProgressionOnStoreTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function login(string $role, array $attrs = []): Employee
    {
        $user = User::create(['name' => ucfirst($role), 'email' => $role.'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $employee = Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ], $attrs));
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    private function emp(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'probation', 'workload' => 'green',
            'joined_at' => '2026-01-05',
        ], $attrs));
    }

    public function test_store_writes_a_hired_row(): void
    {
        $this->login('hr');
        $this->post('/app/employees', ['name' => 'New Person', 'joined_at' => '2026-02-02', 'status' => 'probation'])->assertRedirect();
        $e = Employee::where('name', 'New Person')->firstOrFail();
        $this->assertSame('hired', $e->progressions()->first()->type);
        $this->assertSame('2026-02-02', $e->progressions()->first()->effective_on->toDateString());
    }

    public function test_generic_update_with_a_salary_change_writes_an_updated_row(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah', ['salary' => 5000, 'status' => 'active']);
        $this->post("/app/employees/{$e->id}", ['name' => 'Adibah B', 'status' => 'active', 'salary' => 5500])->assertRedirect();
        $e->refresh();
        $this->assertSame('Adibah B', $e->name);
        $this->assertSame(5500.0, (float) $e->salary);
        $this->assertSame('updated', $e->progressions()->first()->type);
        $this->assertContains('basic_salary', $e->progressions()->first()->changed_fields);
    }

    public function test_generic_update_of_name_only_writes_no_row(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah', ['status' => 'active']);
        $this->post("/app/employees/{$e->id}", ['name' => 'Adibah B', 'status' => 'active'])->assertRedirect();
        $this->assertSame(0, EmployeeProgression::count());
    }
}
