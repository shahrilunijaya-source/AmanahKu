<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLog extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /**
     * Record an audit entry for the current actor + active tenant.
     * tenant_id is auto-filled by the BelongsToTenant trait.
     */
    public static function record(string $action, ?string $target = null): void
    {
        static::create([
            'user_id' => Auth::id(),
            'actor_name' => Auth::user()?->name ?? 'System',
            'action' => $action,
            'target' => $target,
        ]);
    }
}
