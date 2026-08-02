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
     * A super-admin observer (inside a company they hold no membership in) is invisible
     * among the company's PEOPLE, so their READS record nothing — a support visit is not
     * the company's business, and that covers the GET payroll/report exports the read-only
     * gate lets through. Their WRITES are the opposite: the observer seat may only write
     * the org-setup routes listed in ReadOnlyObserver, and an invisible edit there would be
     * unattributable, which is the one thing an HR audit trail cannot survive. So a write
     * is always logged, marked "(platform support)" so HR reads it as an outside hand
     * rather than mistaking it for one of their own staff.
     *
     * This is the single choke-point for both halves — every call site routes through here.
     */
    public static function record(string $action, ?string $target = null): void
    {
        $tenant = app(CurrentTenant::class)->get();
        $user = Auth::user();
        $isObserver = $tenant && $user?->isObserverIn($tenant);

        // request() is a GET stub outside HTTP (console, queue), so non-request observer
        // activity keeps the old silent behaviour.
        if ($isObserver && request()->isMethodSafe()) {
            return;
        }

        static::create([
            'user_id' => Auth::id(),
            'actor_name' => $isObserver ? $user->name.' (platform support)' : ($user?->name ?? 'System'),
            'action' => $action,
            'target' => $target,
        ]);
    }
}
