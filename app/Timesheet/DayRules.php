<?php

declare(strict_types=1);

namespace App\Timesheet;

use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Working-day arithmetic for CR-03: the per-day submit deadline (10:00 the next
 * working day) and the 3-working-day backdate edit window, plus the line-signature
 * comparison WeekWriter uses to tell whether a frozen day's grid actually changed.
 *
 * "Working day" here is Mon-Fri plus the first Saturday of the month (the TOT half
 * day, see DayCapacity::isFirstSaturday), minus the active tenant's public holidays
 * — the same definition LockedDays::workingDays() uses structurally, narrowed by the
 * holiday calendar because a deadline or an edit window has to land on a day someone
 * could actually be expected to act on.
 */
final class DayRules
{
    /** True when $day is a working day: Mon-Fri or the TOT Saturday, and not a public holiday. */
    public function isWorkingDay(CarbonInterface $day): bool
    {
        $day = CarbonImmutable::parse($day);

        if ($day->isSunday()) {
            return false;
        }

        if ($day->isSaturday() && ! DayCapacity::isFirstSaturday($day)) {
            return false;
        }

        return ! $this->isHoliday($day);
    }

    /** The next working day after $day, at config('manday.day_submit_deadline'). */
    public function deadlineFor(CarbonInterface $day): CarbonImmutable
    {
        $next = CarbonImmutable::parse($day)->startOfDay()->addDay();

        while (! $this->isWorkingDay($next)) {
            $next = $next->addDay();
        }

        [$hour, $minute] = array_pad(explode(':', (string) config('manday.day_submit_deadline', '10:00')), 2, 0);

        return $next->setTime((int) $hour, (int) $minute);
    }

    /**
     * Step back `manday.edit_window_working_days` working days from $today. The
     * earliest date still editable without a manager unlock — a date strictly before
     * this is frozen by the backdate window.
     */
    public function earliestEditable(CarbonInterface $today): CarbonImmutable
    {
        $window = (int) config('manday.edit_window_working_days', 3);
        $day = CarbonImmutable::parse($today)->startOfDay();

        for ($i = 0; $i < $window; $i++) {
            do {
                $day = $day->subDay();
            } while (! $this->isWorkingDay($day));
        }

        return $day;
    }

    /**
     * Structural working days of the week starting $weekStart: Mon-Fri plus the TOT
     * Saturday, holidays included (a holiday is excluded later by the "fully locked"
     * check, not here — this is the same day set LockedDays::workingDays() walks).
     *
     * @return list<string> ISO dates
     */
    public function weekWorkingDays(CarbonInterface|string $weekStart): array
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();

        $days = [];
        for ($i = 0; $i < 6; $i++) {
            $day = $start->addDays($i);
            if ($i < 5 || DayCapacity::isFirstSaturday($day)) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }

    /**
     * Normalised, order-independent signature of a day's user-typed lines, for
     * comparing a frozen day's stored lines against what the grid resent. Two lists
     * with the same lines in a different order compare equal; a changed percentage,
     * a dropped line, or an added one does not.
     *
     * @param  array<int, array{category_id:int, project_id?:?int, sub_pillar_id?:?int, percentage:float|int|string}>  $lines
     */
    public function lineSignature(array $lines): string
    {
        $normalised = array_map(fn (array $l) => [
            'category_id' => (int) $l['category_id'],
            'project_id' => isset($l['project_id']) ? (int) $l['project_id'] : null,
            'sub_pillar_id' => isset($l['sub_pillar_id']) ? (int) $l['sub_pillar_id'] : null,
            'percentage' => round((float) $l['percentage'], 2),
        ], $lines);

        sort($normalised);

        return json_encode($normalised) ?: '[]';
    }

    private function isHoliday(CarbonInterface $day): bool
    {
        return PublicHoliday::whereDate('date', CarbonImmutable::parse($day)->toDateString())->exists();
    }
}
