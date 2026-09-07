<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\AuditContext;
use App\Tenancy\CurrentTenant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('audit_logs rows are append-only');
        });

        static::deleting(function () {
            throw new \RuntimeException('audit_logs rows are append-only');
        });
    }

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
        if (self::skipForObserver()) {
            return;
        }

        // target is a varchar(255) and callers build it from user-supplied names. An
        // over-long value used to throw mid-write; a trimmed audit line is always better
        // than a failed action (a migration deploy died this way once).
        static::create([
            'user_id' => Auth::id(),
            'actor_name' => self::actorName(),
            'action' => mb_substr($action, 0, 255),
            'target' => $target === null ? null : mb_substr($target, 0, 255),
            'source' => AuditContext::source(),
        ]);
    }

    /**
     * Record a single field change on a model. One row per field — see
     * App\Models\Concerns\AuditsChanges, which calls this once per audited field
     * on create/update/delete.
     */
    public static function change(Model $subject, string $field, mixed $old, mixed $new, ?string $reason = null, ?string $source = null): void
    {
        if (self::skipForObserver()) {
            return;
        }

        $shortName = Str::snake(class_basename($subject));

        static::create([
            'tenant_id' => $subject->tenant_id ?? app(CurrentTenant::class)->id(),
            'user_id' => Auth::id(),
            'actor_name' => self::actorName(),
            'action' => $shortName.'.'.$field,
            'target' => self::targetLabel($subject),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'field' => $field,
            'old_value' => json_encode(self::serializeValue($old)),
            'new_value' => json_encode(self::serializeValue($new)),
            'reason' => $reason ?? AuditContext::reason(),
            'source' => $source ?? AuditContext::source(),
        ]);
    }

    private static function actorName(): string
    {
        return Auth::user()?->name ?? 'System';
    }

    private static function skipForObserver(): bool
    {
        $tenant = app(CurrentTenant::class)->get();
        $user = Auth::user();

        return (bool) ($tenant && $user?->isObserverIn($tenant));
    }

    /** A short label for the changed row: its title/name if it has one, else the id. */
    /** Human form of a stored JSON value for the Audit Logs screen. */
    public function displayValue(string $column): string
    {
        $value = json_decode((string) $this->{$column}, true);

        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value)) ?: '[]',
            default => (string) $value,
        };
    }

    private static function targetLabel(Model $subject): string
    {
        foreach (['title', 'name'] as $attribute) {
            if (filled($subject->{$attribute} ?? null)) {
                return mb_substr((string) $subject->{$attribute}, 0, 255);
            }
        }

        return (string) $subject->getKey();
    }

    private static function serializeValue(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
    }
}
