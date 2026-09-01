<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Per-user dashboard widget visibility and drag order, stored as JSON on
 * users.dashboard_prefs:
 *
 *   {"dash":{"hidden":["work"],"order":{"left":["tasks"],"right":["calendar"]}}}
 *
 * One key, not one per scope: the Me/Company scope switch is gone and the
 * dashboard is a single grid of role-gated widgets. Prefs written under the old
 * "me"/"company" keys are simply never read again — the column is untyped JSON,
 * so nothing has to migrate and nobody loses a dashboard mid-deploy.
 *
 * Ids that are not in the registry are dropped on the way in and on the way out,
 * so a stale client (or a hand-written POST) can never park junk in the column.
 * Pinned widgets are stripped from `hidden` for the same reason they always
 * were: nobody gets to bury their own action list.
 */
class DashboardPrefs
{
    /** The single key everything lives under. */
    public const KEY = 'dash';

    /**
     * Sanitised prefs for the signed-in user.
     *
     * @return array{hidden: list<string>, order: array<string, list<string>>}
     */
    public static function forUser(?array $prefs): array
    {
        $raw = $prefs[self::KEY] ?? [];

        return [
            'hidden' => self::cleanIds($raw['hidden'] ?? [], stripPinned: true),
            'order' => self::cleanOrder($raw['order'] ?? []),
        ];
    }

    /**
     * Merge an incoming {hidden, order} update into the user's full prefs array.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public static function merge(?array $prefs, array $hidden, array $order): array
    {
        $prefs ??= [];
        $prefs[self::KEY] = [
            'hidden' => self::cleanIds($hidden, stripPinned: true),
            'order' => self::cleanOrder($order),
        ];

        return $prefs;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, list<string>>
     */
    private static function cleanOrder(array $order): array
    {
        $clean = [];
        foreach (DashboardWidgets::COLUMNS as $column) {
            $clean[$column] = is_array($order[$column] ?? null)
                ? self::cleanIds($order[$column], stripPinned: false)
                : [];
        }

        return $clean;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<string>
     */
    private static function cleanIds(array $ids, bool $stripPinned): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (! is_string($id) || ! DashboardWidgets::exists($id) || in_array($id, $clean, true)) {
                continue;
            }
            if ($stripPinned && in_array($id, DashboardWidgets::PINNED, true)) {
                continue;
            }
            $clean[] = $id;
        }

        return $clean;
    }
}
