<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Timesheet\DayCapacity;
use App\Timesheet\DayRules;
use App\Timesheet\LockedDays;
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

    /** @return HasMany<TimesheetDay, $this> */
    public function days(): HasMany
    {
        return $this->hasMany(TimesheetDay::class);
    }

    /**
     * Derive and persist the week-level status from its per-day rows (CR-03): 'submitted'
     * once every working day that isn't fully locked by leave/holiday is submitted or
     * approved (submitted_at = the latest day's), 'approved' once all of those are
     * approved, else 'draft'. Called after every day action so the week-level status a
     * report or the roster reads never drifts from the days that actually back it.
     */
    public function refreshStatusFromDays(): void
    {
        $locked = app(LockedDays::class)->forWeek($this->employee, $this->week_start);
        $candidates = array_filter(
            (new DayRules)->weekWorkingDays($this->week_start),
            fn (string $iso) => ! (($locked[$iso]['percentage'] ?? 0) >= DayCapacity::for($iso)),
        );

        if ($candidates === []) {
            return;
        }

        $days = $this->days()->whereIn('entry_date', $candidates)->get()->keyBy(fn (TimesheetDay $d) => $d->entry_date->toDateString());

        $allApproved = true;
        $allSubmittedOrApproved = true;
        $latestSubmittedAt = null;
        foreach ($candidates as $iso) {
            $day = $days->get($iso);
            $status = $day?->status;

            if ($status !== TimesheetDay::STATUS_APPROVED) {
                $allApproved = false;
            }
            if (! in_array($status, [TimesheetDay::STATUS_SUBMITTED, TimesheetDay::STATUS_APPROVED], true)) {
                $allSubmittedOrApproved = false;
            }
            if ($day?->submitted_at !== null && ($latestSubmittedAt === null || $day->submitted_at->greaterThan($latestSubmittedAt))) {
                $latestSubmittedAt = $day->submitted_at;
            }
        }

        $status = $allApproved ? 'approved' : ($allSubmittedOrApproved ? 'submitted' : 'draft');

        $this->forceFill(['status' => $status, 'submitted_at' => $allSubmittedOrApproved ? $latestSubmittedAt : null])->save();
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
