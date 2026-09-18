<?php

declare(strict_types=1);

namespace App\Services\Payroll\Statutory;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * HRD Corp levy listing, as CSV. Layout is ours, pinned by
 * tests/Fixtures/statutory/hrdcorp-2026-06.csv; not yet checked against HRD Corp's
 * published specification. Add the official document to docs/statutory and flip
 * verified() once it matches.
 */
final class HrdCorpLevyFile extends StatutoryFile
{
    public function key(): string
    {
        return 'hrdcorp';
    }

    public function label(): string
    {
        return 'HRD Corp levy';
    }

    public function verified(): bool
    {
        return false;
    }

    public function contentType(): string
    {
        return 'text/csv';
    }

    public function filename(PayrollRun $run, Tenant $tenant): string
    {
        return 'HRDCorp-'.($tenant->hrdf_registration_no ?? 'employer').'-'.$this->wageMonth($run).'.csv';
    }

    public function build(PayrollRun $run, Tenant $tenant, Collection $payslips): string
    {
        $rows = $payslips->filter(fn (Payslip $p) => (float) $p->hrdf_levy > 0)->values();
        $month = $this->wageMonth($run);

        $detail = [];
        $wagesTotal = 0.0;
        foreach ($rows as $p) {
            $emp = $p->employee;
            // The levy's own wage base: basic pay plus fixed allowances, less unpaid leave.
            $wages = round((float) $p->basic + (float) $p->allowances_total - (float) $p->unpaid_deduction, 2);
            $wagesTotal += $wages;
            $detail[] = [
                $tenant->hrdf_registration_no,
                $month,
                $this->digits($emp?->nric),
                $this->ascii((string) $emp?->name),
                $this->amount($wages),
                $this->amount($p->hrdf_levy),
            ];
        }

        return $this->csv([
            ['EMPLOYER CODE', 'MONTH', 'NRIC', 'NAME', 'WAGES', 'LEVY'],
            ...$detail,
            ['TOTAL', '', '', '', $this->amount($wagesTotal), $this->amount($rows->sum(fn (Payslip $p) => (float) $p->hrdf_levy))],
        ]);
    }

    private function wageMonth(PayrollRun $run): string
    {
        [$year, $month] = explode('-', $run->period);

        return $month.$year;
    }
}
