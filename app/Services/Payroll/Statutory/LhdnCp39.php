<?php

declare(strict_types=1);

namespace App\Services\Payroll\Statutory;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * LHDN CP39 MTD text file. Layout: Exhibit 4 of the LHDN computerised-calculation spec,
 * docs/statutory/spesifikasi-kaedah-pengiraan-berkomputer-pcb-2026.pdf (header 57
 * characters, detail 136). MTD is pcb + pcb_additional; CP38 is its own column.
 */
final class LhdnCp39 extends StatutoryFile
{
    public const LAYOUT_EFFECTIVE = '2026-01-01';

    public function key(): string
    {
        return 'cp39';
    }

    public function label(): string
    {
        return 'LHDN CP39 (PCB)';
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
        [$year, $month] = explode('-', $run->period);

        return $this->employerNo($tenant).$month.'_'.$year.'.txt';
    }

    public function columns(): array
    {
        return ['mtd' => ['PCB (MTD)', 'PCB (PCB)'], 'cp38' => ['CP38', 'CP38']];
    }

    public function rows(Collection $payslips): array
    {
        return $payslips->filter(fn (Payslip $p) => ((float) $p->pcb + (float) $p->pcb_additional + (float) $p->cp38) > 0)
            ->map(fn (Payslip $p) => $this->row($p, $p->employee?->salaryStructure?->tax_no, [
                'mtd' => (float) $p->pcb + (float) $p->pcb_additional,
                'cp38' => $p->cp38,
            ]))->values()->all();
    }

    public function build(PayrollRun $run, Tenant $tenant, Collection $payslips): string
    {
        [$year, $month] = explode('-', $run->period);

        $details = [];
        $mtdTotal = 0;
        $cp38Total = 0;
        $mtdCount = 0;
        $cp38Count = 0;
        foreach ($this->rows($payslips) as $r) {
            $p = $r['payslip'];
            $mtd = $r['amounts']['mtd'];
            $cp38 = $r['amounts']['cp38'];
            $mtdTotal += (int) round($mtd * 100);
            $cp38Total += (int) round($cp38 * 100);
            $mtdCount += $mtd > 0 ? 1 : 0;
            $cp38Count += $cp38 > 0 ? 1 : 0;
            $s = $p->employee?->salaryStructure;
            // Explicit null check rather than ?->...??, which Larastan reads as a
            // never-null nullsafe even though a payslip can genuinely have no structure.
            $foreign = $s !== null && $s->nationality === 'foreign';

            $details[] = 'D'
                .str_pad(substr($this->digits($r['ref']), -11), 11, '0', STR_PAD_LEFT)
                .str_pad(substr($this->ascii($r['name']), 0, 60), 60)
                .str_repeat(' ', 12)
                .str_pad($foreign ? '' : substr($r['ic'], 0, 12), 12)
                .str_repeat(' ', 12)   // ponytail: passport number has no column yet; add when foreign staff are on payroll
                .($foreign ? '  ' : 'MY')
                .$this->cents($mtd, 8)
                .$this->cents($cp38, 8)
                .str_pad(substr($this->ascii((string) $p->employee?->staff_id), 0, 10), 10);
        }

        $employerNo = $this->employerNo($tenant);
        $header = 'H'.$employerNo.$employerNo.$year.$month
            .str_pad((string) $mtdTotal, 10, '0', STR_PAD_LEFT).str_pad((string) $mtdCount, 5, '0', STR_PAD_LEFT)
            .str_pad((string) $cp38Total, 10, '0', STR_PAD_LEFT).str_pad((string) $cp38Count, 5, '0', STR_PAD_LEFT);

        // Refuse to produce a file that does not balance or is off-width.
        $sumMtd = array_sum(array_map(fn (string $d) => (int) substr($d, 110, 8), $details));
        $sumCp38 = array_sum(array_map(fn (string $d) => (int) substr($d, 118, 8), $details));
        if ($sumMtd !== $mtdTotal || $sumCp38 !== $cp38Total || strlen($header) !== 57 || array_filter($details, fn (string $d) => strlen($d) !== 136)) {
            throw new RuntimeException('CP39 file does not balance against its header.');
        }

        return implode("\r\n", [$header, ...$details]);
    }

    private function employerNo(Tenant $tenant): string
    {
        return str_pad(substr($this->digits($tenant->employer_tin), -10), 10, '0', STR_PAD_LEFT);
    }
}
