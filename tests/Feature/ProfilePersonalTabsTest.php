<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfilePersonalTabsTest extends TestCase
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
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green', 'joined_at' => '2025-01-06',
        ], $attrs));
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    private function emp(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05',
        ], $attrs));
    }

    public function test_hr_sees_personal_and_family_tabs_with_identity(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah', ['nric' => '900101-10-1234', 'passport_no' => 'A9']);
        $this->get("/app/profile?emp={$e->id}")->assertOk()
            ->assertSee('data-tab="personal"', false)->assertSee('data-tab="family"', false)
            ->assertSee('900101-10-1234')->assertSee('name="passport_no"', false);
    }

    public function test_employee_sees_own_tabs_and_can_edit_but_no_identity_inputs(): void
    {
        $this->login('employee', ['nric' => '900101-10-1234']);
        $html = $this->get('/app/profile')->assertOk()
            ->assertSee('data-tab="personal"', false)->assertSee('data-tab="family"', false)
            ->assertSee('name="city"', false)->assertDontSee('name="passport_no"', false)
            ->assertSee('900101-10-1234')->getContent();
        // The personal form (up to its Save button) carries no identity inputs; the family form's member NRIC is unrelated.
        $personalForm = substr($html, strpos($html, '/personal"'), strpos($html, 'Save changes') - strpos($html, '/personal"'));
        $this->assertStringNotContainsString('name="nric"', $personalForm);
    }

    public function test_manager_sees_neither_tab(): void
    {
        $m = $this->login('manager');
        $e = $this->emp('Adibah', ['reports_to_id' => $m->id]);
        $this->get("/app/profile?emp={$e->id}")->assertOk()
            ->assertDontSee('data-tab="personal"', false)->assertDontSee('data-tab="family"', false);
    }

    public function test_family_rows_render_in_their_sections(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $e->familyMembers()->create(['tenant_id' => $this->tenant->id, 'relation' => 'child', 'name' => 'Little One', 'education' => 'Primary']);
        $this->get("/app/profile?emp={$e->id}&tab=family")->assertOk()->assertSee('Little One')->assertSee('Primary');
    }
}
