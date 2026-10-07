<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Models\WorkDayRule;
use App\Tenancy\CurrentTenant;
use App\Timesheet\DayCapacity;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

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

    /** @var Collection<int, WorkDayRule>|null */
    private ?Collection $rules = null;

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

    /**
     * The company's special work day rule that covers this date, if any. A rule outranks
     * everything else: it makes the day a working day and sets its capacity and hours.
     */
    public function specialRule(CarbonInterface $day): ?WorkDayRule
    {
        // Memoised per instance. An unsaved in-memory tenant has no id, so no rules.
        $this->rules ??= $this->tenant->getKey()
            ? WorkDayRule::withoutGlobalScopes()->where('tenant_id', $this->tenant->getKey())->get()
            : new Collection;

        return $this->rules->first(fn (WorkDayRule $rule) => $rule->matches($day));
    }

    /** A rule day, a listed work day, or the legacy TOT half day. Holidays are the caller's job. */
    public function isWorkingDay(CarbonInterface $day): bool
    {
        return $this->specialRule($day) !== null || $this->isListed($day) || $this->isLegacyTotDay($day);
    }

    /** The half day: a matching half rule, or the legacy TOT Saturday. Name kept for existing callers. */
    public function isTotDay(CarbonInterface $day): bool
    {
        return $this->isHalfDay($day);
    }

    /** A half-capacity working day (rule with counts = half, or the legacy first-Saturday TOT). */
    public function isHalfDay(CarbonInterface $day): bool
    {
        $rule = $this->specialRule($day);

        return $rule !== null ? $rule->capacity() === 50 : $this->isLegacyTotDay($day);
    }

    /** 100 on a full work day, 50 on a half day, 0 on a day off. A matching rule wins. */
    public function capacity(CarbonInterface $day): int
    {
        if ($rule = $this->specialRule($day)) {
            return $rule->capacity();
        }

        if ($this->isListed($day)) {
            return 100;
        }

        return $this->isLegacyTotDay($day) ? 50 : 0;
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

    /** Flag on, first Saturday of the month, and Saturday not already a full work day. */
    private function isLegacyTotDay(CarbonInterface $day): bool
    {
        // ponytail: legacy tot_saturday fallback, drop the column once nothing sets it
        return $this->totSaturday()
            && DayCapacity::isFirstSaturday($day)
            && ! in_array(6, $this->workingDays(), true);
    }
}
