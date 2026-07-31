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
     * A super-admin observer (inside a company they hold no membership in) records nothing:
     * their seat is read-only and invisible, so the company's audit trail must not carry a
     * name that is not one of its people. This is the single choke-point for that — every
     * call site across the app routes through here, including the three GET payroll/report
     * exports that the read-only gate lets through as reads.
     */
    public static function record(string $action, ?string $target = null): void
    {
        $tenant = app(CurrentTenant::class)->get();

        if ($tenant && Auth::user()?->isObserverIn($tenant)) {
            return;
        }

        static::create([
            'user_id' => Auth::id(),
            'actor_name' => Auth::user()?->name ?? 'System',
            'action' => $action,
            'target' => $target,
        ]);
    }
}
