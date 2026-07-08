<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical copy + colour for the four Profile Test working-style archetypes.
 *
 * Ported from the Hiring platform. Archetypes are a FIXED system of four
 * (rabbit/tortoise/fox/sloth), so a catalog class is the right home — this is
 * not per-tenant config. The accent colours map to Amanahku's status swatch
 * keys so the personality card stays on-palette.
 */
class ArchetypeCatalog
{
    /** @var array<string, array<string, mixed>> */
    private const MAP = [
        'rabbit' => [
            'label' => 'Rabbit',
            'swatch' => 'amber',
            'accent' => 'var(--amber)',
            'tagline_en' => 'Dives in fast. Energetic, instinctive.',
            'suitable_en' => 'Sprints, launches, firefighting — work that rewards speed and momentum.',
            'plays_well_en' => 'Gets things moving when others stall; the energy is contagious.',
            'watch_outs_en' => 'Can leap before looking — pair with a planner on detail-heavy work.',
        ],
        'tortoise' => [
            'label' => 'Tortoise',
            'swatch' => 'green',
            'accent' => 'var(--success)',
            'tagline_en' => 'Steady and reliable. Prefers stability.',
            'suitable_en' => 'Long-haul builds, maintenance — anything needing consistency and follow-through.',
            'plays_well_en' => 'Finishes what it starts; the one you trust with the critical path.',
            'watch_outs_en' => 'Slower to pivot — give early warning before big changes.',
        ],
        'fox' => [
            'label' => 'Fox',
            'swatch' => 'red',
            'accent' => 'var(--error)',
            'tagline_en' => 'Strategic and resourceful. Adapts fast.',
            'suitable_en' => 'Ambiguous problems, tight constraints — work that needs a clever angle.',
            'plays_well_en' => 'Finds the path others miss; turns limits into leverage.',
            'watch_outs_en' => 'May cut corners for speed — check the shortcut is safe.',
        ],
        'sloth' => [
            'label' => 'Sloth',
            'swatch' => 'info',
            'accent' => 'var(--info)',
            'tagline_en' => 'Easygoing, low-urgency. Takes it slow.',
            'suitable_en' => 'Deep-focus work, careful review — anything that rewards patience over panic.',
            'plays_well_en' => 'Calm under pressure; rarely rushed into mistakes.',
            'watch_outs_en' => 'Needs clear deadlines to keep the urgency up.',
        ],
    ];

    /** Neutral fallback for an unknown / null archetype. */
    private const FALLBACK = [
        'label' => '—',
        'swatch' => 'muted',
        'accent' => 'var(--muted-soft)',
        'tagline_en' => '',
        'suitable_en' => '',
        'plays_well_en' => '',
        'watch_outs_en' => '',
    ];

    /** @return array<string, mixed> */
    public static function get(?string $key): array
    {
        return self::MAP[strtolower((string) $key)] ?? self::FALLBACK;
    }

    public static function has(?string $key): bool
    {
        return isset(self::MAP[strtolower((string) $key)]);
    }

    /** Display metadata (emoji + one-word trait) for the editor legend/badges. */
    public static function emoji(?string $key): string
    {
        return [
            'rabbit' => '🐇',
            'tortoise' => '🐢',
            'fox' => '🦊',
            'sloth' => '🦥',
        ][strtolower((string) $key)] ?? '•';
    }
}
