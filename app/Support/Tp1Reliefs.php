<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Spec F8: the optional deductions an employee may claim monthly through LHDN's Form
 * TP1. Codes, labels and yearly caps are transcribed from "List of deductions must be
 * provided in the system as follows" in
 * docs/statutory/spesifikasi-kaedah-pengiraan-berkomputer-pcb-2026.pdf (pages 28 to 35);
 * every entry carries its PDF page. These are statutory figures for one tax year and are
 * never tenant-editable.
 */
final class Tp1Reliefs
{
    /** The tax year these caps belong to. */
    public const int YEAR = 2026;

    /**
     * code => [label, label_ms, cap].
     *
     * @var array<string, array{label: string, label_ms: string, cap: float}>
     */
    public const array LIST = [
        // p.28 item (a).
        'parents-medical' => ['label' => 'Medical treatment, special needs or carer expenses for parents / grandparents', 'label_ms' => 'Rawatan perubatan, keperluan khas atau penjagaan ibu bapa / datuk nenek', 'cap' => 8000.00],
        // p.29 item (b).
        'supporting-equipment' => ['label' => 'Basic supporting equipment for a disabled person', 'label_ms' => 'Peralatan sokongan asas untuk orang kurang upaya', 'cap' => 6000.00],
        // p.29 item (c).
        'higher-education' => ['label' => 'Higher education fees (self)', 'label_ms' => 'Yuran pengajian tinggi (sendiri)', 'cap' => 7000.00],
        // p.29 item (d).
        'tourism-culture' => ['label' => 'Admission fees to tourist centres and cultural programmes', 'label_ms' => 'Yuran masuk pusat pelancongan dan program kebudayaan', 'cap' => 1000.00],
        // p.30 item (e).
        'serious-disease' => ['label' => 'Medical expenses on serious diseases', 'label_ms' => 'Perbelanjaan perubatan penyakit serius', 'cap' => 10000.00],
        // p.32 item (f).
        'sspn' => ['label' => 'Net deposit in SSPN', 'label_ms' => 'Deposit bersih dalam SSPN', 'cap' => 8000.00],
        // p.32 item (g).
        'alimony' => ['label' => 'Payment of alimony to former wife', 'label_ms' => 'Bayaran nafkah kepada bekas isteri', 'cap' => 4000.00],
        // p.33 item (h). The PDF's own worked examples (p.24) treat a TP1 voluntary EPF
        // claim as LP1 only up to RM3,000 under para 49(1)(a) when mandatory EPF already
        // fills K1, which is the single figure the table states and the one used here.
        'life-insurance-epf' => ['label' => 'Life insurance and EPF', 'label_ms' => 'Insurans hayat dan KWSP', 'cap' => 3000.00],
        // p.33 item (i).
        'prs-annuity' => ['label' => 'Private Retirement Scheme (PRS) and deferred annuity', 'label_ms' => 'Skim Persaraan Swasta (PRS) dan anuiti tertunda', 'cap' => 3000.00],
        // p.33 item (j).
        'education-medical-insurance' => ['label' => 'Education and medical insurance', 'label_ms' => 'Insurans pendidikan dan perubatan', 'cap' => 4000.00],
        // p.34 item (k).
        'socso' => ['label' => 'Contribution to SOCSO', 'label_ms' => 'Caruman PERKESO', 'cap' => 350.00],
        // p.34 item (l).
        'lifestyle' => ['label' => 'Lifestyle', 'label_ms' => 'Gaya hidup', 'cap' => 2500.00],
        // p.34 item (m).
        'breastfeeding-equipment' => ['label' => 'Purchase of breastfeeding equipment', 'label_ms' => 'Pembelian peralatan penyusuan susu ibu', 'cap' => 1000.00],
        // p.34 item (n).
        'child-care' => ['label' => 'Child care fees to a registered centre or kindergarten', 'label_ms' => 'Yuran taska atau tadika berdaftar', 'cap' => 3000.00],
        // p.35 item (o).
        'lifestyle-sports' => ['label' => 'Additional lifestyle (sports)', 'label_ms' => 'Gaya hidup tambahan (sukan)', 'cap' => 1000.00],
        // p.35 item (p).
        'ev-charging' => ['label' => 'EV charging facilities / food waste composting machines', 'label_ms' => 'Kemudahan pengecas EV / mesin kompos sisa makanan', 'cap' => 2500.00],
        // p.35 item (q). RM7,000 is the stated maximum; the lower RM5,000 band for a home
        // valued RM500,001 to RM750,000 is a condition on the claim, not a second cap.
        'housing-loan-interest' => ['label' => 'Interest on housing loan (first-time buyer)', 'label_ms' => 'Faedah pinjaman perumahan (pembeli kali pertama)', 'cap' => 7000.00],
    ];

    /** What of a claim is still allowed once the year's cap for that relief is counted. */
    public static function allowed(string $code, float $claim, float $claimedEarlierThisYear): float
    {
        $claim = max(0.0, $claim);
        if (! array_key_exists($code, self::LIST)) {
            return round($claim, 2);
        }

        return round(min($claim, max(0.0, self::LIST[$code]['cap'] - max(0.0, $claimedEarlierThisYear))), 2);
    }
}
