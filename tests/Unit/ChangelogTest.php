<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Changelog;
use Tests\TestCase;

class ChangelogTest extends TestCase
{
    public function test_releases_returns_the_newest_release_first(): void
    {
        $releases = Changelog::releases();

        $this->assertNotEmpty($releases);
        $this->assertSame('1.1', $releases[0]['version']);
        $this->assertSame('2026-08-06', $releases[0]['date']);
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

    public function test_text_ms_falls_back_to_text_when_the_yaml_omits_it(): void
    {
        $entries = Changelog::releases()[0]['entries'];
        $improved = collect($entries)->firstWhere('tag', 'improved');

        $this->assertNotNull($improved, 'Seed data must keep one entry without text_ms to exercise the fallback.');
        $this->assertSame($improved['text'], $improved['text_ms']);
    }
}
