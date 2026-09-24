<?php

declare(strict_types=1);

namespace App\Services\Payroll\Statutory;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * PERKESO combined SOCSO + EIS + SKBBK contribution text file for the ASSIST 2.0 portal,
 * fixed width at 278 characters a line. Transcribed from "Combine SOCSO + EIS Contribution
 * Text File Format" v2.1 (13 Feb 2026), kept in docs/statutory but out of git because
 * PERKESO marks it confidential. Mandatory for every upload from 1 October 2026.
 *
 * Amounts are right justified with spaces, as in the spec's sample file, which also shows
 * the wage column carrying actual wages: only the contributions stop at the RM6,000 ceiling.
 */
final class PerkesoBorang8A extends StatutoryFile
{
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
        return true;
    }

    public function contentType(): string
    {
        return 'text/plain';
    }

    public function filename(PayrollRun $run, Tenant $tenant): string
    {
        return 'PERKESO-8A-'.($tenant->socso_employer_code ?? 'employer').'-'.$this->wageMonth($run).'.txt';
    }

    public function columns(): array
    {
        return [
            'wages' => ['Wages', 'Gaji'],
            'socso_employer' => ['SOCSO employer', 'PERKESO majikan'], 'socso_employee' => ['SOCSO employee', 'PERKESO pekerja'],
            'eis_employer' => ['EIS employer', 'SIP majikan'], 'eis_employee' => ['EIS employee', 'SIP pekerja'],
            'skbbk_employee' => ['SKBBK', 'SKBBK'],
        ];
    }

    public function rows(Collection $payslips): array
    {
        return $payslips->filter(fn (Payslip $p) => ((float) $p->socso_employee + (float) $p->socso_employer + (float) $p->eis_employee + (float) $p->eis_employer + (float) $p->skbbk_employee) > 0)
            ->map(fn (Payslip $p) => $this->row($p, $p->employee?->salaryStructure?->socso_no, [
                'wages' => $p->gross,
                'socso_employer' => $p->socso_employer, 'socso_employee' => $p->socso_employee,
                'eis_employer' => $p->eis_employer, 'eis_employee' => $p->eis_employee,
                'skbbk_employee' => $p->skbbk_employee,
            ]))->values()->all();
    }

    public function build(PayrollRun $run, Tenant $tenant, Collection $payslips): string
    {
        $lines = [];
        foreach ($this->rows($payslips) as $r) {
            $a = $r['amounts'];
            // A foreign worker has no NRIC; PERKESO then takes the SSFW / SSFDW number.
            $id = $r['ic'] ?: (preg_replace('/[^A-Z0-9]/', '', $this->ascii((string) $r['ref'])) ?? '');
            $lines[] = $this->text($tenant->socso_employer_code, 12)
                .$this->text($tenant->registration_number, 20)
                .$this->text($id, 12)
                .$this->text($r['name'], 150)
                .$this->wageMonth($run)
                .$this->money($a['wages'], 14)
                .$this->money($a['socso_employer'], 6)
                .$this->money($a['socso_employee'], 6)
                .$this->money($a['eis_employer'], 6)
                .$this->money($a['eis_employee'], 6)
                .$this->money($a['skbbk_employee'], 6)
                .str_repeat(' ', 34);
        }

        return implode("\r\n", $lines);
    }

    /**
     * PERKESO's contribution month is the month the wages were earned (MMYYYY), paid by
     * the end of the following month. KWSP's Form A uses the month after instead.
     */
    private function wageMonth(PayrollRun $run): string
    {
        return substr($run->period, 5, 2).substr($run->period, 0, 4);
    }

    /** Left justified, uppercase ASCII, cut or space-padded to the width. */
    private function text(?string $value, int $width): string
    {
        return str_pad(substr($this->ascii((string) $value), 0, $width), $width);
    }

    /** Cents with no decimal point, right justified with spaces; zero is written 0000 as in the spec's sample. */
    private function money(float|int|string|null $value, int $width): string
    {
        $cents = (int) round(((float) $value) * 100);

        return str_pad($cents === 0 ? '0000' : (string) $cents, $width, ' ', STR_PAD_LEFT);
    }
}
