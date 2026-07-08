<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * PERKESO contribution bracket tables (Jadual Caruman) — SOCSO + EIS.
 *
 * ⚠️ PLACEHOLDER DATA. The bands below are GENERATED (band-midpoint × statutory rate),
 * NOT the official published amounts. They give the correct *mechanism* (stepped bands,
 * category split, ceiling clamp) and a realistic approximation, but the exact ringgit
 * figures MUST be replaced with the official PERKESO Jadual Caruman before any real
 * statutory filing:
 *   - SOCSO  → Third Schedule, Act 4
 *   - EIS    → Second Schedule, Act 800 (SIP)
 *   Source: https://www.perkeso.gov.my/en/rate-of-contribution.html (schedule eff. 2024-10-01)
 *
 * To make exact: set IS_PLACEHOLDER = false and replace bands() with the transcribed
 * per-band tables (SOCSO_CAT1, SOCSO_CAT2, EIS as explicit arrays), then flip the
 * skipped reference-row assertion in StatutoryBracketsTest.
 */
final class StatutoryBrackets
{
    /** PERKESO contribution schedule this table represents. */
    public const SCHEDULE_EFFECTIVE = '2024-10-01';

    /** TRUE until the official Jadual Caruman amounts are transcribed in. Drives the in-app banner. */
    public const IS_PLACEHOLDER = true;

    /** Wage ceiling (raised from RM5,000 to RM6,000 eff. 1 Oct 2024). */
    public const WAGE_CEILING = 6000.0;

    /** Band width above the fine bottom steps. Official schedule steps in RM100. */
    private const STEP = 100.0;

    /**
     * SOCSO bands for an employee's contribution category.
     *  - Category 1 (<60): Employment Injury + Invalidity — employee 0.5%, employer 1.75%.
     *  - Category 2 (≥60): Employment Injury ONLY — employee 0, employer ~1.25%.
     *
     * @return array<int, array{from: float, to: float, ee: float, er: float}>
     */
    public static function socso(int $category): array
    {
        return $category >= 2
            ? self::bands(employeePct: 0.0, employerPct: 1.25)
            : self::bands(employeePct: 0.5, employerPct: 1.75);
    }

    /**
     * EIS / SIP bands. Does NOT apply to Category 2 (≥60) — returns an empty schedule.
     *
     * @return array<int, array{from: float, to: float, ee: float, er: float}>
     */
    public static function eis(int $category): array
    {
        return $category >= 2 ? [] : self::bands(employeePct: 0.2, employerPct: 0.2);
    }

    /**
     * Look up the contribution for a wage against a band list.
     * Band rule: (from < wage <= to). Wage at/above the ceiling → top band.
     * Wage ≤ 0 → first band (zero contribution). Empty schedule → zero.
     *
     * @param  array<int, array{from: float, to: float, ee: float, er: float}>  $bands
     * @return array{ee: float, er: float}
     */
    public static function lookup(array $bands, float $wage): array
    {
        if ($bands === []) {
            return ['ee' => 0.0, 'er' => 0.0];
        }

        $last = $bands[count($bands) - 1];
        if ($wage >= $last['to']) {
            return ['ee' => $last['ee'], 'er' => $last['er']];
        }

        foreach ($bands as $band) {
            if ($wage > $band['from'] && $wage <= $band['to']) {
                return ['ee' => $band['ee'], 'er' => $band['er']];
            }
        }

        return ['ee' => $bands[0]['ee'], 'er' => $bands[0]['er']];
    }

    /**
     * Generate RM100-step bands from 0 to the ceiling. PLACEHOLDER ONLY — each band's
     * amount is its midpoint × the statutory rate. Replace with the official table.
     *
     * @return array<int, array{from: float, to: float, ee: float, er: float}>
     */
    private static function bands(float $employeePct, float $employerPct): array
    {
        $bands = [];
        for ($from = 0.0; $from < self::WAGE_CEILING; $from += self::STEP) {
            $to = $from + self::STEP;
            $midpoint = ($from + $to) / 2;
            $bands[] = [
                'from' => $from,
                'to' => $to,
                'ee' => round($midpoint * $employeePct / 100, 2),
                'er' => round($midpoint * $employerPct / 100, 2),
            ];
        }

        return $bands;
    }
}
