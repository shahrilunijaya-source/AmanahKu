<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * Minimum Wages Order 2024: RM1,700 a month nationwide from 1 February 2025. Statutory,
 * not tenant-editable; a new Order is a code change here with a new effective date.
 */
final class MinimumWage
{
    public const MONTHLY = 1700.00;

    public const EFFECTIVE = '2025-02-01';

    public static function below(float $basic): bool
    {
        return round($basic, 2) < self::MONTHLY;
    }
}
