<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide feature default + lock, set by super-admins. Global (not
 * tenant-scoped) — deliberately does NOT use BelongsToTenant.
 */
class PlatformFeature extends Model
{
    protected $fillable = ['key', 'value', 'locked'];

    protected function casts(): array
    {
        return ['locked' => 'boolean'];
    }
}
