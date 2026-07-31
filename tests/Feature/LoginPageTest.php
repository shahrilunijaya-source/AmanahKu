<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    /** The centered card contains the heading, field label, and passkey button. */
    public function test_login_page_renders_the_centered_card(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Welcome back');
        $response->assertSee('Work email');
        $response->assertSee('Sign in with a passkey');
    }

    /** The password field has the reveal eye toggle with an accessible label. */
    public function test_login_page_exposes_the_password_reveal_control(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Show password');
    }

    /** A failed login redirects back and flashes validation errors. */
    public function test_failed_login_flashes_validation_errors(): void
    {
        $response = $this->from('/login')->post('/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors();
    }
}
