<?php

declare(strict_types=1);

namespace App\Services\Payroll\Statutory;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * PERKESO Borang 8A contribution listing, fixed width at 119 characters a line. Layout is
 * ours, pinned by tests/Fixtures/statutory/perkeso-8a-2026-06.txt; not yet checked against
 * PERKESO's published specification. Add the official document to docs/statutory and flip
 * verified() once it matches. SKBBK has no column here: it is paid through the portal.
 */
final class PerkesoBorang8A extends StatutoryFile
{
    /** Contributions are assessed on wages up to RM6,000 a month. */
    public const WAGE_CEILING = 6000.0;

    public function key(): string
    {
        return 'perkeso-8a';
    }

    public function label(): string
    {
        return 'PERKESO Borang 8A (SOCSO/EIS)';
    }

    public function verified(): bool
    {
        return false;
    }

    public function contentType(): string
    {
        return 'text/plain';
    }

    public function filename(PayrollRun $run, Tenant $tenant): string
    {
        return 'PERKESO-8A-'.($tenant->socso_employer_code ?? 'employer').'-'.$this->contributionMonth($run).'.txt';
    }

    public function build(PayrollRun $run, Tenant $tenant, Collection $payslips): string
    {
        $rows = $payslips->filter(fn (Payslip $p) => ((float) $p->socso_employee + (float) $p->socso_employer + (float) $p->eis_employee + (float) $p->eis_employer) > 0)->values();

        $lines = [];
        foreach ($rows as $p) {
            $emp = $p->employee;
            $s = $emp?->salaryStructure;
            $nric = substr($this->digits($emp?->nric), 0, 12);
            $lines[] = str_pad(substr($this->ascii((string) $tenant->socso_employer_code), 0, 12), 12)
                .str_pad(substr($this->digits($s?->socso_no) ?: $nric, 0, 12), 12)
                .str_pad($nric, 12)
                .str_pad(substr($this->ascii((string) $emp?->name), 0, 45), 45)
                .$this->contributionMonth($run)
                .$this->cents(min(self::WAGE_CEILING, (float) $p->gross), 8)
                .$this->cents($p->socso_employer, 6)
                .$this->cents($p->socso_employee, 6)
                .$this->cents($p->eis_employer, 6)
                .$this->cents($p->eis_employee, 6);
        }

        return implode("\r\n", $lines);
    }
}
