<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppNotification extends Model
{
    use BelongsToTenant;

    protected $table = 'app_notifications';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Queue an in-app notification for a single user (tenant auto-filled).
     *
     * Pass $dedupeKey from a scheduled sender to make the call idempotent: the first
     * call for a given tenant + user + key wins, every repeat is a no-op.
     *
     * @return bool True when a row was created, false when suppressed.
     */
    public static function send(?int $userId, string $title, ?string $body = null, ?string $url = null, ?string $dedupeKey = null): bool
    {
        if (! $userId) {
            return false;
        }

        $attributes = ['user_id' => $userId, 'title' => $title, 'body' => $body, 'url' => $url];

        if ($dedupeKey === null) {
            static::create($attributes);

            return true;
        }

        return static::firstOrCreate(
            ['user_id' => $userId, 'dedupe_key' => $dedupeKey],
            $attributes,
        )->wasRecentlyCreated;
    }

    /** @param iterable<int> $userIds */
    public static function sendMany(iterable $userIds, string $title, ?string $body = null, ?string $url = null, ?string $dedupeKey = null): void
    {
        foreach ($userIds as $id) {
            static::send($id, $title, $body, $url, $dedupeKey);
        }
    }
}
