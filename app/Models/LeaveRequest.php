<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Timesheet\DayCapacity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $date_from
 * @property Carbon $date_to
 * @property int|null $verified_by_id
 * @property int|null $filed_by_id
 * @property Carbon|null $verified_at
 */
class LeaveRequest extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date_from' => 'date', 'date_to' => 'date', 'days' => 'float',
            'verified_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The immediate superior who verified this request (step 1 of the approval gate). */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'verified_by_id');
    }

    /** Management who gave final approval (step 2). */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by_id');
    }

    /** Whoever declined the request (superior at step 1, or management at step 2). */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rejected_by_id');
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /** True when this request covers only half a day (morning or afternoon). */
    public function isHalfDay(): bool
    {
        return $this->half_day_period !== null;
    }

    /**
     * Working days between $from and $to inclusive. Unijaya works Mon–Fri plus the TOT
     * Saturday (the first Saturday of the month, a half day, counted 0.5); Sundays and
     * ordinary Saturdays are not working days and cost nothing.
     */
    public static function countDays(Carbon $from, Carbon $to): float
    {
        $days = 0.0;
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            if (DayCapacity::isFirstSaturday($date)) {
                $days += 0.5;
            } elseif ($date->isWeekday()) {
                $days += 1.0;
            }
        }

        return $days;
    }
}
