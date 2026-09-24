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

    /**
     * What to tell HR when the employer number this file is keyed on is blank, or null
     * when it's set. A file with a blank employer column is rejected by the agency.
     */
    public function missingEmployerNumber(Tenant $tenant): ?string
    {
        [$column, $name] = match ($this->key()) {
            'kwsp-form-a' => ['epf_employer_no', 'KWSP employer number'],
            'perkeso-8a' => ['socso_employer_code', 'PERKESO employer code'],
            'cp39' => ['employer_tin', 'LHDN E number'],
            'hrdcorp' => ['hrdf_registration_no', 'HRD Corp registration number'],
            default => [null, null],
        };

        return $column !== null && blank($tenant->{$column}) ? "Set your {$name} in Company Settings." : null;
    }

    /** PERKESO wages exclude the annual bonus, so its file leaves bonus runs out (spec F10). */
    public function includesBonusRuns(): bool
    {
        return $this->key() !== 'perkeso-8a';
    }

    /**
     * Labels for the amounts in rows(), in column order: [key => [English, Malay]].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    abstract public function columns(): array;

    /**
     * One entry per line the file will carry. The Form screen's table and build() both
     * read this, so what HR checks on screen is what goes to the agency.
     *
     * @param  Collection<int, Payslip>  $payslips
     * @return list<array{payslip: Payslip, name: string, ic: string, ref: ?string, amounts: array<string, float>}>
     */
    abstract public function rows(Collection $payslips): array;

    /**
     * The row shape shared by every file: who, their IC, their number at the agency.
     *
     * @param  array<string, float|int|string|null>  $amounts
     * @return array{payslip: Payslip, name: string, ic: string, ref: ?string, amounts: array<string, float>}
     */
    protected function row(Payslip $p, ?string $ref, array $amounts): array
    {
        return [
            'payslip' => $p,
            'name' => (string) $p->employee?->name,
            'ic' => $this->digits($p->employee?->nric),
            'ref' => $ref,
            'amounts' => array_map(fn ($v) => round((float) $v, 2), $amounts),
        ];
    }

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
