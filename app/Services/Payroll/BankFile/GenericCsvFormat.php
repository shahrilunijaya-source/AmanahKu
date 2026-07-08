<?php

declare(strict_types=1);

namespace App\Services\Payroll\BankFile;

use App\Models\PayrollRun;
use Illuminate\Support\Collection;

/**
 * Generic, bank-agnostic CSV — the original verified layout. Accepted as a manual
 * import template by most MY banks and a safe fallback when no bank format is chosen.
 */
class GenericCsvFormat extends BankFileFormat
{
    public function key(): string
    {
        return 'generic';
    }

    public function label(): string
    {
        return 'Generic CSV';
    }

    public function verified(): bool
    {
        return true;
    }

    public function rows(Collection $payslips, PayrollRun $run): array
    {
        $rows = [['No.', 'Employee', 'Staff ID', 'Bank', 'Account No', 'Amount (MYR)', 'Reference']];

        foreach ($payslips->values() as $i => $p) {
            $s = $p->employee?->salaryStructure;
            $rows[] = [
                $i + 1,
                $p->employee?->name,
                $p->employee?->staff_id,
                $s?->bank_name,
                $s?->bank_account_no,
                $this->amount($p->net_pay),
                'Salary '.$run->label,
            ];
        }

        $rows[] = ['', 'TOTAL', '', '', '', $this->amount($payslips->sum('net_pay')), ''];

        return $rows;
    }
}
