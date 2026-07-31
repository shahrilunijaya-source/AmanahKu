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
 * The dashboard scope switcher replaced the old four-persona preview switcher:
 * two scopes now, `me` and `company`. A plain employee only ever gets `me` —
 * there is nothing routed to them to verify or approve, so `company` is not
 * even offered. manager/management/hr all get both. Forcing `?scope=company`
 * as an employee is rejected by falling back to the role's default scope
 * rather than aborting (AK-AUTHZ-02 guard style).
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

    private function actAs(User $user): void
    {
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);
    }

    public function test_employee_only_gets_the_me_scope(): void
    {
        $this->actAs($this->userWithRole('employee'));

        $response = $this->get('/app/dash');
        $response->assertOk();

        $this->assertSame('me', $response->viewData('scope'));
        $this->assertSame(['me'], collect($response->viewData('scopes'))->pluck('id')->all());
    }

    public function test_employee_forcing_company_scope_falls_back_to_me(): void
    {
        $this->actAs($this->userWithRole('employee'));

        $response = $this->get('/app/dash?scope=company');
        $response->assertOk();

        $this->assertSame('me', $response->viewData('scope'));
    }

    public function test_manager_may_view_both_scopes(): void
    {
        $this->actAs($this->userWithRole('manager'));

        $this->assertSame('me', $this->get('/app/dash?scope=me')->viewData('scope'));
        $this->assertSame('company', $this->get('/app/dash?scope=company')->viewData('scope'));

        $response = $this->get('/app/dash');
        $this->assertSame(['me', 'company'], collect($response->viewData('scopes'))->pluck('id')->all());
    }

    public function test_management_and_hr_both_default_to_company(): void
    {
        $this->actAs($this->userWithRole('management'));
        $this->assertSame('company', $this->get('/app/dash')->viewData('scope'));

        $this->actAs($this->userWithRole('hr'));
        $this->assertSame('company', $this->get('/app/dash')->viewData('scope'));
    }

    public function test_invalid_scope_value_falls_back_to_the_default(): void
    {
        $this->actAs($this->userWithRole('manager'));

        $response = $this->get('/app/dash?scope=nonsense');
        $response->assertOk();

        $this->assertSame('company', $response->viewData('scope'));
    }
}
