<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Claim extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'float', 'paid_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The immediate superior who verified this claim (step 1 of the approval gate). */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'verified_by_id');
    }
}
