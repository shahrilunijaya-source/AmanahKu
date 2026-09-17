<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkeys;
use Tests\TestCase;

class PasskeyTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Pat', 'email' => 'pat@example.com', 'password' => Hash::make('password')]);
    }

    public function test_passkeys_feature_is_enabled(): void
    {
        $this->assertTrue(Features::enabled(Features::passkeys()));
    }

    public function test_user_implements_the_passkey_contract(): void
    {
        $user = $this->user();

        $this->assertInstanceOf(PasskeyUser::class, $user);
        $this->assertFalse($user->hasPasskeysEnabled());
        $this->assertCount(0, $user->passkeys);
        $this->assertNotSame('', $user->getPasskeyUserHandle());
    }

    public function test_registration_options_require_authentication(): void
    {
        // Management routes sit behind the auth middleware.
        $this->get('/user/passkeys/options')->assertRedirect('/login');
    }

    public function test_authenticated_user_gets_registration_options(): void
    {
        // Passkey management now sits behind password.confirm (step-up) — seed a recent
        // confirmation so the management route is reachable.
        $response = $this->actingAs($this->user())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->getJson('/user/passkeys/options');

        $response->assertOk();
        $response->assertJsonStructure(['options' => ['challenge', 'rp', 'user', 'pubKeyCredParams']]);
    }

    public function test_registration_options_require_recent_password_confirmation(): void
    {
        // Without a recent password confirmation the management route is bounced for step-up.
        $this->actingAs($this->user())
            ->getJson('/user/passkeys/options')
            ->assertStatus(423);
    }

    public function test_guest_gets_login_options(): void
    {
        $response = $this->getJson('/passkeys/login/options');

        $response->assertOk();
        $response->assertJsonStructure(['options' => ['challenge']]);
    }

    public function test_deleting_a_passkey_requires_authentication(): void
    {
        // Even with a bogus id, an unauthenticated request is bounced to login.
        $this->delete('/user/passkeys/1')->assertRedirect('/login');
    }

    // ── FortifyServiceProvider's Passkeys::authorizeLoginUsing() gate ──────
    // security.passkey = 'off' must block passkey sign-in outright, not merely hide
    // the registration box on the personal Settings screen (see FeatureEnforcementTest
    // for that separate $passkeyEnabled UI flag, which security.passkey still controls
    // unchanged).

    private function tenant(): Tenant
    {
        return Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    public function test_passkey_login_is_refused_when_the_users_company_has_passkeys_off(): void
    {
        $tenant = $this->tenant();
        $user = $this->user();
        $user->tenants()->attach($tenant->id, ['role' => 'hr']);
        app(FeatureManager::class)->setTenant($tenant, 'security.passkey', 'off');

        $passkey = $user->passkeys()->create([
            'name' => 'Test key',
            'credential_id' => 'cred-off',
            'credential' => ['type' => 'public-key'],
        ]);

        try {
            Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey);
            $this->fail('Expected InvalidPasskeyException to be thrown.');
        } catch (InvalidPasskeyException $e) {
            // assertSame (not expectExceptionMessage, which only checks the message contains
            // this string) proves the login page's e.message is exactly this text, with no
            // "(and N more errors)" suffix from ValidationException::summarize().
            $this->assertSame('Your company has turned off passkey sign-in. Please use your password.', $e->getMessage());
        }
    }

    public function test_passkey_login_is_allowed_when_the_users_company_has_passkeys_optional(): void
    {
        $tenant = $this->tenant();
        $user = $this->user();
        $user->tenants()->attach($tenant->id, ['role' => 'hr']);
        app(FeatureManager::class)->setTenant($tenant, 'security.passkey', 'optional');

        $passkey = $user->passkeys()->create([
            'name' => 'Test key',
            'credential_id' => 'cred-optional',
            'credential' => ['type' => 'public-key'],
        ]);

        $this->assertTrue(Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey));
    }

    public function test_super_admin_passkey_login_is_allowed_even_if_a_company_has_it_off(): void
    {
        $tenant = $this->tenant();
        $admin = $this->user();
        $admin->forceFill(['is_super_admin' => true])->save();
        // Attach to the very tenant that has passkeys off, so the pass proves the
        // super-admin bypass, not just the absence of any tenant to check.
        $admin->tenants()->attach($tenant->id, ['role' => 'management']);
        app(FeatureManager::class)->setTenant($tenant, 'security.passkey', 'off');

        $passkey = $admin->passkeys()->create([
            'name' => 'Admin key',
            'credential_id' => 'cred-admin',
            'credential' => ['type' => 'public-key'],
        ]);

        $this->assertTrue(Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey));
    }
}
