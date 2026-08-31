<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * EPF / KWSP contribution amounts — Third Schedule, EPF Act 1991 (effective 1 October 2025).
 *
 * Source PDF: docs/statutory/ (KWSP "Effective 1 October 2025"). Contributions below
 * RM20,000 a month are NOT a straight percentage: the schedule fixes a ringgit amount per
 * wage band. The published amounts are exactly each side's percentage applied to the band's
 * upper limit, rounded UP to the next ringgit — verified row by row against all 1,203
 * official rows in tests/Fixtures/epf-third-schedule-2025-10.csv, so the bands are computed
 * rather than transcribed. Change nothing here without re-running EpfCalculatorTest.
 *
 * Parts (B and D were deleted by Act A1760/2025):
 *   A — citizens, permanent residents, and non-citizens who elected before 1 Aug 1998, under 60.
 *   C — permanent residents and pre-1998 electors, 60 and over.
 *   E — Malaysian citizens, 60 and over.
 *   F — other non-citizens (mandatory since 1 Oct 2025): a flat 2% each side of actual wages.
 */
class EpfCalculator
{
    public const SCHEDULE_EFFECTIVE = '2025-10-01';

    /** Wages at or below this are not contributable at all (first row of every table). */
    private const NIL_WAGE = 10.0;

    /** Above this monthly wage the schedule stops and exact percentages apply. */
    private const SCHEDULE_CEILING = 20000.0;

    /** The employer's rate steps down above this wage. */
    private const EMPLOYER_RATE_THRESHOLD = 5000.0;

    /** Contributions cease once the employee turns 75. */
    public const MAX_CONTRIBUTING_AGE = 75;

    /**
     * Percentages per part: [employer at/below RM5,000, employer above, employee].
     *
     * @var array<string, array{0: float, 1: float, 2: float}>
     */
    private const RATES = [
        'A' => [13.0, 12.0, 11.0],
        'C' => [6.5, 6.0, 5.5],
        'E' => [4.0, 4.0, 0.0],
        'F' => [2.0, 2.0, 2.0],
    ];

    /**
     * Which part of the schedule an employee falls under.
     *
     * @param  string  $nationality  citizen | pr | foreign
     * @param  bool  $electedBefore1998  Non-citizen who elected to contribute before 1 Aug 1998.
     * @return string|null Part letter, or null when no contribution is due (age 75+).
     */
    public function part(string $nationality, ?int $age, bool $electedBefore1998 = false): ?string
    {
        if ($age !== null && $age >= self::MAX_CONTRIBUTING_AGE) {
            return null;
        }

        $under60 = $age === null || $age < 60;

        if ($nationality === 'foreign' && ! $electedBefore1998) {
            return 'F';
        }

        if ($under60) {
            return 'A';
        }

        return $nationality === 'citizen' ? 'E' : 'C';
    }

    /**
     * Contribution for one month's wages.
     *
     * @param  string|null  $part  Part letter from part(); null means no contribution.
     * @return array{employee: float, employer: float}
     */
    public function contribution(float $wages, ?string $part): array
    {
        $wages = round(max(0.0, $wages), 2);

        if ($part === null || ! isset(self::RATES[$part]) || $wages <= self::NIL_WAGE) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        // Part F is a plain percentage of actual wages at every wage level — it has no bands.
        $base = $part === 'F' ? $wages : $this->bandUpperLimit($wages);
        [$employerBelow, $employerAbove, $employeePct] = self::RATES[$part];
        $employerPct = $base <= self::EMPLOYER_RATE_THRESHOLD ? $employerBelow : $employerAbove;

        return [
            'employee' => $this->ceilToRinggit($base * $employeePct / 100),
            'employer' => $this->ceilToRinggit($base * $employerPct / 100),
        ];
    }

    /**
     * The wage figure the schedule contributes on: the top of the band the wages fall in
     * (RM20 bands up to RM5,000, RM100 bands to RM20,000), or the actual wages above that.
     */
    private function bandUpperLimit(float $wages): float
    {
        if ($wages > self::SCHEDULE_CEILING) {
            return $wages;
        }

        if ($wages <= self::EMPLOYER_RATE_THRESHOLD) {
            return ceil($wages / 20) * 20;
        }

        return self::EMPLOYER_RATE_THRESHOLD
            + ceil(($wages - self::EMPLOYER_RATE_THRESHOLD) / 100) * 100;
    }

    /** Every published amount is whole ringgit, rounded up. */
    private function ceilToRinggit(float $amount): float
    {
        return (float) ceil(round($amount, 6));
    }
}
