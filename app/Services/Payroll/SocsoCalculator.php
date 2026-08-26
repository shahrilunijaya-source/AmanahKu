<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * SOCSO contribution — PERKESO Third Schedule, Act 4 (see PerkesoSchedule).
 *
 * Category 1 (under 60): employer pays Employment Injury; employee pays Invalidity,
 * plus SKBBK ("Lindung 24 Jam") when opted in. Category 2 (60 and over): no Invalidity
 * scheme applies — employee pays SKBBK only if opted in, nothing otherwise.
 * SKBBK is voluntary since 8 July 2026 and entirely employee-paid. `employee` already
 * includes it; `skbbk` is the same amount broken out so the caller can show/record it
 * as its own payslip line (it is not part of the socso_employee column — see
 * PayrollCalculator). Employer share is unaffected by the opt-in in both categories.
 */
class SocsoCalculator
{
    /**
     * @return array{employee: float, employer: float, skbbk: float}
     */
    public function contribution(float $wages, int $category, bool $skbbkOptIn): array
    {
        $wages = round(max(0.0, $wages), 2);
        if ($wages <= 0.0) {
            return ['employee' => 0.0, 'employer' => 0.0, 'skbbk' => 0.0];
        }

        $row = PerkesoSchedule::socsoRow(min($wages, PerkesoSchedule::WAGE_CEILING));

        if ($category >= 2) {
            $skbbk = $skbbkOptIn ? $row['c2_employee_skbbk'] : 0.0;

            return ['employee' => $skbbk, 'employer' => $row['c2_employer'], 'skbbk' => $skbbk];
        }

        $skbbk = $skbbkOptIn ? $row['c1_employee_skbbk'] : 0.0;

        return [
            'employee' => round($row['c1_employee_invalidity'] + $skbbk, 2),
            'employer' => $row['c1_employer'],
            'skbbk' => $skbbk,
        ];
    }
}
