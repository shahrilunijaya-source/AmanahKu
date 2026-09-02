<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $date
 * @property int|null $filed_by_id
 */
class Claim extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'float', 'paid_at' => 'datetime', 'verified_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The immediate superior who verified this claim (step 1 of the approval gate). */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'verified_by_id');
    }

    /** Management who gave final approval (step 2). Null on anything decided before
     *  the 2026_09_02 decision-trail migration, which had nothing to record. */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by_id');
    }

    /** Whoever declined it — a verifier at step 1 or management at step 2. */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rejected_by_id');
    }
}
