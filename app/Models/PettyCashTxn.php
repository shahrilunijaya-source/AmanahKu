<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashTxn extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'txn_date' => 'date',
        ];
    }

    public function float(): BelongsTo
    {
        return $this->belongsTo(PettyCashFloat::class, 'petty_cash_float_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recorded_by_id');
    }
}
