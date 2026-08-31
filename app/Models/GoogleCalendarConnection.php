<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per user who has connected their Google account for calendar sync.
 * Not tenant-scoped: the Google account belongs to the person, not a tenant —
 * a user who belongs to multiple tenants still has exactly one connection.
 *
 * `expires_at` carries a `datetime` cast, so it reads back as a Carbon instance
 * rather than the raw string the schema reports — without this, static analysis
 * calls accessTokenFor()'s ->isAfter() a method call on a string.
 *
 * @property Carbon|null $expires_at
 */
class GoogleCalendarConnection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
