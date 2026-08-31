<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Yaml\Yaml;

class Changelog
{
    /**
     * Parsed release notes from resources/changelog.yaml, newest release first (file order).
     *
     * `link` is optional: an entry that names a screen can carry one, and the changelog
     * renders it as a button beside the text. Absent on most entries. `major` marks a
     * headline change — the What's new popup lists those and nothing else.
     *
     * @return array<int, array{version: string, date: string, entries: array<int, array{tag: string, text: string, text_ms: string, major: bool, link: ?string, link_text: string, link_text_ms: string}>}>
     */
    public static function releases(): array
    {
        /** @var array<int, array{version: string, date: string, entries: array<int, array{tag: string, text: string, text_ms?: string, major?: bool, link?: string, link_text?: string, link_text_ms?: string}>}> $raw */
        $raw = Yaml::parseFile(resource_path('changelog.yaml'));

        return array_map(static fn (array $release): array => [
            'version' => $release['version'],
            'date' => $release['date'],
            'entries' => array_map(static fn (array $entry): array => [
                'tag' => $entry['tag'],
                'text' => $entry['text'],
                'text_ms' => $entry['text_ms'] ?? $entry['text'],
                'major' => (bool) ($entry['major'] ?? false),
                'link' => $entry['link'] ?? null,
                'link_text' => $entry['link_text'] ?? 'Open',
                'link_text_ms' => $entry['link_text_ms'] ?? $entry['link_text'] ?? 'Buka',
            ], $release['entries']),
        ], $raw);
    }
}
