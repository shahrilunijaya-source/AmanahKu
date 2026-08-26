<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use Carbon\Carbon;

/**
 * Formats one employee's 22 pipe-delimited fields for the C.P.8D text file, per
 * docs/statutory/cp8d-information-layout.pdf Part A ("EMPLOYEES' PARTICULARS"). This
 * class only knows formatting rules — Cp8dData supplies the raw values (and decides
 * what this app can and cannot fill in).
 *
 * Sen handling differs per field (the PDF's own "DATA TYPES" column):
 *   - Integer fields (9, 10, 11, 12, 13, 14, 15, 17, 21, 22): sen DROPPED — truncated
 *     towards zero, never rounded (RM50000.70 → 50000, not 50001; the PDF's own
 *     examples for fields 10/11/12/13/14/17/21/22 all show this).
 *   - Decimal fields (16, 18, 19, 20): sen KEPT, to 2 decimal places.
 *   - Fields 4-8 are small integer codes/counts, not money — printed as bare integers.
 *   - Field 6 is a date, dd-mm-yyyy.
 *
 * An empty/untracked optional field prints as an EMPTY STRING between delimiters, never
 * "0" or "0.00" — the PDF's own second worked example shows exactly this for an absent
 * living-accommodation benefit, ESOS benefit and CP38 instalment (three consecutive
 * delimiters, `4200|||445`). Applied uniformly here: a null OR zero value on any of
 * fields 7-22 (money, counts and codes alike) prints blank — LHDN's own example does
 * this even for CP38, a field we DO track, when its total genuinely is zero. Fields
 * 1-3 always print (name/TIN/identification are never "zero", TIN alone may be blank
 * per field 2's own rule when the employee has none).
 *
 * The PDF's own second worked example ends "...2555.25||2210|150|" — a trailing pipe
 * after field 22, its last field, which is already populated (150) in BOTH examples.
 * There is no rule under which a trailing delimiter follows a non-empty final field;
 * it is a typo in the source PDF, not a real trailing-empty-field-23. This formatter
 * never emits one — see Cp8dLineTest for the reasoning and the resulting one-character
 * deviation from that example's literal line.
 */
final class Cp8dLine
{
    /** Fields whose sen is dropped (truncated, not rounded). */
    private const INTEGER_MONEY_FIELDS = [9, 10, 11, 12, 13, 14, 15, 17, 21, 22];

    /** Fields that keep sen, to 2dp. */
    private const DECIMAL_MONEY_FIELDS = [16, 18, 19, 20];

    /**
     * @param  array{
     *     name: string, tin: ?string, identification: string, category: int,
     *     employee_status: ?int, retirement_or_end_date: ?Carbon,
     *     tax_borne_by_employer: ?int, children_count: ?int,
     *     total_qualifying_child_relief: ?float, total_gross_remuneration: ?float,
     *     benefits_in_kind: ?float, living_accommodation: ?float, esos_benefit: ?float,
     *     tax_exempt_allowances: ?float, tp1_relief: ?float, tp1_zakat: ?float,
     *     epf_contribution: ?float, zakat_salary_deduction: ?float, mtd: ?float,
     *     cp38: ?float, medical_insurance: ?float, socso_contribution: ?float,
     * }  $row
     */
    public static function format(array $row): string
    {
        $fields = [
            1 => self::pipeSafe($row['name']),
            2 => self::pipeSafe($row['tin'] ?? ''),
            3 => self::pipeSafe($row['identification']),
            4 => (string) $row['category'],
            5 => self::blankIfEmpty($row['employee_status']),
            6 => $row['retirement_or_end_date']?->format('d-m-Y') ?? '',
            7 => self::blankIfEmpty($row['tax_borne_by_employer']),
            8 => self::blankIfEmpty($row['children_count']),
            9 => self::money($row['total_qualifying_child_relief'], 9),
            10 => self::money($row['total_gross_remuneration'], 10),
            11 => self::money($row['benefits_in_kind'], 11),
            12 => self::money($row['living_accommodation'], 12),
            13 => self::money($row['esos_benefit'], 13),
            14 => self::money($row['tax_exempt_allowances'], 14),
            15 => self::money($row['tp1_relief'], 15),
            16 => self::money($row['tp1_zakat'], 16),
            17 => self::money($row['epf_contribution'], 17),
            18 => self::money($row['zakat_salary_deduction'], 18),
            19 => self::money($row['mtd'], 19),
            20 => self::money($row['cp38'], 20),
            21 => self::money($row['medical_insurance'], 21),
            22 => self::money($row['socso_contribution'], 22),
        ];

        return implode('|', $fields);
    }

    /** LHDNM filename: P<employer TIN, no "E" prefix>_<year>.txt (spec Part A, Note 2). */
    public static function filename(string $employerTin, int $year): string
    {
        return "P{$employerTin}_{$year}.txt";
    }

    private static function money(?float $amount, int $field): string
    {
        if ($amount === null || abs($amount) < 0.005) {
            return '';
        }

        if (in_array($field, self::INTEGER_MONEY_FIELDS, true)) {
            // Round to 4dp first to clear float noise (e.g. 50000.70 stored as
            // 50000.699999999997), then floor — truncation, never rounding.
            return (string) (int) floor(round($amount, 4));
        }

        return number_format($amount, 2, '.', '');
    }

    private static function blankIfEmpty(?int $value): string
    {
        return $value === null || $value === 0 ? '' : (string) $value;
    }

    /** A `|` inside a variable-character field would corrupt the record — strip it. */
    private static function pipeSafe(string $value): string
    {
        return str_replace('|', ' ', $value);
    }
}
