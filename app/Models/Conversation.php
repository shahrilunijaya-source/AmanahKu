<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A 1-to-1 direct-message thread between two employees. Participants are stored
 * canonically (low id / high id) so a pair always resolves to a single row; the
 * unique index on (employee_low_id, employee_high_id) makes firstOrCreatePair
 * race-safe. tenant_id is auto-filled on create by BelongsToTenant.
 */
class Conversation extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Newest message — powers the conversation-list snippet without an N+1. */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function employeeLow(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_low_id');
    }

    public function employeeHigh(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_high_id');
    }

    /**
     * Find-or-create the single conversation for a pair. Ids are ordered low/high so
     * (a,b) and (b,a) map to the same row; the unique index makes the insert race-safe.
     * tenant_id is auto-filled by the BelongsToTenant creating hook.
     */
    public static function firstOrCreatePair(int $a, int $b): self
    {
        return static::firstOrCreate([
            'employee_low_id' => min($a, $b),
            'employee_high_id' => max($a, $b),
        ]);
    }

    /** The existing conversation for a pair, or null (no create). */
    public static function findPair(int $a, int $b): ?self
    {
        return static::where('employee_low_id', min($a, $b))
            ->where('employee_high_id', max($a, $b))
            ->first();
    }

    /** Whether $employeeId is one of the two participants. */
    public function hasParticipant(int $employeeId): bool
    {
        return $this->employee_low_id === $employeeId || $this->employee_high_id === $employeeId;
    }

    /** The participant id that is NOT the viewer. */
    public function otherId(int $viewerId): int
    {
        return $this->employee_low_id === $viewerId ? $this->employee_high_id : $this->employee_low_id;
    }

    /**
     * The other participant as a loaded Employee — WITHOUT the active() scope, so an
     * archived colleague still resolves their name/initials/avatar in an old thread.
     * Relies on employeeLow/employeeHigh being eager-loaded by the caller.
     */
    public function other(int $viewerId): ?Employee
    {
        return $this->employee_low_id === $viewerId ? $this->employeeHigh : $this->employeeLow;
    }
}
