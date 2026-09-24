<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off per-employee pay/deduction line against a Payroll Item catalogue entry,
 * queued for a single month — the client's HRMS calls this an "Individual Transaction"
 * (a recurring one is a "Fixed Transaction" — see FixedTransaction). Several rows per
 * employee per period are allowed: this is a list of one-offs, not one value.
 *
 * The single source of truth for one-off payslip lines — both queuing one directly
 * (PayrollController::storeIndividualTransaction) and adding one while editing a draft
 * payslip (updatePayslip) write into this same table, so the two entry points can never
 * disagree about what ends up on a payslip.
 */
class IndividualTransaction extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'payroll_item_id',
        'period',
        'for_bonus_run',
        'payroll_cycle',
        'amount',
        'remarks',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'for_bonus_run' => 'boolean',
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

    /** Individual Transactions queued for a given YYYY-MM period. */
    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('period', $period);
    }

    /**
     * Spec F10: the rows one kind of run may pull. A bonus run pays the flagged rows and
     * nothing else; a monthly run pays everything that is not flagged, so a bonus queued
     * for the month never lands on the monthly payslip by accident.
     */
    public function scopeForBonusRun(Builder $query, bool $forBonus): Builder
    {
        return $query->where('for_bonus_run', $forBonus);
    }
}
