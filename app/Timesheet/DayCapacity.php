<?php

declare(strict_types=1);

namespace App\Timesheet;

use App\Support\WorkWeek;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How full a timesheet day must be to count as complete, as a percentage.
 *
 * On a tenant with `tot_saturday` (Unijaya) the first Saturday of every month is the TOT
 * day and runs as a half day, so it asks for 50%, not 100%: the submit gate, the capture
 * screen's day dots and the generated holiday / leave rows all measure against this.
 * Whether the flag is on, and whether Saturday is a full work day instead, is
 * App\Support\WorkWeek's call.
 *
 * Days off are left at 100% — the capture screen's "Show weekend" toggle has always let
 * a staffer log a full Saturday, and nothing here changes that. Whether a day is asked
 * for at all is WorkWeek::capacity(), not this.
 */
final class DayCapacity
{
    /** The TOT Saturday's share of a normal day. */
    public const FIRST_SATURDAY_PERCENT = 50.0;

    /** True when $date is the first Saturday of its month (Unijaya's TOT day). */
    public static function isFirstSaturday(CarbonInterface|string $date): bool
    {
        $day = CarbonImmutable::parse($date);

        return $day->isSaturday() && $day->day <= 7;
    }

    /** The percentage $date must reach to count as full. */
    public static function for(CarbonInterface|string $date): float
    {
        return WorkWeek::for()->isTotDay(CarbonImmutable::parse($date)) ? self::FIRST_SATURDAY_PERCENT : 100.0;
    }
}
