<?php

declare(strict_types=1);

namespace App\Services\Payroll\BankFile;

use App\Models\PayrollRun;
use Illuminate\Support\Collection;

/**
 * Maybank2u Biz / M2E bulk-payroll style layout (IBG credit). ⚠️ verified() = false —
 * Maybank's bulk template is account-specific and version-specific; confirm the exact
 * record order, NRIC formatting and any header/trailer record against the current M2E
 * payroll upload spec before a live run (I-017).
 */
class Maybank2uBizFormat extends BankFileFormat
{
    public function key(): string
    {
        return 'maybank2u';
    }

    public function label(): string
    {
        return 'Maybank2u Biz (verify spec)';
    }

    public function verified(): bool
    {
        return false;
    }

    public function rows(Collection $payslips, PayrollRun $run): array
    {
        $rows = [[
            'Beneficiary Account', 'Beneficiary Name', 'Beneficiary NRIC',
            'Amount', 'Payment Type', 'Reference', 'Email',
        ]];

        foreach ($payslips->values() as $p) {
            $s = $p->employee?->salaryStructure;
            $rows[] = [
                $s?->bank_account_no,
                $p->employee?->name,
                // NRIC decrypts on read; bank files want it digits-only.
                preg_replace('/\D/', '', (string) $s?->nric),
                $this->amount($p->net_pay),
                'IBG',
                'Salary '.$run->label,
                $p->employee?->email,
            ];
        }

        return $rows;
    }
}
