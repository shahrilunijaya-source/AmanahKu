<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use App\Timesheet\DayCapacity;
use Carbon\CarbonInterface;

/**
 * The company's working week: which ISO weekdays (1 = Monday .. 7 = Sunday) are working
 * days, plus Unijaya's TOT rule (the first Saturday of the month is a half day) on tenants
 * with `tot_saturday` set. A Saturday listed in `work_days` is a full day and outranks TOT.
 *
 * Public holidays are not this class's business: every caller that already checks the
 * holiday table keeps doing so after asking here, so a holiday on a non-work day changes
 * nothing.
 *
 * Console and queue paths sometimes run with no tenant bound; for(null) then falls back to
 * Monday to Friday with no TOT so nothing throws.
 */
final class WorkWeek
{
    /** @var list<int> */
    public const DEFAULT_DAYS = [1, 2, 3, 4, 5];

    public function __construct(private readonly Tenant $tenant) {}

    public static function for(?Tenant $tenant = null): self
    {
        $tenant ??= app(CurrentTenant::class)->get()
            ?? new Tenant(['work_days' => self::DEFAULT_DAYS, 'tot_saturday' => false]);

        return new self($tenant);
    }

    /**
     * The listed working days, ascending. A tenant row created in memory before the DB
     * default is read back has null here, hence the fallback.
     *
     * @return list<int>
     */
    public function workingDays(): array
    {
        $days = array_map('intval', (array) ($this->tenant->work_days ?? self::DEFAULT_DAYS));

        sort($days);

        return array_values(array_unique($days));
    }

    public function totSaturday(): bool
    {
        return (bool) $this->tenant->tot_saturday;
    }

    /** A listed work day, or the TOT half day. Holidays are the caller's job. */
    public function isWorkingDay(CarbonInterface $day): bool
    {
        return $this->isListed($day) || $this->isTotDay($day);
    }

    /** The TOT half day: flag on, first Saturday of the month, and Saturday not already a full work day. */
    public function isTotDay(CarbonInterface $day): bool
    {
        return $this->totSaturday()
            && DayCapacity::isFirstSaturday($day)
            && ! in_array(6, $this->workingDays(), true);
    }

    /** 100 on a listed work day, 50 on the TOT half day, 0 on a day off. */
    public function capacity(CarbonInterface $day): int
    {
        if ($this->isListed($day)) {
            return 100;
        }

        return $this->isTotDay($day) ? 50 : 0;
    }

    /** The share of a leave day this date costs: 1.0, 0.5 or 0.0. */
    public function dayFraction(CarbonInterface $day): float
    {
        return $this->capacity($day) / 100;
    }

    private function isListed(CarbonInterface $day): bool
    {
        return in_array((int) $day->dayOfWeekIso, $this->workingDays(), true);
    }
}
