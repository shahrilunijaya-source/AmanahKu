<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CompanyInvite;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Super-admin company delete. Only for a company that is still empty (at most one
 * staff record, archived included), so it cleans up test or mistaken signups and can
 * never wipe a live company. Logins that belonged to nothing else go with it.
 */
class SuperAdminCompanyDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $u = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('password')]);
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    private function user(string $email): User
    {
        return User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('password')]);
    }

    private function staff(Tenant $tenant, ?User $user, array $extra = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $tenant->id, 'user_id' => $user?->id, 'name' => 'Staff '.Employee::withoutGlobalScopes()->count(),
            'status' => 'active', 'workload' => 'green', 'initials' => 'ST', 'avatar_color' => '#000',
            'joined_at' => now()->toDateString(),
        ], $extra));
    }

    /** A company with its first HR (the only staff record), as signup leaves it. */
    private function freshCompany(): array
    {
        $tenant = Tenant::create(['slug' => 'test-co', 'name' => 'Test Co Sdn Bhd', 'initials' => 'TC']);
        $hr = $this->user('hr@test.co');
        $hr->tenants()->attach($tenant->id, ['role' => 'hr']);
        $this->staff($tenant, $hr);
        Branch::create(['tenant_id' => $tenant->id, 'name' => 'HQ']);

        return [$tenant, $hr];
    }

    public function test_super_admin_deletes_an_empty_company_with_its_data_and_lone_logins(): void
    {
        [$tenant, $hr] = $this->freshCompany();
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $shared = $this->user('shared@test.co');
        $shared->tenants()->attach($tenant->id, ['role' => 'manager']);
        $shared->tenants()->attach($other->id, ['role' => 'employee']);
        $admin = $this->superAdmin();
        $admin->tenants()->attach($tenant->id, ['role' => 'hr']);
        $invite = CompanyInvite::factory()->usedBy($tenant)->create();

        Log::spy();

        $this->actingAs($admin)
            ->post(route('superadmin.companies.destroy', $tenant), ['confirm_name' => 'Test Co Sdn Bhd'])
            ->assertRedirect(route('superadmin.companies.index'))
            ->assertSessionHas('ok');

        $this->assertNull(Tenant::find($tenant->id));
        $this->assertSame(0, Employee::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertNull(User::find($hr->id), 'a login that belonged only here is removed');
        $this->assertNotNull(User::find($shared->id), 'a login with another company stays');
        $this->assertSame([$other->id], $shared->fresh()->tenants()->pluck('tenants.id')->all());
        $this->assertNotNull(User::find($admin->id), 'a super admin is never removed');
        $this->assertNull($invite->fresh(), 'the signup link goes with the company');

        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => str_contains($msg, 'Company deleted')
            && $ctx['company'] === 'Test Co Sdn Bhd' && $ctx['by'] === 'super@example.com' && $ctx['logins_removed'] === 1)->once();
    }

    public function test_a_company_with_more_than_one_staff_record_cannot_be_deleted(): void
    {
        [$tenant, $hr] = $this->freshCompany();
        $this->staff($tenant, null, ['archived_at' => now()]);

        $this->actingAs($this->superAdmin())
            ->post(route('superadmin.companies.destroy', $tenant), ['confirm_name' => 'Test Co Sdn Bhd'])
            ->assertSessionHasErrors('confirm_name');

        $this->assertNotNull(Tenant::find($tenant->id));
        $this->assertNotNull(User::find($hr->id));
    }

    public function test_the_typed_name_must_match_exactly(): void
    {
        [$tenant] = $this->freshCompany();

        $this->actingAs($this->superAdmin())
            ->post(route('superadmin.companies.destroy', $tenant), ['confirm_name' => 'test co sdn bhd'])
            ->assertSessionHasErrors('confirm_name');

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_non_super_admins_cannot_delete(): void
    {
        [$tenant, $hr] = $this->freshCompany();

        $this->actingAs($hr)
            ->post(route('superadmin.companies.destroy', $tenant), ['confirm_name' => 'Test Co Sdn Bhd'])
            ->assertStatus(403);

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_company_page_offers_delete_only_when_empty(): void
    {
        [$tenant] = $this->freshCompany();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('superadmin.companies.show', $tenant))
            ->assertOk()
            ->assertSee('action="'.route('superadmin.companies.destroy', $tenant).'"', false)
            ->assertSee('name="confirm_name"', false);

        $this->staff($tenant, null);

        $this->actingAs($admin)->get(route('superadmin.companies.show', $tenant))
            ->assertOk()
            ->assertDontSee('name="confirm_name"', false)
            ->assertSee('Only a company with no staff yet can be deleted');
    }
}
