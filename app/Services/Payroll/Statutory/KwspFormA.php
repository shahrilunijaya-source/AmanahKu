<?php

declare(strict_types=1);

namespace App\Services\Payroll\Statutory;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * KWSP Form A contribution listing, as CSV. Layout is ours, pinned by
 * tests/Fixtures/statutory/kwsp-form-a-2026-06.csv; not yet checked against KWSP's
 * published specification. Add the official document to docs/statutory and flip
 * verified() once it matches.
 */
final class KwspFormA extends StatutoryFile
{
    public function key(): string
    {
        return 'kwsp-form-a';
    }

    public function label(): string
    {
        return 'KWSP Form A (EPF)';
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
        return 'KWSP-FormA-'.($tenant->epf_employer_no ?? 'employer').'-'.$this->contributionMonth($run).'.csv';
    }

    public function build(PayrollRun $run, Tenant $tenant, Collection $payslips): string
    {
        $rows = $payslips->filter(fn (Payslip $p) => ((float) $p->epf_employee + (float) $p->epf_employer) > 0)->values();

        $detail = [];
        foreach ($rows as $p) {
            $emp = $p->employee;
            $s = $emp?->salaryStructure;
            $detail[] = [
                $s?->epf_no,
                $this->digits($emp?->nric),
                $this->ascii((string) $emp?->name),
                // No stored EPF wage column: the calculator's rule is gross less overtime.
                $this->amount(round((float) $p->gross - (float) $p->overtime_amount, 2)),
                $this->amount($p->epf_employer),
                $this->amount($p->epf_employee),
            ];
        }

        return $this->csv([
            ['EMPLOYER NO', 'CONTRIBUTION MONTH', 'TOTAL EMPLOYER', 'TOTAL EMPLOYEE', 'RECORDS'],
            [
                $tenant->epf_employer_no,
                $this->contributionMonth($run),
                $this->amount($rows->sum(fn (Payslip $p) => (float) $p->epf_employer)),
                $this->amount($rows->sum(fn (Payslip $p) => (float) $p->epf_employee)),
                $rows->count(),
            ],
            ['EPF NO', 'NRIC', 'NAME', 'WAGES', 'EMPLOYER SHARE', 'EMPLOYEE SHARE'],
            ...$detail,
        ]);
    }
}
