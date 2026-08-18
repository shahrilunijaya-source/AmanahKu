<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Support\ApiReference;
use Tests\TestCase;

/**
 * The public API reference. It has no auth by design — it documents shapes, not data,
 * and openapi.json is already served unauthenticated.
 */
class ApiDocsPageTest extends TestCase
{
    public function test_a_logged_out_visitor_can_read_it(): void
    {
        $response = $this->get('/docs/api');

        $response->assertOk();
        $response->assertSee('AmanahKu API', escape: false);
    }

    public function test_it_lists_every_endpoint(): void
    {
        $response = $this->get('/docs/api');

        foreach (ApiReference::ENDPOINTS as $endpoint) {
            $response->assertSee($endpoint['path'], escape: false);
        }
    }

    public function test_it_offers_the_five_grantable_scopes_and_marks_payslips_otherwise(): void
    {
        $response = $this->get('/docs/api');

        foreach (array_keys(ApiClient::SCOPES) as $scope) {
            $response->assertSee($scope, escape: false);
        }

        // /payslips is still documented, but must not read as something you can ask for.
        $response->assertSee('/payslips', escape: false);
        $response->assertSee('Staff tokens only', escape: false);
    }

    public function test_the_agent_brief_is_embedded_so_the_copy_button_has_something_to_copy(): void
    {
        $response = $this->get('/docs/api');

        // A distinctive line from the brief, proving it was rendered into the page
        // rather than the button being wired to an empty string.
        $response->assertSee('HARD CONSTRAINTS', escape: false);
        $response->assertSee('week_start MUST be an exact Monday', escape: false);

        // The discriminating one. Blade escapes entities, so a brief embedded in a raw-text
        // element (<script>) reaches the clipboard as &quot;data&quot; and -&gt;. This line
        // fails under that embed and passes under a correct one, which the phrases above
        // cannot distinguish because they contain no escapable characters.
        $response->assertSee('success -> {"data"', escape: false);
    }

    public function test_it_does_not_leak_a_key_or_invite_anyone_to_paste_one(): void
    {
        $response = $this->get('/docs/api');

        // A public page must never hold a credential, and must not teach the habit of
        // pasting one into a public page.
        $response->assertDontSee('amk_live_', escape: false);
        $response->assertDontSee('<input type="password"', escape: false);
    }
}
