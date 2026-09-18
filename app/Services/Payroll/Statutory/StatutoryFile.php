<?php

declare(strict_types=1);

namespace App\Services\Payroll\Statutory;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * An agency upload file built from a finalized run. verified() follows the BankFileFormat
 * convention: true only when the layout is transcribed from an official document kept in
 * docs/statutory; false means a structural approximation the UI and audit trail flag.
 */
abstract class StatutoryFile
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function verified(): bool;

    abstract public function filename(PayrollRun $run, Tenant $tenant): string;

    abstract public function contentType(): string;

    /** @param  Collection<int, Payslip>  $payslips */
    abstract public function build(PayrollRun $run, Tenant $tenant, Collection $payslips): string;

    /** Ringgit as zero-padded cents with no decimal point: 120.5 at width 10 is 0000012050. */
    protected function cents(float|int|string $value, int $width): string
    {
        return str_pad((string) (int) round(((float) $value) * 100), $width, '0', STR_PAD_LEFT);
    }

    protected function ascii(string $value): string
    {
        $plain = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return strtoupper(preg_replace('/[^\x20-\x7E]/', '', $plain === false ? $value : $plain) ?? '');
    }

    protected function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }

    protected function amount(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** KWSP and PERKESO contributions for a wage month are remitted in the month after it. */
    protected function contributionMonth(PayrollRun $run): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $run->period.'-01')->addMonth()->format('mY');
    }

    /**
     * CSV lines, LF ended. Written by hand rather than with fputcsv because PHP 8.4 quotes
     * any cell holding a space, which the agency uploads and the golden files do not.
     * Only a comma, a quote or a line break forces quoting here.
     *
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    protected function csv(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $cells = array_map(function (string $cell): string {
                return preg_match('/["\r\n,]/', $cell) === 1 ? '"'.str_replace('"', '""', $cell).'"' : $cell;
            }, Csv::safeRow($row));
            $lines[] = implode(',', $cells);
        }

        return implode("\n", $lines)."\n";
    }
}
