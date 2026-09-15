<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Privacy policy and terms of service. Google's OAuth consent screen links to both, and
 * its reviewers open them logged out, so they must answer without a session.
 */
class LegalPagesTest extends TestCase
{
    public function test_a_logged_out_visitor_can_read_the_privacy_policy(): void
    {
        $response = $this->get('/privacy');

        $response->assertOk();
        $response->assertSee('Privacy Policy');
        $response->assertSee('Google API Services User Data Policy');
    }

    public function test_a_logged_out_visitor_can_read_the_terms(): void
    {
        $response = $this->get('/terms');

        $response->assertOk();
        $response->assertSee('Terms of Service');
    }

    public function test_the_login_page_links_to_both(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee(route('privacy'));
        $response->assertSee(route('terms'));
    }
}
