<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Per-user, per-scope dashboard card visibility/order, stored as JSON on
 * users.dashboard_prefs: {"me":{"hidden":[],"order":[]},"company":{"hidden":[],"order":[]}}.
 *
 * `queue` and `secondary` are the user's real action list — hiding them would let
 * someone bury their own obligations, so they are pinned and can never appear in
 * `hidden` no matter what the client sends.
 */
class DashboardPrefs
{
    /** Card ids that can never be hidden. */
    public const PINNED = ['queue', 'secondary'];

    /**
     * Sanitised prefs for one scope: pinned ids stripped out of `hidden`, everything
     * else passed through as plain strings.
     *
     * @return array{hidden: array<int, string>, order: array<int, string>}
     */
    public static function forScope(?array $prefs, string $scope): array
    {
        $raw = $prefs[$scope] ?? [];

        return [
            'hidden' => self::stripPinned($raw['hidden'] ?? []),
            'order' => array_values(array_map('strval', $raw['order'] ?? [])),
        ];
    }

    /**
     * Merge an incoming {hidden, order} update for one scope into the user's full
     * prefs array, dropping pinned card ids from `hidden` regardless of what was sent.
     *
     * @return array<string, array{hidden: array<int, string>, order: array<int, string>}>
     */
    public static function merge(?array $prefs, string $scope, array $hidden, array $order): array
    {
        $prefs ??= [];
        $prefs[$scope] = [
            'hidden' => self::stripPinned($hidden),
            'order' => array_values(array_map('strval', $order)),
        ];

        return $prefs;
    }

    /** @return array<int, string> */
    private static function stripPinned(array $hidden): array
    {
        return array_values(array_diff(array_map('strval', $hidden), self::PINNED));
    }
}
