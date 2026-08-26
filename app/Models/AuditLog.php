<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLog extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /**
     * Record an audit entry for the current actor + active tenant.
     * tenant_id is auto-filled by the BelongsToTenant trait.
     *
     * A super-admin observer (inside a company they hold no membership in) records NOTHING
     * — not their reads, not their writes. It is a developer/support seat: the company must
     * not see that it exists, so it leaves no line in their ledger the way it leaves no row
     * in their staff list. The cost is deliberate: an edit made from that seat is
     * unattributable in-app, so platform-side logs are the only trace.
     *
     * This is the single choke-point — every call site routes through here.
     */
    public static function record(string $action, ?string $target = null): void
    {
        $tenant = app(CurrentTenant::class)->get();
        $user = Auth::user();

        if ($tenant && $user?->isObserverIn($tenant)) {
            return;
        }

        // target is a varchar(255) and callers build it from user-supplied names. An
        // over-long value used to throw mid-write; a trimmed audit line is always better
        // than a failed action (a migration deploy died this way once).
        static::create([
            'user_id' => Auth::id(),
            'actor_name' => $user?->name ?? 'System',
            'action' => mb_substr($action, 0, 255),
            'target' => $target === null ? null : mb_substr($target, 0, 255),
        ]);
    }
}
