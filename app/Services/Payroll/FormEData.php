<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\Payslip;
use App\Models\Tenant;

/**
 * Data layer for Form E (C.P.8 - Pin. 2025) — the employer's annual return itself, as
 * opposed to the C.P.8D employee schedule (Cp8dData/Cp8dLine) it's filed alongside. See
 * docs/statutory/form-e-sample-2025.pdf for the section numbers and wording this class
 * follows: "BASIC PARTICULARS" items 1-13, and Part A's six employee counts. Part B (tax
 * agent particulars) and Part C (declaration) are for hand completion — this class does
 * not touch them; the PDF renders them blank.
 *
 * Fields this app has NO STORAGE for at all are always null: category of employer (item
 * 3 — government/statutory/local authority/private/special class; NOT the same thing as
 * Tenant::companyCategory, which is a subscription-plan tier, not a legal classification
 * — never conflate the two), status of employer (4), TIN type code (5), passport no. (7,
 * mirrors Form EA's own gap), SSM/other registration no. (8 — Tenant::registration_number
 * is close but unverified as the same number LHDN wants here), postcode/city/state/
 * country (9 — Tenant::address is one free-text line, never split into these), and
 * handphone no. (11, distinct from the landline Tenant::contact_number already covers
 * as item 10).
 */
final class FormEData
{
    /** @return array<string, mixed> */
    public function build(Tenant $tenant, int $year): array
    {
        return [
            'year' => $year,
            'basic_particulars' => [
                'name' => $tenant->name,
                'employer_tin' => $tenant->employer_tin,
                'category_of_employer' => null,
                'status_of_employer' => null,
                'tin_type_code' => null,
                'identification_no' => null,
                'passport_no' => null,
                'ssm_registration_no' => $tenant->registration_number,
                'address' => $tenant->address,
                'postcode' => null,
                'city' => null,
                'state' => null,
                'country' => null,
                'telephone' => $tenant->contact_number,
                'handphone' => null,
                'email' => $tenant->email,
                // We ARE the e-Data Praisi / e-CP8D route (Cp8dLine's text file) — a
                // true statement about how this app furnishes it, not a guess.
                'furnish_of_cp8d' => 1,
            ],
            'part_a' => $this->partA($tenant, $year),
            'incomplete' => $this->incompleteFields($tenant),
        ];
    }

    /**
     * Part A's six counts, each derived from employment dates and finalized payroll
     * history — never guessed. A1/A3/A4 come straight off Employee dates; A2 needs
     * finalized payslips (a draft run can still change, so it must never feed a
     * statutory count — same rule as EaFormData/PcbYearToDate); A5/A6 have no
     * corresponding data at all (see incompleteFields()) and are always null.
     *
     * @return array{a1: int, a2: int, a3: int, a4: int, a5: ?int, a6: ?int}
     */
    private function partA(Tenant $tenant, int $year): array
    {
        $yearStart = "{$year}-01-01";
        $yearEnd = "{$year}-12-31";

        // A1 — employed at any point up to and including 31 December, and either still
        // employed or left after that date (an employee who left mid-year is excluded).
        $a1 = Employee::where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->whereNull('joined_at')->orWhere('joined_at', '<=', $yearEnd))
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhere('archived_at', '>', $yearEnd))
            ->count();

        // A2 — distinct employees with any MTD actually deducted on a finalized payslip
        // this year (normal + additional, same combined figure EaFormData reports).
        $a2 = Payslip::where('tenant_id', $tenant->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('status', 'finalized')->where('period', 'like', $year.'-%'))
            ->where(fn ($q) => $q->where('pcb', '>', 0)->orWhere('pcb_additional', '>', 0))
            ->distinct('employee_id')->count('employee_id');

        // A3 — joined within the calendar year.
        $a3 = Employee::where('tenant_id', $tenant->id)
            ->whereBetween('joined_at', [$yearStart, $yearEnd])
            ->count();

        // A4 — ceased employment within the calendar year. This app has no separate
        // "died" reason on Employee — lumped into the same count the PDF itself lumps
        // them into ("ceased employment / died").
        $a4 = Employee::where('tenant_id', $tenant->id)
            ->where('status', 'resigned')
            ->whereBetween('archived_at', [$yearStart.' 00:00:00', $yearEnd.' 23:59:59'])
            ->count();

        return ['a1' => $a1, 'a2' => $a2, 'a3' => $a3, 'a4' => $a4, 'a5' => null, 'a6' => null];
    }

    /** @return list<array{box: string, label: string}> */
    private function incompleteFields(Tenant $tenant): array
    {
        $gaps = [
            ['box' => 'Item 3', 'label' => 'Category of employer'],
            ['box' => 'Item 4', 'label' => 'Status of employer'],
            ['box' => 'Item 5', 'label' => 'Tax Identification No. (TIN) type code'],
            ['box' => 'Item 7', 'label' => 'Passport no.'],
            ['box' => 'Item 9', 'label' => 'Postcode / city / state / country'],
            ['box' => 'Item 11', 'label' => 'Handphone no.'],
            [
                'box' => 'A5 / A6',
                'label' => 'Employees who ceased employment and left Malaysia, and whether reported to LHDNM',
            ],
        ];

        if (blank($tenant->employer_tin)) {
            array_unshift($gaps, ['box' => 'Item 2', 'label' => "Employer's TIN"]);
        }
        if (blank($tenant->registration_number)) {
            $gaps[] = ['box' => 'Item 8', 'label' => 'Registration no. with SSM or others'];
        }

        return $gaps;
    }
}
