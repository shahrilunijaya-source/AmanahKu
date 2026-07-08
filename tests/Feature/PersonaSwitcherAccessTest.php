<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The dashboard persona switcher lets a role PREVIEW other dashboards, but only
 * downward/laterally: employee sees no switcher, manager may preview employee +
 * manager, management/HR may preview all four. Forcing a higher persona via the
 * query string is rejected — a manager can't peek at the HR dashboard (AK-AUTHZ-02).
 */
class PersonaSwitcherAccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->tenant = Tenant::where('slug', 'unijaya')->firstOrFail();
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => 'Test '.$role,
            'email' => $role.'@persona.test',
            'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Test '.$role, 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    private function actAs(User $user, string $persona): void
    {
        $this->actingAs($user)->withSession([
            'current_tenant' => $this->tenant->id,
            'persona' => $persona,
        ]);
    }

    public function test_manager_sees_only_employee_and_manager_tabs(): void
    {
        $this->actAs($this->userWithRole('manager'), 'manager');

        $this->get('/app/dash')->assertOk()
            ->assertSee('persona=employee')
            ->assertSee('persona=manager')
            ->assertDontSee('persona=management')
            ->assertDontSee('persona=hr');
    }

    public function test_management_sees_all_four_tabs(): void
    {
        $this->actAs($this->userWithRole('management'), 'management');

        $this->get('/app/dash')->assertOk()
            ->assertSee('persona=employee')
            ->assertSee('persona=manager')
            ->assertSee('persona=management')
            ->assertSee('persona=hr');
    }

    public function test_hr_sees_all_four_tabs(): void
    {
        $this->actAs($this->userWithRole('hr'), 'hr');

        $this->get('/app/dash')->assertOk()
            ->assertSee('persona=employee')
            ->assertSee('persona=manager')
            ->assertSee('persona=management')
            ->assertSee('persona=hr');
    }

    public function test_employee_sees_no_persona_switcher(): void
    {
        $this->actAs($this->userWithRole('employee'), 'employee');

        $this->get('/app/dash')->assertOk()
            ->assertDontSee('persona=manager')
            ->assertDontSee('persona=management')
            ->assertDontSee('persona=hr');
    }

    public function test_manager_cannot_force_a_higher_persona(): void
    {
        $this->actAs($this->userWithRole('manager'), 'manager');

        $this->get('/app/dash?persona=hr')->assertOk();

        // The illegal switch is ignored; the manager keeps their own persona.
        $this->assertSame('manager', session('persona'));
    }

    public function test_employee_cannot_force_a_persona(): void
    {
        $this->actAs($this->userWithRole('employee'), 'employee');

        $this->get('/app/dash?persona=management')->assertOk();

        $this->assertSame('employee', session('persona'));
    }
}
