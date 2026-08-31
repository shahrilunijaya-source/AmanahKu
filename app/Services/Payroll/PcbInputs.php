<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * Inputs for one month's LHDN PCB (Monthly Tax Deduction) calculation — resident
 * employee, normal-remuneration formula per docs/statutory/spesifikasi-kaedah-pengiraan-
 * berkomputer-pcb-2026.pdf, section D(b)(1) and D(b)(2), variable names match the PDF.
 *
 * The employer/payroll system is responsible for carrying the year-to-date figures
 * (∑Y, ∑K, X, Z, ∑LP) forward month to month — this object is a snapshot for one month.
 */
final readonly class PcbInputs
{
    /**
     * @param  int  $category  1 = single; 2 = married, spouse not working; 3 = married with
     *                         working spouse, divorced, widowed, or single with adopted child.
     * @param  bool  $isResident  false routes to the flat 30%-of-remuneration non-resident rate.
     * @param  float  $ytdGrossY  ∑Y — accumulated gross normal + additional remuneration paid
     *                            prior to this month this year (including a prior employer via TP3).
     * @param  float  $ytdEpfK  ∑K — accumulated EPF/approved-scheme contribution on ∑Y.
     * @param  float  $currentGrossY1  Y1 — this month's gross normal remuneration.
     * @param  float  $currentEpfK1  K1 — EPF/approved-scheme contribution on Y1.
     * @param  int  $monthsRemainingAfterCurrent  n — months left in the year after this one
     *                                            (January = 11, December = 0).
     * @param  float  $ytdZakatZ  Z — accumulated zakat paid this year, excluding this month.
     * @param  float  $ytdMtdPaidX  X — accumulated MTD already paid this year (excludes any
     *                              employee-requested additional deduction or tax instalment).
     * @param  float  $currentZakat  Zakat (or fi) deducted this month, netted off the MTD.
     * @param  bool  $disabledIndividual  Grants the DU relief.
     * @param  bool  $disabledSpouse  Grants the SU relief (category 2/3 only, spouse present).
     * @param  int  $qualifyingChildren  C — number of qualifying children. Per the spec's
     *                                   category definitions this is forced to 0 for category 1.
     * @param  float  $ytdOptionalDeductions  ∑LP — accumulated TP1 optional deductions this year.
     * @param  float  $currentOptionalDeductions  LP1 — this month's TP1 optional deductions
     *                                            (includes voluntary EPF/insurance beyond K1).
     * @param  float  $currentAdditionalGrossYt  Yt — this month's additional remuneration
     *                                           (bonus, arrears, non-monthly commission, …), 0 if none.
     * @param  float  $currentAdditionalEpfKt  Kt — EPF/approved-scheme contribution on Yt.
     */
    public function __construct(
        public int $category,
        public bool $isResident = true,
        public float $ytdGrossY = 0.0,
        public float $ytdEpfK = 0.0,
        public float $currentGrossY1 = 0.0,
        public float $currentEpfK1 = 0.0,
        public int $monthsRemainingAfterCurrent = 0,
        public float $ytdZakatZ = 0.0,
        public float $ytdMtdPaidX = 0.0,
        public float $currentZakat = 0.0,
        public bool $disabledIndividual = false,
        public bool $disabledSpouse = false,
        public int $qualifyingChildren = 0,
        public float $ytdOptionalDeductions = 0.0,
        public float $currentOptionalDeductions = 0.0,
        public float $currentAdditionalGrossYt = 0.0,
        public float $currentAdditionalEpfKt = 0.0,
    ) {}
}
