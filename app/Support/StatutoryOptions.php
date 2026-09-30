<?php

declare(strict_types=1);

namespace App\Support;

/** Fixed lists for the Bank & Statutory tab (Worksy's Malaysian lists). Not tenant-editable. */
final class StatutoryOptions
{
    public const BANKS = [
        'Affin Bank', 'Agrobank', 'Alliance Bank', 'AmBank', 'Bank Islam', 'Bank Muamalat', 'Bank Rakyat', 'Bank Simpanan Nasional',
        'CIMB Bank', 'Citibank', 'Hong Leong Bank', 'HSBC Bank', 'Kuwait Finance House', 'Maybank', 'MBSB Bank', 'OCBC Bank',
        'Public Bank', 'RHB Bank', 'Standard Chartered', 'United Overseas Bank', 'Other',
    ];

    /** Form E item 3 (Category of employer) codes, transcribed from docs/statutory/form-e-sample-2025.pdf. */
    public const EMPLOYER_CATEGORIES = [
        '1' => 'Government', '2' => 'Statutory', '3' => 'Local authority',
        '4' => 'Private Sector - Company', '5' => 'Private Sector - Other than company', '6' => 'Special class employer',
    ];

    /** Form E item 4 (Status of employer) codes, same source. */
    public const EMPLOYER_STATUSES = ['1' => 'In operation', '2' => 'Dormant', '3' => 'In the process of winding up'];

    /**
     * Malaysian bank SWIFT/BIC codes keyed by the BANKS display name. Used by the salary
     * structure bank picker (bank_code) and the employer's paying bank. "Other" has no
     * code — a lookup on it yields null, which is what the agency files expect.
     */
    public const BANK_CODES = [
        'Affin Bank' => 'PHBMMYKL', 'Agrobank' => 'AGOBMYKL', 'Alliance Bank' => 'MFBBMYKL', 'AmBank' => 'ARBKMYKL',
        'Bank Islam' => 'BIMBMYKL', 'Bank Muamalat' => 'BMMBMYKL', 'Bank Rakyat' => 'BKRMMYKL', 'Bank Simpanan Nasional' => 'BSNAMYK1',
        'CIMB Bank' => 'CIBBMYKL', 'Citibank' => 'CITIMYKL', 'Hong Leong Bank' => 'HLBBMYKL', 'HSBC Bank' => 'HBMBMYKL',
        'Kuwait Finance House' => 'KFHOMYKL', 'Maybank' => 'MBBEMYKL', 'MBSB Bank' => 'AFBQMYKL', 'OCBC Bank' => 'OCBCMYKL',
        'Public Bank' => 'PBBEMYKL', 'RHB Bank' => 'RHBBMYKL', 'Standard Chartered' => 'SCBLMYKX', 'United Overseas Bank' => 'UOVBMYKL',
    ];

    /** Short codes HR sheets and the old payroll system use for a bank, keyed lower-case. */
    private const BANK_ALIASES = [
        'mbb' => 'Maybank', 'pbb' => 'Public Bank', 'hlb' => 'Hong Leong Bank', 'hlbb' => 'Hong Leong Bank', 'bimb' => 'Bank Islam',
        'bsn' => 'Bank Simpanan Nasional', 'uob' => 'United Overseas Bank', 'kfh' => 'Kuwait Finance House', 'scb' => 'Standard Chartered',
    ];

    /**
     * The bank-list name for a typed or legacy bank name, or null when it can't be told
     * apart. Accepts the list name in any case, a short code ("MBB"), or a longer name
     * holding exactly one bank's distinctive part ("Malayan Banking Berhad (MAYBANK)").
     * A name matching two banks ("Maybank Islamic") stays null rather than guessing.
     */
    public static function bankFor(?string $typed): ?string
    {
        $key = mb_strtolower(trim((string) $typed));
        if ($key === '') {
            return null;
        }
        foreach (array_keys(self::BANK_CODES) as $name) {
            if (mb_strtolower($name) === $key) {
                return $name;
            }
        }
        if (isset(self::BANK_ALIASES[$key])) {
            return self::BANK_ALIASES[$key];
        }
        // Same "distinctive part" rule as the bank_code backfill migration ("Bank Islam" -> "islam").
        $hits = array_values(array_filter(array_keys(self::BANK_CODES), fn (string $name) => str_contains(
            $key, mb_strtolower((string) preg_replace('/(^Bank |\s+Bank$)/', '', $name)),
        )));

        return count($hits) === 1 ? $hits[0] : null;
    }

    /** LHDN PCB category codes. */
    public const TAX_CATEGORIES = ['1' => 'Category 1 · Single', '2' => 'Category 2 · Married, spouse not working', '3' => 'Category 3 · Married, spouse working'];

    public const EMPLOYEE_TAX_STATUS = ['normal' => 'Normal', 'returning_expert' => 'Returning Expert Programme', 'knowledge_worker' => 'Knowledge Worker (Iskandar)', 'non_resident' => 'Non-resident'];

    public const EPF_SCHEMES = ['statutory' => 'Statutory rate', 'voluntary_higher' => 'Voluntary higher rate', 'exempt' => 'Exempt'];

    /** State zakat bodies, as Worksy's Zakat Form lists them. */
    public const ZAKAT_AUTHORITIES = ['johor' => 'Johor', 'kedah' => 'Kedah', 'kelantan' => 'Kelantan', 'kuala_lumpur' => 'Kuala Lumpur', 'labuan' => 'Labuan', 'melaka' => 'Malacca',
        'negeri_sembilan' => 'Negeri Sembilan', 'pahang' => 'Pahang', 'penang' => 'Penang', 'perak' => 'Perak', 'perlis' => 'Perlis', 'putrajaya' => 'Putrajaya',
        'sabah' => 'Sabah', 'sarawak' => 'Sarawak', 'selangor' => 'Selangor', 'terengganu' => 'Terengganu'];

    public const SOCSO_CATEGORIES = ['category_1' => 'Category 1 · Employment injury + invalidity', 'category_2' => 'Category 2 · Employment injury only', 'exempt' => 'Exempt'];

    /** LHDN child relief categories; each holds a count at 100% and a count at 50% (shared custody). */
    public const CHILD_RELIEF_CATEGORIES = [
        'under_18' => ['Under 18', 'Bawah 18 tahun'],
        'over_18_education' => ['18+ in full-time education', '18+ pendidikan sepenuh masa'],
        'disabled' => ['Disabled child', 'Anak kurang upaya'],
        'disabled_education' => ['Disabled child, 18+ in education', 'Anak kurang upaya, 18+ pendidikan'],
    ];
}
