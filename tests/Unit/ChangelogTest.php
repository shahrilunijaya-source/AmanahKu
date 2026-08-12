<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Changelog;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ChangelogTest extends TestCase
{
    /**
     * The screen renders the file in the order it reads it, so "newest first" is a
     * property of the YAML rather than of any sorting code. Asserted as an ordering
     * rule instead of a hardcoded top version, which every release had to come back
     * and edit.
     */
    public function test_releases_are_ordered_newest_first(): void
    {
        $releases = Changelog::releases();

        $this->assertNotEmpty($releases);

        foreach ($releases as $release) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $release['version']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $release['date']);
        }

        foreach (array_slice($releases, 1) as $i => $older) {
            $newer = $releases[$i];
            $this->assertTrue(
                version_compare($newer['version'], $older['version'], '>'),
                "Release {$newer['version']} must sit above {$older['version']} in changelog.yaml.",
            );
            $this->assertGreaterThanOrEqual($older['date'], $newer['date']);
        }
    }

    public function test_each_entry_has_a_tag_and_bilingual_text(): void
    {
        $entries = Changelog::releases()[0]['entries'];

        $this->assertNotEmpty($entries);
        foreach ($entries as $entry) {
            $this->assertContains($entry['tag'], ['added', 'improved', 'fixed']);
            $this->assertNotEmpty($entry['text']);
            $this->assertNotEmpty($entry['text_ms']);
        }
    }

    /**
     * Reads the raw YAML to find an entry that genuinely omits text_ms, then checks
     * releases() filled it in. The old version looked at the newest release only, so
     * it quietly demanded that every future release ship at least one untranslated
     * entry — the opposite of what we want the copy to do.
     */
    public function test_text_ms_falls_back_to_text_when_the_yaml_omits_it(): void
    {
        $raw = Yaml::parseFile(resource_path('changelog.yaml'));

        $untranslated = collect($raw)
            ->flatMap(fn (array $release): array => $release['entries'])
            ->firstWhere(fn (array $entry): bool => ! isset($entry['text_ms']));

        $this->assertNotNull($untranslated, 'changelog.yaml no longer exercises the text_ms fallback.');

        $rendered = collect(Changelog::releases())
            ->flatMap(fn (array $release): array => $release['entries'])
            ->firstWhere('text', $untranslated['text']);

        $this->assertSame($untranslated['text'], $rendered['text_ms']);
    }
}
