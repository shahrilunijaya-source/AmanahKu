<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CompanyCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MemberInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers cross-tenant company provisioning via the super-admin console.
 */
class SuperAdminCompanyTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $u = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('password')]);
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    private function ordinaryUser(): User
    {
        $u = User::create(['name' => 'Joe', 'email' => 'joe@example.com', 'password' => Hash::make('password')]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $u->tenants()->attach($tenant->id, ['role' => 'hr']);

        return $u;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Beta Industries',
            'company_category_id' => CompanyCategory::where('level', 1)->value('id'),
            'plan' => 'Business',
            'color' => '#1f8a65',
            'branch_name' => 'Head Office',
            'branch_state' => 'Selangor',
            'department_name' => 'Human Resources',
            'admin_name' => 'Siti Aminah',
            'admin_email' => 'siti@beta.com',
        ], $overrides);
    }

    public function test_super_admin_can_view_companies_index(): void
    {
        $response = $this->actingAs($this->superAdmin())->get('/admin/companies');

        $response->assertOk()->assertSee('Companies');
    }

    public function test_ordinary_user_is_forbidden_from_the_console(): void
    {
        $user = $this->ordinaryUser();
        $this->actingAs($user)->get('/admin/companies')->assertForbidden();
        $this->actingAs($user)->get('/admin/companies/new')->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/companies')->assertRedirect('/login');
    }

    public function test_super_admin_provisions_company_with_first_admin_atomically(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->post('/admin/companies', $this->validPayload());

        $response->assertRedirect(route('superadmin.companies.index'));

        $tenant = Tenant::where('name', 'Beta Industries')->firstOrFail();
        $this->assertSame('beta-industries', $tenant->slug);
        $this->assertSame('Business', $tenant->plan);

        // First branch + department seeded under the new tenant.
        $this->assertDatabaseHas('branches', ['tenant_id' => $tenant->id, 'name' => 'Head Office']);
        $this->assertDatabaseHas('departments', ['tenant_id' => $tenant->id, 'name' => 'Human Resources']);

        // First HR admin user created, attached as 'hr', forced to rotate password.
        $admin = User::where('email', 'siti@beta.com')->firstOrFail();
        $this->assertTrue($admin->password_change_required);
        $this->assertFalse($admin->isSuperAdmin());
        $this->assertSame('hr', $admin->roleIn($tenant));

        // Matching employee record exists in the tenant.
        $this->assertSame(1, Employee::where('tenant_id', $tenant->id)->where('user_id', $admin->id)->count());
    }

    public function test_provisioning_emails_the_first_admin_their_credentials(): void
    {
        Notification::fake();

        $this->actingAs($this->superAdmin())->post('/admin/companies', $this->validPayload());

        $admin = User::where('email', 'siti@beta.com')->firstOrFail();
        Notification::assertSentTo($admin, MemberInvited::class);
    }

    public function test_super_admin_can_assign_an_existing_user_to_a_company(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $existing = User::create(['name' => 'Mei Ling', 'email' => 'mei@example.com', 'password' => Hash::make('password')]);

        $response = $this->actingAs($this->superAdmin())
            ->post("/admin/companies/{$tenant->slug}/members", ['email' => 'mei@example.com', 'role' => 'manager']);

        $response->assertRedirect();
        $this->assertSame('manager', $existing->fresh()->roleIn($tenant));
        $this->assertSame(1, Employee::where('tenant_id', $tenant->id)->where('user_id', $existing->id)->count());
    }

    public function test_assigning_a_user_already_in_the_company_is_rejected(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $existing = User::create(['name' => 'Mei Ling', 'email' => 'mei@example.com', 'password' => Hash::make('password')]);
        $existing->tenants()->attach($tenant->id, ['role' => 'employee']);

        $this->actingAs($this->superAdmin())
            ->post("/admin/companies/{$tenant->slug}/members", ['email' => 'mei@example.com', 'role' => 'manager'])
            ->assertSessionHasErrors('email');
    }

    public function test_duplicate_admin_email_is_rejected(): void
    {
        User::create(['name' => 'Taken', 'email' => 'siti@beta.com', 'password' => Hash::make('password')]);

        $response = $this->actingAs($this->superAdmin())
            ->post('/admin/companies', $this->validPayload());

        $response->assertSessionHasErrors('admin_email');
        $this->assertSame(0, Tenant::where('name', 'Beta Industries')->count());
    }

    public function test_slug_collision_gets_a_unique_suffix(): void
    {
        Tenant::create(['slug' => 'beta-industries', 'name' => 'Beta Industries', 'initials' => 'BI']);

        $this->actingAs($this->superAdmin())
            ->post('/admin/companies', $this->validPayload(['admin_email' => 'other@beta.com']));

        $this->assertDatabaseHas('tenants', ['slug' => 'beta-industries-2']);
    }

    public function test_provisioning_rolls_back_when_admin_email_invalid(): void
    {
        $before = Tenant::count();

        $this->actingAs($this->superAdmin())
            ->post('/admin/companies', $this->validPayload(['admin_email' => 'not-an-email']));

        // Validation fails before the transaction — no partial tenant created.
        $this->assertSame($before, Tenant::count());
    }
}
