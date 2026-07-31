<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeRequest extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ot_date' => 'date',
            'hours' => 'decimal:2',
            'rate_multiplier' => 'decimal:2',
            'decided_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The immediate superior who verified this request (step 1 of the approval gate). */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'verified_by_id');
    }

    /** Payable hours after the rate multiplier (e.g. 4h @ 1.5x = 6.00 equivalent hours). */
    protected function equivalentHours(): Attribute
    {
        return Attribute::get(fn () => round((float) $this->hours * (float) $this->rate_multiplier, 2));
    }
}
