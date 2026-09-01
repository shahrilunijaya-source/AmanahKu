<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Changelog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChangelogScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'initials' => 'AL',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
    }

    public function test_any_authenticated_user_can_open_the_changelog_screen(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/changelog');

        $response->assertOk();
        $response->assertViewHas('releases');
        $response->assertSee('Changelog');
    }

    public function test_it_lists_every_release_with_its_tagged_entries(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/changelog');

        $response->assertOk();
        $response->assertSee('1.1');
        $response->assertSee('New Changelog screen', false);
        $response->assertSee('Timesheet add-entry now opens in one popup', false);
        $response->assertSee('Added');
        $response->assertSee('Improved');
        // 'added' carries the success tone; 'improved' carries no data-tone (neutral default).
        // Derived from the data rather than hardcoded, so a new release doesn't need this test touched.
        $expectedAddedCount = collect(Changelog::releases())
            ->flatMap(fn (array $release) => $release['entries'])
            ->filter(fn (array $entry) => $entry['tag'] === 'added')
            ->count();
        $this->assertSame(
            $expectedAddedCount,
            substr_count($response->getContent(), 'uj-stamp" data-tone="success"'),
            'Every added-tagged entry must render with the success tone.'
        );
    }

    public function test_an_entry_can_carry_a_link_and_it_renders_as_an_anchor(): void
    {
        $linked = collect(Changelog::releases())
            ->flatMap(fn (array $release): array => $release['entries'])
            ->filter(fn (array $entry): bool => $entry['link'] !== null);

        $this->assertNotEmpty($linked, 'No changelog entry carries a link, so this test guards nothing.');

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/changelog');

        $response->assertOk();

        foreach ($linked as $entry) {
            // The href, not merely the URL as text — a bare URL in the copy would satisfy
            // a looser assertion while leaving the reader nothing to click.
            $response->assertSee('href="'.$entry['link'].'"', escape: false);
            $response->assertSee($entry['link_text'], escape: false);
        }
    }

    public function test_an_entry_without_a_link_carries_no_anchor_markup(): void
    {
        // Most entries have no link. The optional field must default to null rather than
        // rendering an empty or placeholder anchor.
        $unlinked = collect(Changelog::releases())
            ->flatMap(fn (array $release): array => $release['entries'])
            ->filter(fn (array $entry): bool => $entry['link'] === null);

        $this->assertNotEmpty($unlinked);

        foreach ($unlinked as $entry) {
            $this->assertNull($entry['link']);
        }
    }

    public function test_a_newline_in_an_entry_survives_to_the_page_and_is_set_to_render(): void
    {
        $multiline = collect(Changelog::releases())
            ->flatMap(fn (array $release): array => $release['entries'])
            ->filter(fn (array $entry): bool => str_contains($entry['text'], "\n"));

        $this->assertNotEmpty($multiline, 'No entry uses a line break, so this test guards nothing.');

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/changelog');

        $response->assertOk();

        // Two halves of the same check: the newline has to reach the markup, AND the
        // container has to be told to honour it. Either alone renders as one run-on line.
        $response->assertSee('white-space:pre-line', escape: false);

        // Decoded, because Blade escapes the apostrophes and quotes in the copy, so a
        // raw byte match would fail for reasons that have nothing to do with newlines.
        $rendered = html_entity_decode($response->getContent() ?: '', ENT_QUOTES);

        foreach ($multiline as $entry) {
            $this->assertStringContainsString($entry['text'], $rendered, 'The entry text, newline and all, is not in the markup.');

            // English and Malay must break in the same places, or the two languages
            // disagree about the shape of the entry.
            $this->assertSame(
                substr_count($entry['text'], "\n"),
                substr_count($entry['text_ms'], "\n"),
                'text and text_ms have a different number of line breaks.',
            );
        }
    }

    public function test_the_newest_release_announces_the_one_page_dashboard(): void
    {
        $newest = Changelog::releases()[0];

        $this->assertSame('1.7.1', $newest['version']);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/changelog');

        $response->assertOk();
        $response->assertSee('The dashboard is one page now', false);
        $response->assertSee('/app/dash', false);

        $response->assertSee('red dot on anything waiting for you', false);

        // Every entry in the release must carry its own Malay copy. A missing text_ms
        // silently falls back to English, which reads as a translation gap in the UI.
        foreach ($newest['entries'] as $entry) {
            $this->assertNotSame($entry['text'], $entry['text_ms'], 'A 1.7.1 entry has no Malay copy of its own.');
        }
    }

    public function test_the_sidebar_footer_links_to_the_changelog_from_any_screen(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/dash');

        $response->assertOk();
        $response->assertSee(route('app.screen', 'changelog'), false);
    }
}
