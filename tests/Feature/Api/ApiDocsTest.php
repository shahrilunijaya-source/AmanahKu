<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ApiClient;
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
}
