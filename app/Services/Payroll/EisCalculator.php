<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * EIS / SIP contribution — PERKESO Second Schedule, Act 800 (see PerkesoSchedule).
 * Category 2 (60 and over) is not covered by EIS at all — both sides are zero.
 */
class EisCalculator
{
    /**
     * @return array{employee: float, employer: float}
     */
    public function contribution(float $wages, int $category): array
    {
        if ($category >= 2) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        $wages = round(max(0.0, $wages), 2);
        if ($wages <= 0.0) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        $row = PerkesoSchedule::eisRow(min($wages, PerkesoSchedule::WAGE_CEILING));

        return ['employee' => $row['employee'], 'employer' => $row['employer']];
    }
}
