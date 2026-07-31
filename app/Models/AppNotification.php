<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Notifications\AppNotificationMail;
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
     * Pass $mail to also email a copy. Opt-in on purpose: only the bells a member must
     * not miss while away from the app carry it (clock-in/out, task assignment,
     * timesheet). Everything else stays in-app so the inbox does not become noise.
     *
     * @return bool True when a row was created, false when suppressed.
     */
    public static function send(?int $userId, string $title, ?string $body = null, ?string $url = null, ?string $dedupeKey = null, bool $mail = false): bool
    {
        if (! $userId) {
            return false;
        }

        $attributes = ['user_id' => $userId, 'title' => $title, 'body' => $body, 'url' => $url];

        if ($dedupeKey === null) {
            static::create($attributes);
            $created = true;
        } else {
            $created = static::firstOrCreate(
                ['user_id' => $userId, 'dedupe_key' => $dedupeKey],
                $attributes,
            )->wasRecentlyCreated;
        }

        // Only a fresh row mails: a deduped repeat tick stays a no-op in the inbox too.
        if ($mail && $created) {
            User::find($userId)?->notify(new AppNotificationMail($title, $body, $url));
        }

        return $created;
    }

    /** @param iterable<int> $userIds */
    public static function sendMany(iterable $userIds, string $title, ?string $body = null, ?string $url = null, ?string $dedupeKey = null, bool $mail = false): void
    {
        foreach ($userIds as $id) {
            static::send($id, $title, $body, $url, $dedupeKey, $mail);
        }
    }
}
