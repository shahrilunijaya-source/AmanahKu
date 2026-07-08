<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function member(bool $mustChange, string $role = 'employee', string $password = 'password'): User
    {
        $user = User::create(['name' => 'Pat', 'email' => 'pat@example.com', 'password' => Hash::make($password)]);
        $user->forceFill(['password_change_required' => $mustChange])->save();
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Pat', 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    // ── Security headers ──────────────────────────────────────────

    public function test_hsts_header_is_sent_over_https(): void
    {
        $this->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
    }

    public function test_hsts_header_absent_over_plain_http(): void
    {
        $response = $this->get('/login');
        $this->assertNull($response->headers->get('Strict-Transport-Security'));
        // Baseline headers still present regardless of scheme.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_content_security_policy_locks_down_framing_and_base(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
    }

    // ── Forced password rotation (I-008) ──────────────────────────

    public function test_invited_member_must_change_password_first(): void
    {
        $user = $this->member(mustChange: true);

        $this->actingAs($user)->get('/tenant')
            ->assertRedirect(route('password.change'));
    }

    public function test_flagged_user_can_reach_the_change_screen(): void
    {
        $user = $this->member(mustChange: true);

        $this->actingAs($user)->get('/password/change')
            ->assertOk()
            ->assertSee('Set your password');
    }

    public function test_changing_password_clears_the_flag_and_releases_the_user(): void
    {
        $user = $this->member(mustChange: true, password: 'temp-one-time-pw');

        $this->actingAs($user)->post('/password/change', [
            'current_password' => 'temp-one-time-pw',
            'password' => 'Str0ng-New-Pass!',
            'password_confirmation' => 'Str0ng-New-Pass!',
        ])->assertRedirect('/tenant');

        $this->assertFalse($user->fresh()->password_change_required);
        // Now the app is reachable again.
        $this->actingAs($user->fresh())->get('/tenant')->assertOk();
    }

    public function test_wrong_temporary_password_is_rejected(): void
    {
        $user = $this->member(mustChange: true, password: 'temp-one-time-pw');

        $this->actingAs($user)->post('/password/change', [
            'current_password' => 'wrong-password',
            'password' => 'Str0ng-New-Pass!',
            'password_confirmation' => 'Str0ng-New-Pass!',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue($user->fresh()->password_change_required);
    }

    public function test_unflagged_user_is_not_redirected(): void
    {
        $user = $this->member(mustChange: false);

        $this->actingAs($user)->get('/tenant')->assertOk();
    }

    public function test_member_invite_sets_the_rotation_flag(): void
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/members', [
                'name' => 'New Hire',
                'email' => 'newhire@example.com',
                'role' => 'employee',
                'position' => 'Analyst',
            ])->assertRedirect();

        $invited = User::where('email', 'newhire@example.com')->first();
        $this->assertNotNull($invited);
        $this->assertTrue($invited->password_change_required);
    }

    // ── 2FA password confirmation (I-007) ─────────────────────────

    public function test_confirm_password_screen_renders(): void
    {
        $user = $this->member(mustChange: false);

        $this->actingAs($user)->get('/user/confirm-password')
            ->assertOk()
            ->assertSee('Confirm your password');
    }

    // ── HR password reset ─────────────────────────────────────────

    /** Create an HR user in the tenant plus a target employee with a login. */
    private function targetWithLogin(string $password = 'old-password'): array
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $target = User::create(['name' => 'Pat', 'email' => 'pat@example.com', 'password' => Hash::make($password)]);
        $target->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $target->id,
            'name' => 'Pat', 'status' => 'active', 'workload' => 'green',
        ]);

        return [$hr, $target, $employee];
    }

    public function test_hr_reset_rotates_the_password_and_reveals_it_once(): void
    {
        [$hr, $target, $employee] = $this->targetWithLogin('old-password');

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/members/{$employee->id}/reset-password");

        $response->assertRedirect();
        $response->assertSessionHas('reset_password');

        $flashed = session('reset_password');
        $this->assertSame('Pat', $flashed['name']);

        $fresh = $target->fresh();
        // Must rotate on next sign-in, and the credential shown to HR is the real one.
        $this->assertTrue($fresh->password_change_required);
        $this->assertTrue(Hash::check($flashed['password'], $fresh->password));
        // The old password is dead.
        $this->assertFalse(Hash::check('old-password', $fresh->password));
    }

    public function test_non_privileged_role_cannot_reset_a_password(): void
    {
        [, $target, $employee] = $this->targetWithLogin('old-password');

        $plain = User::create(['name' => 'Nobody', 'email' => 'nobody@example.com', 'password' => Hash::make('password')]);
        $plain->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->actingAs($plain)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/members/{$employee->id}/reset-password")
            ->assertForbidden();

        // Untouched: still the old password, no forced rotation.
        $fresh = $target->fresh();
        $this->assertFalse($fresh->password_change_required);
        $this->assertTrue(Hash::check('old-password', $fresh->password));
    }

    public function test_reset_on_a_directory_record_without_a_login_is_rejected(): void
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => null,
            'name' => 'No Login', 'email' => 'nologin@example.com',
            'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/members/{$employee->id}/reset-password")
            ->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionMissing('reset_password');
    }
}
