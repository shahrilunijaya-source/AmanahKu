<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use Illuminate\Support\Facades\Cache;

/**
 * How far a person's "Sync now" has got, for the board to poll. Cache only: it is a
 * progress bar, not a record, and it expires on its own after ten minutes.
 */
final class CalendarSyncProgress
{
    private const TTL = 600;

    /** @return array{state: string, total: int, done: int, failed: int, pulled: int, finished_at: ?string}|null */
    public static function get(int $userId): ?array
    {
        return Cache::get(self::key($userId));
    }

    public static function start(int $userId, int $total): void
    {
        Cache::put(self::key($userId), ['state' => 'running', 'total' => $total, 'done' => 0, 'failed' => 0, 'pulled' => 0, 'finished_at' => null], self::TTL);
    }

    public static function tick(int $userId, bool $ok): void
    {
        $p = self::get($userId) ?? ['state' => 'running', 'total' => 0, 'done' => 0, 'failed' => 0, 'pulled' => 0, 'finished_at' => null];
        $p['done']++;
        $p['failed'] += $ok ? 0 : 1;
        Cache::put(self::key($userId), $p, self::TTL);
    }

    public static function finish(int $userId, string $state, int $pulled = 0): void
    {
        $p = self::get($userId) ?? ['total' => 0, 'done' => 0, 'failed' => 0];
        Cache::put(self::key($userId), ['state' => $state, 'pulled' => $pulled, 'finished_at' => now()->toIso8601String()] + $p, self::TTL);
    }

    private static function key(int $userId): string
    {
        return "calendar-sync-progress:{$userId}";
    }
}
