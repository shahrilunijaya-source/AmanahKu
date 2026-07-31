<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

/**
 * The demo seeder mints a super-admin with a known password. It must never run
 * against a real deployment — only local development and the test suite.
 */
class DatabaseSeederGuardTest extends TestCase
{
    public function test_seeder_refuses_to_run_outside_local_and_testing(): void
    {
        // A fresh application is built per test method, so mutating the environment
        // here does not leak into any other test.
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame('production', $this->app->environment());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refused outside local/testing');

        (new DatabaseSeeder)->run();
    }
}
