<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Timesheet\DayCapacity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * `week_start` has a `date` cast over a NOT NULL column, so it always reads back as
 * a Carbon instance. Without this, static analysis takes the raw column type and
 * reports ->toDateString() as a call on a string.
 *
 * `dismissed_suggestions` is a json column with an `array` cast: the board cards the
 * staffer struck off each day of the week, keyed by ISO date.
 *
 * @property Carbon $week_start
 * @property array<string, array<int, int>>|null $dismissed_suggestions
 */
class Timesheet extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /**
     * Rows whose week_start falls on the given day. Sargable range instead of
     * whereDate(): DATE() around the column defeats the (employee_id, week_start)
     * unique index in MySQL, while sqlite stores date casts with a 00:00:00 time
     * part that a plain where() equality would miss.
     */
    public function scopeForWeek(Builder $query, CarbonInterface|string $weekStart): Builder
    {
        $day = CarbonImmutable::parse($weekStart);

        return $query->where('week_start', '>=', $day->toDateString())
            ->where('week_start', '<', $day->addDay()->toDateString());
    }

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'total_hours' => 'decimal:2',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'dismissed_suggestions' => 'array',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<TimesheetEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class);
    }

    /**
     * Re-sum the timesheet's total hours from its entries and persist it. The
     * entries query inherits the active tenant scope via the BelongsToTenant trait.
     */
    public function recomputeTotal(): void
    {
        $this->update(['total_hours' => (float) $this->entries()->sum('hours')]);
    }

    /**
     * A week's cutoff: Friday, unless that week's Saturday is the first Saturday of the
     * month (Unijaya's TOT day, a work half-day), which pushes the cutoff there. Single
     * source of truth for TimesheetController's submit gate (both the capture screen's
     * submit_now and the Review tab's plain-form submit) — mirrors weekEndsOn() in
     * resources/js/timesheet-capture.js for the capture screen's own button state.
     */
    public static function computeWeekEndsOn(CarbonInterface $weekStart): Carbon
    {
        $saturday = Carbon::parse($weekStart)->addDays(5);

        return DayCapacity::isFirstSaturday($saturday) ? $saturday : Carbon::parse($weekStart)->addDays(4);
    }
}
