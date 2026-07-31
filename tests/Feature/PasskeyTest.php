<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\Features;
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
}
