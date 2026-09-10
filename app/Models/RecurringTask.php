<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * CR-18: a schedule that spawns one work card per period. The owner is a person or a
 * role (a Position title) resolved to its current holder each time a card is made, so
 * a departed owner never receives a new occurrence. Periods are counted from
 * `start_on`; the engine (`work:recurring`) makes the card for the latest period whose
 * creation day has come, on the first working day on or after it, and records every
 * period it touched in recurring_task_occurrences.
 *
 * @property Carbon $start_on
 * @property Carbon|null $paused_at
 * @property list<int>|null $tagged_employee_ids
 * @property list<string>|null $subtasks
 */
class RecurringTask extends Model
{
    use BelongsToTenant;

    public const FREQUENCIES = ['weekly', 'monthly', 'every_n_months', 'yearly'];

    public const LABEL = 'recurring';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_on' => 'date:Y-m-d',
            'paused_at' => 'datetime',
            'tagged_employee_ids' => 'array',
            'subtasks' => 'array',
            'interval' => 'integer',
            'lead_days' => 'integer',
            'min_attended' => 'integer',
        ];
    }

    /** @return HasMany<RecurringTaskOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(RecurringTaskOccurrence::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    /** The N-th period start, N = 0 being `start_on`. */
    public function periodStart(int $n): CarbonImmutable
    {
        $start = CarbonImmutable::parse($this->start_on->toDateString());
        $step = max(1, $this->interval) * $n;

        return match ($this->frequency) {
            'weekly' => $start->addWeeks($step),
            'yearly' => $start->addYearsNoOverflow($step),
            default => $start->addMonthsNoOverflow($step),
        };
    }

    /**
     * The latest period whose creation day (period start minus lead days) is on or
     * before the given day, or null when the schedule has not started. Earlier periods
     * the engine never made are left alone: a paused stretch is not back-filled.
     */
    public function latestPeriodDueOn(CarbonImmutable $day): ?CarbonImmutable
    {
        if ($this->periodStart(0)->subDays($this->lead_days)->gt($day)) {
            return null;
        }

        $n = 0;
        while ($this->periodStart($n + 1)->subDays($this->lead_days)->lte($day)) {
            $n++;
        }

        return $this->periodStart($n);
    }

    /**
     * When a period's card falls due: the end of its week, or the last day of the month
     * the period starts in ("due end of that month", CR-18 scope 2).
     */
    public function dueFor(CarbonImmutable $period): CarbonImmutable
    {
        return $this->frequency === 'weekly' ? $period->addDays(6) : $period->endOfMonth();
    }

    /**
     * Who owns the next card: the named person while they are still with the company,
     * else the current holder of the named position, else whoever set the schedule up.
     * Null when none of them is active any more.
     */
    public function resolveOwner(): ?Employee
    {
        $owner = $this->owner_employee_id ? Employee::active()->find($this->owner_employee_id) : null;
        if ($owner) {
            return $owner;
        }

        if ($this->owner_position_title) {
            $holder = Employee::active()
                ->where('tenant_id', $this->tenant_id)
                ->whereHas('positionBand', fn ($q) => $q->where('title', $this->owner_position_title))
                ->orderBy('id')
                ->first();
            if ($holder) {
                return $holder;
            }
        }

        return $this->created_by_employee_id ? Employee::active()->find($this->created_by_employee_id) : null;
    }

    /** Plain words for the list: "Every 2 months from 1 Nov 2026". */
    public function cadenceText(): string
    {
        $n = max(1, $this->interval);
        $unit = match ($this->frequency) {
            'weekly' => $n === 1 ? 'week' : $n.' weeks',
            'yearly' => $n === 1 ? 'year' : $n.' years',
            default => $n === 1 ? 'month' : $n.' months',
        };

        return 'Every '.$unit.' from '.$this->start_on->format('j M Y');
    }
}
