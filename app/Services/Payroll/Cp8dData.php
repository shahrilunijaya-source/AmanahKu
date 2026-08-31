<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\Tenant;
use Carbon\Carbon;

/**
 * Data layer for the C.P.8D text file — the employee schedule that (together with Form
 * E) makes up the employer's annual return, uploaded to LHDN's e-Data Praisi / e-CP8D.
 * See docs/statutory/cp8d-information-layout.pdf Part A for the 22-field layout this
 * class fills; Cp8dLine formats the values this class returns into the pipe-delimited
 * line and applies the sen truncation/rounding rules.
 *
 * Reuses EaFormData for the money figures Form EA already computes correctly (gross
 * remuneration, tax-exempt total, EPF/SOCSO/zakat/MTD/CP38 deductions) rather than
 * re-summing payslips — one source of truth for "what did this employer pay this
 * employee this year". Category of employee (field 4) reuses
 * PcbCalculator::categoryFor for the same reason.
 *
 * Fields this app has NO STORAGE for at all are always null here: tax borne by employer
 * (7), number of children qualified — a HEADCOUNT, distinct from the relief UNITS
 * SalaryStructure.children_relief_count holds (8, same known gap as EA form box A8),
 * benefits in kind (11), living accommodation (12), ESOS benefit (13), TP1 relief (15),
 * TP1 zakat (16), medical insurance via salary deduction (21). FormEController surfaces
 * which of these are COMPULSORY per employee via incompleteFields() below.
 */
final class Cp8dData
{
    /**
     * Employee Status (field 5) mapping from `employment_types.name` to the C.P.8D
     * code. Only names that map cleanly onto one of the spec's six statuses are listed
     * — "Probation" (seeded alongside these) is a hiring phase, not one of LHDN's six
     * employee-status categories, and forcing it onto "Permanent" or "Contract" would
     * misreport a fact LHDN uses for its own purposes. An employment type with no entry
     * here (including "Probation", or no employment_type_id at all) leaves field 5 null
     * — a compulsory gap, surfaced by incompleteFields().
     */
    private const array EMPLOYMENT_TYPE_STATUS = [
        'Permanent' => 2,
        'Contract' => 3,
        'Intern' => 5,
    ];

    public function __construct(private readonly EaFormData $eaData) {}

    /** @return array<string, mixed> */
    public function forEmployee(Tenant $tenant, Employee $employee, int $year): array
    {
        if ($employee->tenant_id !== $tenant->id) {
            throw new \InvalidArgumentException('Employee does not belong to the given tenant.');
        }

        $ea = $this->eaData->forEmployee($tenant, $employee, $year);
        $income = $ea['employment_income'];
        $ded = $ea['deductions'];
        $structure = $employee->salaryStructure;

        $retirementOrEndDate = $employee->status === 'resigned' && $employee->archived_at
            && $employee->archived_at->year === $year
            ? $employee->archived_at : null;

        $employeeStatus = $employee->employmentType
            ? self::EMPLOYMENT_TYPE_STATUS[$employee->employmentType->name] ?? null
            : null;

        return [
            'name' => $employee->name,
            'tin' => $this->digitsOnly($structure?->tax_no),
            'identification' => $this->identification($employee),
            'category' => PcbCalculator::categoryFor($employee, $structure),
            'employee_status' => $employeeStatus,
            'retirement_or_end_date' => $retirementOrEndDate,
            'tax_borne_by_employer' => null,
            'children_count' => null,
            'total_qualifying_child_relief' => $structure && $structure->children_relief_count > 0
                ? round($structure->children_relief_count * 2000.0, 2) : null,
            'total_gross_remuneration' => round($income['taxable_total'] + $income['tax_exempt_total'], 2),
            'benefits_in_kind' => null,
            'living_accommodation' => null,
            'esos_benefit' => null,
            'tax_exempt_allowances' => $income['tax_exempt_total'],
            'tp1_relief' => null,
            'tp1_zakat' => null,
            'epf_contribution' => $ded['epf_employee'],
            'zakat_salary_deduction' => $ded['zakat'],
            'mtd' => $ded['pcb_total'],
            'cp38' => $ded['cp38'],
            'medical_insurance' => null,
            'socso_contribution' => $ded['socso_employee'],
            'incomplete' => $this->incompleteFields($retirementOrEndDate, $employeeStatus),
        ];
    }

    /**
     * Compulsory fields (per the PDF's own "COMPULSARY" markings) this app could not
     * fill for this employee — the file will be rejected by LHDN until these are
     * completed by hand. Non-compulsory known gaps (BIK, accommodation, ESOS, TP1,
     * medical insurance, children headcount) are not listed per-employee here since
     * they never block submission; they're documented on the class instead.
     *
     * @return list<array{field: string, label: string}>
     */
    private function incompleteFields(?Carbon $retirementOrEndDate, ?int $employeeStatus): array
    {
        $gaps = [
            ['field' => 'Field 7', 'label' => 'Tax borne by employer'],
        ];

        if ($employeeStatus === null) {
            $gaps[] = ['field' => 'Field 5', 'label' => 'Employee status'];
        }

        if ($retirementOrEndDate === null) {
            $gaps[] = ['field' => 'Field 6', 'label' => 'Date of retirement / end of contract'];
        }

        return $gaps;
    }

    /**
     * Field 3 — compulsory. Priority to identification card, and LHDN wants bare digits
     * (its own example strips the NRIC's dashes to fit the 12-character length); twelve
     * zeros when the employee has no identification number at all (spec's own rule).
     */
    private function identification(Employee $employee): string
    {
        $digits = preg_replace('/[^A-Za-z0-9]/', '', (string) $employee->nric) ?? '';

        return $digits !== '' ? $digits : str_repeat('0', 12);
    }

    /** Field 2 — LHDN wants bare TIN digits, without the SG/OG-style prefix we store. */
    private function digitsOnly(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        return $digits !== '' ? $digits : null;
    }
}
