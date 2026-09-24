<?php

declare(strict_types=1);

namespace App\Services\Payroll\Statutory;

use App\Models\Payslip;
use App\Models\Tenant;
use App\Support\StatutoryOptions;

/**
 * Payroll → Form → Zakat: zakat deducted from pay in one month, per staff member and the
 * state body it goes to. Every authority's upload file differs and none is specified
 * yet, so this is the listing and a plain CSV (spec phase 3).
 */
final class ZakatMonth
{
    /** Filter value for staff whose deduction has no authority set yet. */
    public const string NONE = 'none';

    /**
     * @param  ?string  $authority  a StatutoryOptions::ZAKAT_AUTHORITIES key, self::NONE, or null for all
     * @return array{rows: list<array{name: string, staff_id: ?string, ic: string, authority: ?string, amount: float}>, total: float}
     */
    public static function for(Tenant $tenant, string $period, ?string $authority): array
    {
        $rows = Payslip::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('zakat', '>', 0)
            ->whereHas('payrollRun', fn ($q) => $q->where('tenant_id', $tenant->id)->where('period', $period)
                ->where('status', 'finalized')->countsAsRemuneration())
            ->with('employee.salaryStructure')->get()
            ->groupBy('employee_id')
            ->map(function ($slips) {
                $e = $slips->first()->employee;
                $key = $e?->salaryStructure?->zakat_authority;

                return ['name' => (string) $e?->name, 'staff_id' => $e?->staff_id, 'ic' => preg_replace('/\D/', '', (string) $e?->nric),
                    'authority' => StatutoryOptions::ZAKAT_AUTHORITIES[$key] ?? null, 'key' => $key, 'amount' => round((float) $slips->sum('zakat'), 2)];
            })
            ->filter(fn (array $r) => $authority === null || ($authority === self::NONE ? $r['authority'] === null : $r['key'] === $authority))
            ->sortBy('name')->values()
            ->map(fn (array $r) => array_diff_key($r, ['key' => 1]))->all();

        return ['rows' => $rows, 'total' => round(array_sum(array_column($rows, 'amount')), 2)];
    }
}
