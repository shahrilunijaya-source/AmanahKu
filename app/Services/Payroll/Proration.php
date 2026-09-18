<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Incomplete-month wages, Employment Act s.18A: monthly wages ÷ days in that month ×
 * days employed. Calendar days, real month length. This is deliberately a different
 * divisor from the 26-day ordinary rate PayrollCalculator uses for unpaid leave and
 * overtime (s.60I); see spec section 7.
 */
final class Proration
{
    /** @return array{employed: int, in_month: int} */
    public static function days(string $period, ?CarbonInterface $joinedAt, ?CarbonInterface $lastWorkingDay): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        $end = $start->endOfMonth()->startOfDay();
        $inMonth = $end->day;

        $from = $joinedAt !== null && $joinedAt->gt($start) ? CarbonImmutable::instance($joinedAt)->startOfDay() : $start;
        $to = $lastWorkingDay !== null && $lastWorkingDay->lt($end) ? CarbonImmutable::instance($lastWorkingDay)->startOfDay() : $end;

        if ($from->gt($end) || $to->lt($start) || $from->gt($to)) {
            return ['employed' => 0, 'in_month' => $inMonth];
        }

        return ['employed' => $to->day - $from->day + 1, 'in_month' => $inMonth];
    }

    /** Monthly wage split over the real days of the month, s.18A. */
    public static function prorate(float $monthly, int $employed, int $inMonth): float
    {
        if ($inMonth <= 0) {
            return 0.0;
        }

        return round($monthly / $inMonth * min($employed, $inMonth), 2);
    }
}
