<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthFlowsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    private ?User $hrUser = null;

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

    private function hr(): User
    {
        if ($this->hrUser) {
            return $this->hrUser;
        }
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        return $this->hrUser = $hr;
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr())->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    // ── Password reset ────────────────────────────────────────────

    public function test_forgot_password_screen_renders(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Reset your password');
    }

    public function test_user_can_request_a_password_reset_link(): void
    {
        $this->post('/forgot-password', ['email' => 'demo@example.com'])->assertSessionHas('status');
    }

    public function test_reset_password_screen_renders(): void
    {
        $this->get('/reset-password/sometoken?email=demo@example.com')->assertOk()->assertSee('Choose a new password');
    }

    // ── Two-factor ────────────────────────────────────────────────

    public function test_security_screen_renders(): void
    {
        $this->actingInTenant()->get('/app/security')->assertOk()->assertSee('Two-factor authentication');
    }

    public function test_user_can_enable_two_factor(): void
    {
        $this->assertNull($this->user->two_factor_secret);

        // 2FA management now requires a recent password confirmation (I-007 / confirmPassword=true).
        $this->actingAs($this->user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication');

        $this->assertNotNull($this->user->fresh()->two_factor_secret);
    }

    // ── Member invite ─────────────────────────────────────────────

    public function test_privileged_user_invites_a_new_member(): void
    {
        $this->actingHr()->post('/app/members', [
            'name' => 'New Hire', 'email' => 'newhire@example.com', 'role' => 'manager', 'position' => 'Team Lead',
        ])->assertRedirect();

        $new = User::where('email', 'newhire@example.com')->first();
        $this->assertNotNull($new);
        $this->assertTrue($new->tenants()->whereKey($this->tenant->id)->exists());
        $this->assertSame('manager', $new->roleIn($this->tenant));
        $this->assertDatabaseHas('employees', ['user_id' => $new->id, 'tenant_id' => $this->tenant->id, 'name' => 'New Hire']);
    }

    public function test_existing_email_cannot_be_attached_across_tenants(): void
    {
        // A login that exists in another tenant must not be silently pulled into this one.
        $other = Tenant::create(['slug' => 'other2', 'name' => 'Other Co', 'initials' => 'OC']);
        $existing = User::create(['name' => 'Existing', 'email' => 'existing@example.com', 'password' => Hash::make('x')]);
        $existing->tenants()->attach($other->id, ['role' => 'employee']);

        $this->actingHr()->post('/app/members', [
            'name' => 'Existing', 'email' => 'existing@example.com', 'role' => 'manager',
        ])->assertSessionHasErrors('email');

        $this->assertFalse($existing->fresh()->tenants()->whereKey($this->tenant->id)->exists());
    }

    public function test_invite_role_is_capped_at_manager(): void
    {
        $this->actingHr()->post('/app/members', [
            'name' => 'Would-be Admin', 'email' => 'wba@example.com', 'role' => 'hr',
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'wba@example.com']);
    }

    public function test_disabling_two_factor_requires_the_current_password(): void
    {
        $this->user->forceFill([
            'two_factor_secret' => encrypt('SECRETKEY'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingInTenant()->post('/app/security/two-factor/disable', ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');
        $this->assertNotNull($this->user->fresh()->two_factor_secret);

        $this->actingInTenant()->post('/app/security/two-factor/disable', ['password' => 'password'])
            ->assertRedirect();
        $this->assertNull($this->user->fresh()->two_factor_secret);
    }

    public function test_inviting_an_existing_member_is_rejected(): void
    {
        // demo@example.com is already a member of $this->tenant.
        $this->actingHr()->post('/app/members', [
            'name' => 'Demo', 'email' => 'demo@example.com', 'role' => 'manager',
        ])->assertSessionHasErrors('email');
    }

    public function test_employee_cannot_invite_a_member(): void
    {
        $this->actingInTenant()->post('/app/members', [
            'name' => 'X', 'email' => 'x@example.com', 'role' => 'hr',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }
}
