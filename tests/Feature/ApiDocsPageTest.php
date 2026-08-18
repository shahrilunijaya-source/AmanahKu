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

        // Test the property, not the escaping style: whatever escaping the view uses,
        // no unescaped '<' may survive into the element (it could open a tag), and the
        // text the browser hands the clipboard must equal the brief exactly. This fails
        // for a fully-raw embed, which silently eats the <key> and <scope> placeholders.
        preg_match('#<pre id="agent-brief" hidden>(.*?)</pre>#s', $response->getContent(), $m);

        $this->assertNotEmpty($m, 'The agent brief is not embedded in a hidden <pre>.');
        $this->assertStringNotContainsString('<', $m[1]);
        $this->assertSame(ApiReference::agentBrief(), html_entity_decode($m[1], ENT_QUOTES));
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
