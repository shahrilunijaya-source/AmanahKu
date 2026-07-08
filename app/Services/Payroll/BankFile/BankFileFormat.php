<?php

declare(strict_types=1);

namespace App\Services\Payroll\BankFile;

use App\Models\PayrollRun;
use Illuminate\Support\Collection;

/**
 * A bank bulk-payment file layout. The generic CSV is the verified common denominator;
 * bank-specific formats (Maybank2u, CIMB BizChannel, DuitNow batch, …) are structural
 * approximations until confirmed against each bank's current upload spec — `verified()`
 * reports that so the UI and audit trail can flag an unconfirmed layout (I-017).
 */
abstract class BankFileFormat
{
    /** Stable key used in the ?format= query param and the filename. */
    abstract public function key(): string;

    /** Human label for the format picker. */
    abstract public function label(): string;

    /** True when the layout matches the bank's published spec; false = needs confirming. */
    abstract public function verified(): bool;

    /**
     * Full set of CSV rows (header + one per payslip + any total/footer), each an array
     * of scalar cells. Fixed-width banks would override the writer; all current formats
     * are CSV, so the controller streams these via fputcsv.
     *
     * @param  Collection<int, \App\Models\Payslip>  $payslips
     * @return array<int, array<int, string|int|float>>
     */
    abstract public function rows(Collection $payslips, PayrollRun $run): array;

    public function filename(PayrollRun $run): string
    {
        return $this->key().'-'.$run->period.'.csv';
    }

    /** Net pay formatted to 2 dp with no thousands separator (bank-upload safe). */
    protected function amount(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
