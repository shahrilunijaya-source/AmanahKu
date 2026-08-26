<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\Tenant;

/**
 * Maps EaFormData's box-agnostic figures onto the real Form C.P.8A (Pin. 2024) layout —
 * see docs/statutory/form-e-sample-2025.pdf for the official section letters, numbers
 * and wording this class and ea-form.blade.php follow exactly. EaFormData itself must
 * never know the box numbering (its own docblock says so); this class is that renderer.
 *
 * A box with nothing behind it prints BLANK, never "0.00" — real forms do the same, and
 * a printed zero would wrongly claim "nothing was paid" rather than "we don't track this
 * yet". STATIC_INCOMPLETE_BOXES lists every box this app has NO STORAGE for at all, for
 * any tenant or employee. Employer's TIN and Employer's Telephone No. are NOT in that
 * list — both live on `tenants` (employer_tin, contact_number) — they are appended to
 * the incomplete list in build() only when that particular tenant hasn't filled them in
 * yet, so the list stays true rather than permanently pessimistic. See the class
 * docblock on EaFormController for where the full incomplete list is surfaced.
 */
final class EaFormPdfData
{
    /**
     * @var list<array{box: string, label: string}>
     */
    public const array STATIC_INCOMPLETE_BOXES = [
        ['box' => 'Header', 'label' => 'Serial No.'],
        ['box' => 'Header', 'label' => 'LHDNM State'],
        ['box' => 'A5', 'label' => 'Passport No.'],
        ['box' => 'A8', 'label' => 'Number of children qualified for tax relief'],
        ['box' => 'B1(d)', 'label' => 'Income tax borne by the employer'],
        ['box' => 'B1(e)', 'label' => 'ESOS benefit'],
        ['box' => 'B1(f)', 'label' => 'Gratuity'],
        ['box' => 'B2', 'label' => 'Arrears and others for preceding years paid in the current year'],
        ['box' => 'B3', 'label' => 'Benefits in kind'],
        ['box' => 'B4', 'label' => 'Value of living accommodation'],
        ['box' => 'B5', 'label' => 'Refund from unapproved provident/pension fund'],
        ['box' => 'B6', 'label' => 'Compensation for loss of employment'],
        ['box' => 'C1', 'label' => 'Pension'],
        ['box' => 'C2', 'label' => 'Annuities or other periodical payments'],
        ['box' => 'D4', 'label' => 'Approved donations/gifts/contributions via salary deduction'],
        ['box' => 'D5(a)', 'label' => 'Total claim for deduction via Form TP1: Relief'],
        ['box' => 'D5(b)', 'label' => 'Total claim for deduction via Form TP1: Zakat other than via salary'],
    ];

    public function __construct(private readonly EaFormData $eaData) {}

    /** @return array<string, mixed> */
    public function build(Tenant $tenant, Employee $employee, int $year): array
    {
        $data = $this->eaData->forEmployee($tenant, $employee, $year);
        $income = $data['employment_income']['by_category'];
        $ded = $data['deductions'];
        $structure = $employee->salaryStructure;

        $yearStart = "{$year}-01-01";
        $yearEnd = "{$year}-12-31";
        $joinedInYear = $employee->joined_at && $employee->joined_at->format('Y-m-d') >= $yearStart
            ? $employee->joined_at : null;
        $leftInYear = $employee->status === 'resigned' && $employee->archived_at
            && $employee->archived_at->format('Y-m-d') <= $yearEnd && $employee->archived_at->year === $year
            ? $employee->archived_at : null;

        return [
            'year' => $year,
            'header' => [
                'serial_no' => null,
                // Stored without the "E" prefix — ea-form.blade.php adds it when printing.
                'employer_tin' => $tenant->employer_tin,
                'lhdnm_state' => null,
                'employee_tin' => $structure?->tax_no,
            ],
            'employer' => [
                'name' => $data['employer']['name'],
                'address' => $data['employer']['address'],
                'telephone' => $tenant->contact_number,
            ],
            'employee' => [
                'name' => $employee->name,
                'designation' => $employee->position,
                'staff_id' => $employee->staff_id,
                'nric' => $employee->nric,
                'passport' => null,
                'epf_no' => $structure?->epf_no,
                'socso_no' => $structure?->socso_no,
                'children' => null,
                'commencement_date' => $joinedInYear,
                'cessation_date' => $leftInYear,
            ],
            'b' => [
                'b1a' => $income['B1(a)'] ?? null,
                'b1b' => $income['B1(b)'] ?? null,
                'b1c' => $income['B1(c)'] ?? null,
                'b1d' => null,
                'b1e' => null,
                'b1f' => null,
                'b2' => null,
                'b3' => null,
                'b4' => null,
                'b5' => null,
                'b6' => null,
            ],
            'c' => [
                'c1' => null,
                'c2' => null,
                'total' => null,
            ],
            'd' => [
                'd1' => $ded['pcb_total'] > 0 ? $ded['pcb_total'] : null,
                'd2' => $ded['cp38'] > 0 ? $ded['cp38'] : null,
                'd3' => $ded['zakat'] > 0 ? $ded['zakat'] : null,
                'd4' => null,
                'd5a' => null,
                'd5b' => null,
                // Computable: children_relief_count holds RELIEF UNITS (RM2,000 each — a
                // child over 18 in higher education counts as multiple units), not a
                // headcount, so this is the only place that number is usable. It must
                // NEVER be printed as box A8's "number of children" — see 'employee.children'
                // above, deliberately left null instead of reusing this figure.
                'd6' => $structure && $structure->children_relief_count > 0
                    ? round($structure->children_relief_count * 2000.0, 2) : null,
            ],
            'e' => [
                'fund_name' => $ded['epf_employee'] > 0 ? 'KWSP / EPF' : null,
                'e1' => $ded['epf_employee'] > 0 ? $ded['epf_employee'] : null,
                // Guide note 7: SOCSO's box E2 includes EIS contributed through SIP —
                // LHDN treats the two as one combined employee contribution figure here,
                // not two separate ones. This is deliberate, not a bug.
                'e2' => ($ded['socso_employee'] + $ded['eis_employee']) > 0
                    ? round($ded['socso_employee'] + $ded['eis_employee'], 2) : null,
            ],
            'f' => $data['employment_income']['tax_exempt_total'] > 0
                ? $data['employment_income']['tax_exempt_total'] : null,
            'previous_employment' => $data['previous_employment'],
            'incomplete' => $this->incompleteBoxes($tenant),
        ];
    }

    /** @return list<array{box: string, label: string}> */
    private function incompleteBoxes(Tenant $tenant): array
    {
        $boxes = self::STATIC_INCOMPLETE_BOXES;

        if (blank($tenant->employer_tin)) {
            array_unshift($boxes, ['box' => 'Header', 'label' => "Employer's TIN"]);
        }
        if (blank($tenant->contact_number)) {
            $boxes[] = ['box' => 'Footer', 'label' => "Employer's Telephone No."];
        }

        return $boxes;
    }
}
