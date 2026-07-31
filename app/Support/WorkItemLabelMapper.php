<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure mapping logic for the T.A.A. board redesign's label-palette prune
 * (Stage 4, 2026-07-29). Three labels duplicated a field the card already
 * carried, and could contradict it:
 *
 *  - `urgent`  folds into `priority`: set to `high` unless already `high`,
 *    then the label is dropped.
 *  - `waiting` renames to `blocked`, de-duplicated if the card already
 *    carries `blocked`.
 *  - `review`  is deleted outright. It maps to nothing.
 *
 * Kept as a plain static method (no migration, no DB) so the mapping is
 * provable with a unit test rather than only by running the migration that
 * calls it — see database/migrations/*_migrate_work_item_labels.php.
 */
final class WorkItemLabelMapper
{
    /** Priority values `urgent` overrides. An existing `high` is left alone. */
    private const PRIORITIES_URGENT_OVERRIDES = [null, 'low', 'medium'];

    /**
     * @param  array<int, string>|null  $labels  The card's current labels, in
     *                                           order. Null and empty are both
     *                                           accepted and produce an empty result.
     * @param  string|null  $priority  The card's current priority.
     * @return array{labels: array<int, string>, priority: string|null}
     */
    public static function map(?array $labels, ?string $priority): array
    {
        $labels ??= [];

        if (in_array('urgent', $labels, true) && in_array($priority, self::PRIORITIES_URGENT_OVERRIDES, true)) {
            $priority = 'high';
        }

        $mapped = array_map(
            static fn (string $label): ?string => match ($label) {
                'urgent', 'review' => null,
                'waiting' => 'blocked',
                default => $label,
            },
            $labels,
        );

        // array_filter drops the removed (`null`) slots; array_unique then
        // de-dupes `blocked` against a `waiting` that mapped onto it, keeping
        // the first occurrence's position so surviving order is undisturbed.
        $survivors = array_unique(array_filter($mapped, static fn (?string $label): bool => $label !== null));

        return ['labels' => array_values($survivors), 'priority' => $priority];
    }
}
