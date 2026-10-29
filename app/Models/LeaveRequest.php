<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\WorkWeek;
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
     * Working days between $from and $to inclusive, per the tenant's work week: a listed
     * work day costs 1, the TOT Saturday (Unijaya's first-Saturday half day) 0.5, and days
     * off and the tenant's public holidays cost nothing. With $calendarDays (maternity),
     * every day in the range costs 1, weekends and public holidays included.
     */
    public static function countDays(Carbon $from, Carbon $to, bool $calendarDays = false): float
    {
        if ($calendarDays) {
            return (float) ($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);
        }

        return (float) array_sum(self::dayFractions($from, $to));
    }

    /**
     * What a whole-day leave costs on each date from $from to $to, listing only the dates
     * that cost something: 1.0 on a work day, 0.5 on a half day. Days off and the tenant's
     * public holidays are left out. The leave form previews from this map, so the browser
     * and countDays() can never disagree.
     *
     * @return array<string, float> Y-m-d => fraction
     */
    public static function dayFractions(Carbon $from, Carbon $to): array
    {
        $holidays = PublicHoliday::whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->pluck('date')
            ->map(fn (Carbon $d) => $d->toDateString())
            ->flip();

        $workWeek = WorkWeek::for();

        $fractions = [];
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $fraction = $holidays->has($date->toDateString()) ? 0.0 : $workWeek->dayFraction($date);
            if ($fraction > 0) {
                $fractions[$date->toDateString()] = $fraction;
            }
        }

        return $fractions;
    }
}
