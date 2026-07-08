<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseReport extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class);
    }

    /**
     * Re-sum the report total from its lines and persist it. The lines query
     * inherits the active tenant scope via the BelongsToTenant trait.
     */
    public function recomputeTotal(): void
    {
        $this->update(['total' => (float) $this->lines()->sum('amount')]);
    }
}
