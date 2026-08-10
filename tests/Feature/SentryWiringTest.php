<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The settings that decide what leaves this host.
 *
 * Sentry is a third party, and this application's request bodies are HR data: GPS
 * coordinates from a clock-in, the reason an employee typed for being late, a payroll
 * edit. Every assertion here is a leak that would otherwise be one config edit away, and
 * config/sentry.php is a vendor-published file that an upgrade may rewrite — so the
 * decisions are pinned in a test rather than left to a comment nobody re-reads.
 */
class SentryWiringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Production only, both SDKs. Anything else — local, testing, staging — resolves to a
     * null DSN, and a null DSN makes each SDK inert rather than merely quiet: nothing is
     * queued and no request is attempted. Asserted rather than trusted because the gate is
     * an easy thing to lose in a merge, and losing it is silent: faults from a developer's
     * machine would simply start appearing in the project alongside the real ones.
     *
     * This runs under APP_ENV=testing, so it exercises the closed branch. The open branch is
     * proven on production itself, by /admin/errors/self-test.
     */
    public function test_no_dsn_resolves_outside_production(): void
    {
        $this->assertNotSame('production', config('app.env'));
        $this->assertNull(config('sentry.dsn'));
        $this->assertNull(config('services.sentry.browser_dsn'));
    }

    public function test_request_bodies_are_never_sent(): void
    {
        $this->assertSame('none', config('sentry.max_request_body_size'));
    }

    /** With PII on, the SDK attaches cookies — and the session cookie is a live login. */
    public function test_personally_identifying_data_is_off(): void
    {
        $this->assertFalse((bool) config('sentry.send_default_pii'));
    }

    /** Bindings are the query's values: names, coordinates, password hashes. */
    public function test_sql_query_bindings_are_not_captured(): void
    {
        $this->assertFalse((bool) config('sentry.breadcrumbs.sql_bindings'));
    }

    /**
     * The browser SDK posts to a per-organisation ingest subdomain. The app sends a CSP on
     * every response, so without this the SDK is silently dead — every send blocked, the
     * dashboard simply empty, which reads as "no errors" rather than "no delivery".
     */
    public function test_the_content_security_policy_allows_the_sentry_ingest_host(): void
    {
        $response = $this->get('/login');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $connect = collect(explode(';', $csp))->first(fn ($part) => str_contains($part, 'connect-src'));

        $this->assertStringContainsString('https://*.ingest.sentry.io', (string) $connect);
        // The map picker's geocoder must survive the addition.
        $this->assertStringContainsString('https://nominatim.openstreetmap.org', (string) $connect);
    }

    /**
     * Blank DSN is the local and the pre-configuration state. The meta tag must still
     * render, empty, because resources/js/sentry.js reads it to decide to do nothing —
     * a missing tag and an empty one take the same branch, but only one of them proves
     * the wiring is present.
     */
    public function test_the_browser_dsn_meta_tag_renders_empty_when_unconfigured(): void
    {
        config(['services.sentry.browser_dsn' => null]);

        $this->get('/login')->assertOk()->assertSee('<meta name="sentry-dsn" content="">', false);
    }

    public function test_the_browser_dsn_meta_tag_carries_the_configured_key(): void
    {
        config(['services.sentry.browser_dsn' => 'https://examplekey@o0.ingest.sentry.io/1']);

        $this->get('/login')
            ->assertOk()
            ->assertSee('<meta name="sentry-dsn" content="https://examplekey@o0.ingest.sentry.io/1">', false);
    }

    /**
     * Id only. A name or an email in the meta tag would put staff identities in the page
     * source of every screen, and in a vendor's dashboard — the app's own console is where
     * an id resolves to a person.
     */
    public function test_the_user_meta_tag_carries_an_id_and_nothing_else(): void
    {
        $tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        $user = User::create(['name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => 'employee']);

        $response = $this->actingAs($user)
            ->withSession(['current_tenant' => $tenant->id])
            ->get('/app/dashboard');

        $response->assertOk()->assertSee('<meta name="sentry-user" content="'.$user->id.'">', false);
        $response->assertDontSee('<meta name="sentry-user" content="aisyah@example.com">', false);
    }
}
