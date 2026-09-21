<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * HRD Corp levy, Pembangunan Sumber Manusia Berhad Act 2001: employers with 10 or more
 * Malaysian employees pay 1% of monthly wages; 5 to 9 may register voluntarily at 0.5%.
 * Wages here are basic salary plus fixed allowances after unpaid leave. It is the
 * employer's cost only. Rates are statutory, not tenant-editable; the tenant only chooses
 * which registration applies (Features 'payroll.hrdf').
 */
final class HrdCorpLevy
{
    public const EFFECTIVE = '2021-03-01';

    public const RATES = ['off' => 0.0, '1' => 0.01, '0.5' => 0.005];

    public static function rate(?string $setting): float
    {
        return self::RATES[$setting ?? 'off'] ?? 0.0;
    }
}
