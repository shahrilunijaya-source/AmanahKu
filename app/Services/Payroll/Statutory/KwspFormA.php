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

    public function columns(): array
    {
        return ['wages' => ['Wages', 'Upah'], 'employer' => ['Employer share', 'Caruman majikan'], 'employee' => ['Employee share', 'Caruman pekerja']];
    }

    public function rows(Collection $payslips): array
    {
        return $payslips->filter(fn (Payslip $p) => ((float) $p->epf_employee + (float) $p->epf_employer) > 0)
            ->map(fn (Payslip $p) => $this->row($p, $p->employee?->salaryStructure?->epf_no, [
                // No stored EPF wage column: the calculator's rule is gross less overtime.
                'wages' => (float) $p->gross - (float) $p->overtime_amount,
                'employer' => $p->epf_employer,
                'employee' => $p->epf_employee,
            ]))->values()->all();
    }

    public function build(PayrollRun $run, Tenant $tenant, Collection $payslips): string
    {
        $rows = collect($this->rows($payslips));

        $detail = $rows->map(fn (array $r) => [
            $r['ref'], $r['ic'], $this->ascii($r['name']),
            $this->amount($r['amounts']['wages']), $this->amount($r['amounts']['employer']), $this->amount($r['amounts']['employee']),
        ])->all();

        return $this->csv([
            ['EMPLOYER NO', 'CONTRIBUTION MONTH', 'TOTAL EMPLOYER', 'TOTAL EMPLOYEE', 'RECORDS'],
            [
                $tenant->epf_employer_no,
                $this->contributionMonth($run),
                $this->amount($rows->sum(fn (array $r) => $r['amounts']['employer'])),
                $this->amount($rows->sum(fn (array $r) => $r['amounts']['employee'])),
                $rows->count(),
            ],
            ['EPF NO', 'NRIC', 'NAME', 'WAGES', 'EMPLOYER SHARE', 'EMPLOYEE SHARE'],
            ...$detail,
        ]);
    }
}
