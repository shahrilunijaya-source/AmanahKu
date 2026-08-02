<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Does "Remember me" actually keep someone signed in?
 *
 * The login form has always carried the checkbox and the users table has always had the
 * column, but nothing verified the behaviour that matters: staying signed in AFTER the
 * session is gone. Reading the code cannot prove that — only replaying the cookie against
 * an emptied session can, which is what test_stays_signed_in_after_the_session_is_lost does.
 * The other cases are supporting detail.
 */
class RememberMeTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['email' => 'aina@acme.test']);
    }

    /** The guard's per-app recaller cookie name, e.g. remember_web_<hash>. */
    private function recallerName(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    /**
     * Throw away everything the browser and the server hold about this session, so the
     * next request can only succeed on the strength of the remember cookie.
     */
    private function forgetTheSession(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    public function test_login_with_remember_sets_the_cookie_and_persists_a_token(): void
    {
        $user = $this->user();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => 'on',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($response->getCookie($this->recallerName()), 'No remember cookie was set.');
        $this->assertNotNull($user->fresh()->getRememberToken());
    }

    public function test_login_without_remember_sets_no_cookie(): void
    {
        $user = $this->user();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNull($response->getCookie($this->recallerName()), 'A remember cookie was set without the box ticked.');
    }

    public function test_stays_signed_in_after_the_session_is_lost(): void
    {
        $user = $this->user();

        $cookie = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => 'on',
        ])->getCookie($this->recallerName());

        $this->assertNotNull($cookie);

        $this->forgetTheSession();
        $this->assertGuest();

        // Only the remember cookie survives. getCookie() hands back the decrypted value,
        // and withCookie() re-encrypts it the way EncryptCookies expects on the way in.
        $this->withCookie($this->recallerName(), $cookie->getValue())
            ->get(route('tenant.select'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_stale_cookie_is_refused_after_the_token_is_rotated(): void
    {
        $user = $this->user();

        $cookie = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => 'on',
        ])->getCookie($this->recallerName());

        // Logging out cycles the remember token, which is what invalidates the cookie
        // still sitting in any other browser.
        $this->post('/logout');
        $this->forgetTheSession();

        $this->withCookie($this->recallerName(), $cookie->getValue())
            ->get(route('tenant.select'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
