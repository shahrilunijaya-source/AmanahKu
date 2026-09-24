<?php

declare(strict_types=1);

namespace App\Services\Payroll\Statutory;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;

/**
 * What the Form screen's monthly tabs (EPF Borang A, Perkeso 8A, CP39, HRDF) show for
 * one month: the same merged payslips and rows Payment → Submission downloads.
 */
final class StatutoryMonth
{
    /** Latest month with a finalized run that counts as pay, or this month when there is none. */
    public static function defaultPeriod(Tenant $tenant): string
    {
        return (string) (PayrollRun::where('tenant_id', $tenant->id)->where('status', 'finalized')
            ->countsAsRemuneration()->max('period') ?? now()->format('Y-m'));
    }

    /**
     * The run a download for this month hangs off: the monthly run when there is one,
     * else any finalized run of the month. The download merges the whole month anyway.
     */
    public static function run(Tenant $tenant, string $period): ?PayrollRun
    {
        return PayrollRun::where('tenant_id', $tenant->id)->where('period', $period)
            ->where('status', 'finalized')->countsAsRemuneration()
            ->orderByRaw("kind = 'monthly' desc")->orderBy('id')->first();
    }

    /**
     * @return array{run: ?PayrollRun, rows: list<array{payslip: Payslip, name: string, ic: string, ref: ?string, amounts: array<string, float>}>, totals: array<string, float>}
     */
    public static function for(Tenant $tenant, string $period, StatutoryFile $file): array
    {
        $run = self::run($tenant, $period);
        $rows = $run === null ? [] : $file->rows(MergedPayslips::forPeriod($tenant, $period, $file->includesBonusRuns()));
        $totals = [];
        foreach (array_keys($file->columns()) as $key) {
            $totals[$key] = round(array_sum(array_map(fn (array $r) => $r['amounts'][$key], $rows)), 2);
        }

        return ['run' => $run, 'rows' => $rows, 'totals' => $totals];
    }
}
