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

    /** Queue an in-app notification for a single user (tenant auto-filled). */
    public static function send(?int $userId, string $title, ?string $body = null, ?string $url = null): void
    {
        if (! $userId) {
            return;
        }

        static::create(['user_id' => $userId, 'title' => $title, 'body' => $body, 'url' => $url]);
    }

    /** @param iterable<int> $userIds */
    public static function sendMany(iterable $userIds, string $title, ?string $body = null, ?string $url = null): void
    {
        foreach ($userIds as $id) {
            static::send($id, $title, $body, $url);
        }
    }
}
