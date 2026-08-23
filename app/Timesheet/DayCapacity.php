<?php

declare(strict_types=1);

namespace App\Timesheet;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How much of a timesheet day is fillable, as a percentage.
 *
 * Unijaya's working week is Mon–Fri plus the first Saturday of every month, which is the
 * TOT day and runs as a half day. That Saturday therefore asks for 50%, not 100%: the
 * submit gate, the capture screen's day dots and the generated holiday / leave rows all
 * measure against this, so "full" means 50% there and 100% everywhere else.
 *
 * Ordinary Saturdays are left at 100% — the capture screen's "Show weekend" toggle has
 * always let a staffer log a full Saturday, and nothing here changes that.
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
        return self::isFirstSaturday($date) ? self::FIRST_SATURDAY_PERCENT : 100.0;
    }
}
