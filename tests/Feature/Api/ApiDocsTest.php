<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Support\ApiReference;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The docs are hand-written files, not generated, so nothing but this test stops
 * them drifting from the code. It fails the moment a scope exists that no consumer
 * could have read about.
 */
class ApiDocsTest extends TestCase
{
    public function test_every_scope_is_documented_in_both_files(): void
    {
        $markdown = file_get_contents(base_path('docs/API.md'));
        $openapi = file_get_contents(public_path('openapi.json'));

        $this->assertIsString($markdown);
        $this->assertIsString($openapi);

        foreach (array_keys(ApiClient::SCOPES) as $scope) {
            $this->assertStringContainsString($scope, $markdown, "docs/API.md does not mention the {$scope} scope.");
            $this->assertStringContainsString($scope, $openapi, "public/openapi.json does not mention the {$scope} scope.");
        }
    }

    public function test_the_openapi_file_is_valid_json_and_lists_every_route(): void
    {
        $spec = json_decode((string) file_get_contents(public_path('openapi.json')), true);

        $this->assertIsArray($spec);

        foreach (['/employees', '/leave-requests', '/payslips', '/projects', '/positions', '/timesheet-effort'] as $path) {
            $this->assertArrayHasKey($path, $spec['paths'], "openapi.json is missing {$path}.");
        }
    }

    public function test_every_registered_api_route_has_a_reference_entry_and_the_reverse(): void
    {
        $registered = collect(Route::getRoutes())
            ->map(fn ($route) => '/'.$route->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, '/api/v1/'))
            ->map(fn (string $uri) => str_replace('/api/v1', '', $uri))
            ->unique()->sort()->values()->all();

        $documented = collect(ApiReference::ENDPOINTS)
            ->pluck('path')->unique()->sort()->values()->all();

        // Both directions on purpose. One catches an endpoint shipped without a card;
        // the other catches a card left behind after an endpoint was deleted.
        $this->assertSame($registered, $documented);
    }

    public function test_the_agent_brief_carries_every_grantable_scope(): void
    {
        $brief = ApiReference::agentBrief();

        foreach (array_keys(ApiClient::SCOPES) as $scope) {
            $this->assertStringContainsString($scope, $brief, "The agent brief never mentions {$scope}.");
        }
    }

    public function test_the_agent_brief_pins_the_facts_agents_get_wrong(): void
    {
        $brief = ApiReference::agentBrief();

        // Both 401 shapes. An agent told about only one writes a client that throws
        // on the other, and which one it meets depends on how the key failed.
        $this->assertStringContainsString('{"message": "Unauthenticated."}', $brief);
        $this->assertStringContainsString('{"data": null, "error": "Unauthenticated."}', $brief);

        // The silent-empty trap: a non-Monday week_start returns 200 with no rows.
        $this->assertStringContainsString('week_start', $brief);
        $this->assertStringContainsString('Monday', $brief);

        // The four constraints an agent will otherwise invent.
        $this->assertStringContainsString('Read-only', $brief);
        $this->assertStringContainsString('No webhooks', $brief);
        $this->assertStringContainsString('No pagination', $brief);
        $this->assertStringContainsString('No rate limit', $brief);
    }

    public function test_payslips_is_documented_but_marked_unavailable_to_app_keys(): void
    {
        $payslips = collect(ApiReference::ENDPOINTS)->firstWhere('path', '/payslips');

        $this->assertNotNull($payslips, 'The reference must still describe /payslips — staff tokens reach it.');
        $this->assertFalse($payslips['app_key'], 'An application key must not be able to request payslips.');
    }
}
