<?php

declare(strict_types=1);

namespace App\Services\Payroll\BankFile;

use App\Models\PayrollRun;
use Illuminate\Support\Collection;

/**
 * DuitNow-style batch credit layout (account-number variant). Columns follow the common
 * DuitNow bulk-payment shape. ⚠️ verified() = false — confirm the exact column order,
 * ID-type codes and header against your bank's current DuitNow batch template before
 * relying on it for a live payment run (I-017).
 */
class DuitNowBatchFormat extends BankFileFormat
{
    public function key(): string
    {
        return 'duitnow';
    }

    public function label(): string
    {
        return 'DuitNow batch (verify spec)';
    }

    public function verified(): bool
    {
        return false;
    }

    public function rows(Collection $payslips, PayrollRun $run): array
    {
        $rows = [[
            'Payment Type', 'Beneficiary Name', 'Beneficiary Bank',
            'Account Number', 'Amount', 'Recipient Reference', 'Payment Details', 'Email',
        ]];

        foreach ($payslips->values() as $p) {
            $s = $p->employee?->salaryStructure;
            $rows[] = [
                'IBG',
                $p->employee?->name,
                $s?->bank_name,
                $s?->bank_account_no,
                $this->amount($p->net_pay),
                substr('SAL'.preg_replace('/\D/', '', $run->period), 0, 20),
                'Salary '.$run->label,
                $p->employee?->email,
            ];
        }

        return $rows;
    }
}
