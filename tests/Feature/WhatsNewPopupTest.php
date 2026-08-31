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

    public function test_the_changelog_marks_some_entries_major(): void
    {
        $latest = Changelog::releases()[0];
        $major = array_filter($latest['entries'], fn (array $entry): bool => $entry['major']);

        $this->assertNotEmpty($major, 'The newest release has no major entry, so nobody would see a popup.');
        $this->assertLessThan(count($latest['entries']), count($major), 'Everything being major leaves nothing behind Read more.');
    }

    public function test_the_shell_shows_the_major_entries_and_hides_the_rest(): void
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
            // The opening of the entry's first line, which the popup shows verbatim.
            $needle = Str::limit(explode("\n", $entry['text'])[0], 60, '');

            if ($entry['major']) {
                $response->assertSee($needle, escape: false);
            } else {
                $response->assertDontSee($needle, escape: false);
            }
        }
    }
}
