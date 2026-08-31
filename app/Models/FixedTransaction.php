<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring per-employee pay/deduction line against a Payroll Item catalogue entry —
 * the client's HRMS calls this a "Fixed Transaction" (a one-off is an "Individual
 * Transaction", a later pass). Applies to every payroll run whose period falls inside
 * [start_period, end_period] (end null = open-ended). Ending one sets end_period rather
 * than deleting the row, so payroll history stays explicable.
 */
class FixedTransaction extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'payroll_item_id',
        'amount',
        'start_period',
        'end_period',
        'last_amount',
        'prorate',
        'remarks',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'last_amount' => 'float',
            'prorate' => 'boolean',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<PayrollItem, $this> */
    public function payrollItem(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class);
    }

    /** Fixed Transactions covering a given YYYY-MM period (inclusive range, null end = open). */
    public function scopeActiveDuring(Builder $query, string $period): Builder
    {
        return $query->where('start_period', '<=', $period)
            ->where(fn (Builder $q) => $q->whereNull('end_period')->orWhere('end_period', '>=', $period));
    }
}
