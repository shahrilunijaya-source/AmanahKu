<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashFloat extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'custodian_employee_id');
    }

    public function txns(): HasMany
    {
        return $this->hasMany(PettyCashTxn::class);
    }

    /**
     * Re-derive the running balance from the float's transactions and persist it.
     * Disbursements reduce the float; replenishments top it up. The txns query
     * inherits the active tenant scope via the BelongsToTenant trait.
     */
    public function recomputeBalance(): void
    {
        $disbursed = (float) $this->txns()->where('type', 'disbursement')->sum('amount');
        $replenished = (float) $this->txns()->where('type', 'replenishment')->sum('amount');

        $this->update(['balance' => (float) $this->opening_balance + $replenished - $disbursed]);
    }
}
