<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A licence / certification / work permit held by an employee, tracked for expiry.
 * Status is derived from expires_at — nothing is stored, so it can never drift.
 */
class ComplianceItem extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_at' => 'date', 'expires_at' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Days until expiry — negative once expired. */
    public function getDaysToExpiryAttribute(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false);
    }

    /** Bucket the item for the expiry dashboard. */
    public function getExpiryBucketAttribute(): string
    {
        $days = $this->days_to_expiry;

        return match (true) {
            $days < 0 => 'expired',
            $days <= 30 => '30',
            $days <= 60 => '60',
            $days <= 90 => '90',
            default => 'valid',
        };
    }

    /** Semantic colour token for the status pill. */
    public function getExpiryColorAttribute(): string
    {
        return match ($this->expiry_bucket) {
            'expired', '30' => 'error',
            '60' => 'amber',
            default => 'green',
        };
    }
}
