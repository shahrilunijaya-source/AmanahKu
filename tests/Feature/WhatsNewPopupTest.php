<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Changelog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The "What's new" popup that greets everyone after an update. It lists the entries
 * marked `major: true` in changelog.yaml and nothing else — the rest of the release
 * sits behind Read more on the Changelog screen.
 */
class WhatsNewPopupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A release may have no major entry at all — a quiet release simply shows no popup,
     * which is the documented behaviour. What must never happen is EVERY entry being
     * major, because then the popup is the whole release and Read more leads nowhere.
     */
    public function test_no_release_marks_every_entry_major(): void
    {
        foreach (Changelog::releases() as $release) {
            $major = array_filter($release['entries'], fn (array $entry): bool => $entry['major']);

            $this->assertLessThan(
                count($release['entries']),
                count($major),
                "Release {$release['version']} is all major, leaving nothing behind Read more.",
            );
        }
    }

    public function test_the_endpoint_returns_the_major_entries_and_nothing_else(): void
    {
        $latest = Changelog::releases()[0];
        $this->seed(DatabaseSeeder::class);
        $hr = User::where('email', 'aisyah.rahman@unijaya.example')->firstOrFail();

        $response = $this->actingAs($hr)->getJson(route('whats-new'));

        $response->assertOk();
        $html = $response->json('html');

        foreach ($latest['entries'] as $entry) {
            // The opening of the entry's first line, which the popup shows verbatim.
            $needle = e(Str::limit(explode("\n", $entry['text'])[0], 60, ''));

            $entry['major']
                ? $this->assertStringContainsString($needle, $html)
                : $this->assertStringNotContainsString($needle, $html);
        }

        $this->assertSame(
            count($latest['entries']) - count(array_filter($latest['entries'], fn (array $e): bool => $e['major'])),
            $response->json('rest'),
        );
    }

    /** No screen carries the release notes — only the version the popup compares against. */
    public function test_a_screen_does_not_carry_the_release_notes(): void
    {
        $latest = Changelog::releases()[0];
        $this->seed(DatabaseSeeder::class);
        $hr = User::where('email', 'aisyah.rahman@unijaya.example')->firstOrFail();
        $tenant = Tenant::where('slug', 'unijaya')->firstOrFail();

        $response = $this->actingAs($hr)
            ->withSession(['current_tenant' => $tenant->id, 'persona' => 'hr'])
            ->get(route('app.screen', 'dash'));

        $response->assertOk();
        $response->assertSee($latest['version'], escape: false);

        foreach ($latest['entries'] as $entry) {
            $response->assertDontSee(Str::limit(explode("\n", $entry['text'])[0], 60, ''));
        }
    }
}
