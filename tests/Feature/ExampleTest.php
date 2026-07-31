<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Guests hitting the root are sent to the login screen.
     */
    public function test_root_redirects_guests_to_login(): void
    {
        $response = $this->get('/');

        $response->assertredirect('/login');
    }
}
