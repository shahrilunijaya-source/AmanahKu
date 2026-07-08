<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entitlement' => 'float',
            'requires_attachment' => 'boolean',
            'is_unplanned' => 'boolean',
            'min_notice_days' => 'integer',
            'monthly_accrual_days' => 'float',
            'max_balance' => 'float',
            'max_carry_forward' => 'float',
        ];
    }

    /**
     * A leave type accrues monthly when it grants more than zero days per month.
     */
    public function accrues(): bool
    {
        return (float) $this->monthly_accrual_days > 0;
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    /** The type whose balance this one draws down (e.g. Emergency → Annual), if any. */
    public function deductsFrom(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'deducts_from_leave_type_id');
    }

    /**
     * Which balance an approval of this type should decrement: its own, unless it is
     * configured to draw down another type (emergency leave spends the annual balance).
     */
    public function effectiveBalanceTypeId(): int
    {
        return $this->deducts_from_leave_type_id ?? $this->id;
    }
}
