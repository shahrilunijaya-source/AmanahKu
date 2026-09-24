<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollOpeningFigure;
use App\Models\Payslip;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * Data layer for Form EA (C.P.8A), the annual remuneration statement every Malaysian
 * employer must give every employee by 28 February. This class does NOT know the
 * official box numbering (B1a, B1b, ...) — nobody on this project has sourced the real
 * LHDN form yet. Employment income is grouped by whatever string sits in the tenant's
 * own `PayrollItem.ea_box` column (recorded since the payroll-items catalogue was
 * built, previously unread by any code — see PayrollItem::SYSTEM_ITEMS), with a
 * catch-all "unclassified" bucket for anything that has none. A renderer that knows the
 * real form maps these buckets onto boxes later; this class must never guess.
 *
 * Only finalized payroll runs count — a draft/approved run can still change, so it must
 * never appear on a statutory form (same rule as PcbYearToDate/PayslipYearToDate).
 *
 * A take-on row (PayrollOpeningFigure::isTakeOn(): what THIS company paid through its
 * old payroll system earlier in the year) IS this employer's pay, so its figures are
 * added into the totals below, box by box.
 *
 * Previous-employer figures (PayrollOpeningFigure, LHDN's Form TP3 in database form) are
 * returned as their OWN top-level component, never merged into this employer's totals.
 * LHDN's own EA covers only what THIS employer paid in the calendar year; a renderer
 * that wants a "total income for the year including the previous employer" figure must
 * add these together itself, deliberately, rather than have this class hide the
 * distinction.
 */
final class EaFormData
{
    /**
     * @return array<string, mixed>
     */
    public function forEmployee(Tenant $tenant, Employee $employee, int $year): array
    {
        if ($employee->tenant_id !== $tenant->id) {
            throw new \InvalidArgumentException('Employee does not belong to the given tenant.');
        }

        $payslips = Payslip::where('tenant_id', $tenant->id)
            ->where('employee_id', $employee->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('status', 'finalized')->countsAsRemuneration()
                ->where('period', 'like', $year.'-%'))
            ->with('lines.payrollItem')
            ->get();

        $opening = PayrollOpeningFigure::where('tenant_id', $tenant->id)
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->first();

        $takeOn = $opening !== null && $opening->isTakeOn() ? $opening : null;
        $previous = $takeOn === null ? $opening : null;

        return [
            'year' => $year,
            // Employer fields the form needs. `income_tax_reference_no` is null because
            // no such column exists on `tenants` yet (only the PREVIOUS employer's TIN is
            // captured, on payroll_opening_figures, for Form TP3) — must be collected
            // before Form EA can actually be issued.
            'employer' => [
                'name' => $tenant->name,
                'address' => $tenant->address,
                'income_tax_reference_no' => null,
            ],
            'employee' => [
                'name' => $employee->name,
                'staff_id' => $employee->staff_id,
                'nric' => $employee->nric,
                'income_tax_no' => $employee->salaryStructure?->tax_no,
            ],
            'employment_income' => $this->employmentIncome($payslips, $takeOn),
            'deductions' => $this->deductions($payslips, $takeOn),
            // Take-on boxes nothing in a pay run produces; EaFormPdfData prints them as is.
            'take_on' => $takeOn === null ? null : [
                'd4' => $takeOn->line('d4'),
                'd5a' => (float) $takeOn->optional_deductions,
                'd5b' => $takeOn->line('d5b'),
                'd6' => $takeOn->line('d6'),
            ] + array_intersect_key($takeOn->ea_lines ?? [], array_flip(PayrollOpeningFigure::EA_TEXT)),
            'previous_employment' => $previous === null ? null : [
                'previous_employer' => $previous->previous_employer,
                'previous_employer_tin' => $previous->previous_employer_tin,
                'gross' => (float) $previous->gross,
                'additional_gross' => (float) $previous->additional_gross,
                'epf' => (float) $previous->epf,
                'additional_epf' => (float) $previous->additional_epf,
                'socso' => (float) $previous->socso,
                'eis' => (float) $previous->eis,
                'zakat_paid' => (float) $previous->zakat_paid,
                'pcb_paid' => (float) $previous->pcb_paid,
                // Form TP3 section C2 — recorded specifically "for the EA form only" (see
                // the model's own ponytail comment); never wired into PcbYearToDate/PCB.
                'exempt_allowances' => (float) $previous->exempt_allowances,
            ],
        ];
    }

    /**
     * @param  Collection<int, Payslip>  $payslips
     * @param  ?PayrollOpeningFigure  $takeOn  this employer's own pay before the app, added box by box
     * @return array{by_category: array<string, float>, overtime_total: float, taxable_total: float, tax_exempt_total: float, exempt_cap_candidates_total: float}
     */
    private function employmentIncome(Collection $payslips, ?PayrollOpeningFigure $takeOn): array
    {
        $byCategory = [];
        $overtimeTotal = 0.0;
        $taxableTotal = 0.0;
        $exemptTotal = 0.0;
        $capCandidateTotal = 0.0;

        foreach ($payslips as $payslip) {
            if ($payslip->lines->isNotEmpty()) {
                $earnings = $payslip->lines->where('type', 'earning')
                    // Claim reimbursement is not wages (see PayslipPdfData/Payslip docblocks).
                    ->reject(fn ($line) => $line->source === 'claim');

                foreach ($earnings as $line) {
                    $item = $line->payrollItem;
                    $category = ($item !== null ? $item->ea_box : null) ?? 'unclassified';
                    $byCategory[$category] = ($byCategory[$category] ?? 0.0) + (float) $line->amount;

                    if ($line->source === 'overtime') {
                        $overtimeTotal += (float) $line->amount;
                    }

                    // No catalogue item (legacy line, or item since deleted) defaults to
                    // taxable — the same default PayrollItem::SYSTEM_ITEMS uses for its own
                    // catch-all "Other Addition" item, and the safe direction to guess on a
                    // tax form.
                    if ($item?->pcb_taxable === false) {
                        $exemptTotal += (float) $line->amount;
                    } else {
                        $taxableTotal += (float) $line->amount;

                        // Taxable but capped (e.g. the RM6,000/yr travel allowance): the
                        // cap itself is a known separate gap (never applied here), but the
                        // raw amount attributable to a capped item must still surface, not
                        // sit invisibly inside taxable_total — a later pass needs a figure
                        // to apply the cap to.
                        if ($item?->pcb_exempt_cap_yearly !== null) {
                            $capCandidateTotal += (float) $line->amount;
                        }
                    }
                }

                continue;
            }

            // Payslip issued before PayslipLine existed: no per-item ea_box or pcb_taxable
            // data survives for it, so its whole earnings total lands in "unclassified"
            // and is treated as taxable.
            $legacyTotal = (float) $payslip->basic + (float) $payslip->allowances_total
                + (float) $payslip->bonus + (float) $payslip->overtime_amount
                + collect($payslip->additions ?? [])->sum('amount');

            $byCategory['unclassified'] = ($byCategory['unclassified'] ?? 0.0) + $legacyTotal;
            $overtimeTotal += (float) $payslip->overtime_amount;
            $taxableTotal += $legacyTotal;
        }

        // Spec F8: the part of the year's pay that a Payroll Item's yearly cap exempted
        // (payslips.pcb_exempt_amount, computed month by month when the run was built).
        // Part F of Form EA reports it, and it must come back out of the taxable total.
        $cappedExempt = round((float) $payslips->sum('pcb_exempt_amount'), 2);
        $taxableTotal = max(0.0, $taxableTotal - $cappedExempt);
        $exemptTotal += $cappedExempt;

        if ($takeOn !== null) {
            $boxes = ['B1(a)' => (float) $takeOn->gross, 'B1(b)' => (float) $takeOn->additional_gross,
                'B1(c)' => $takeOn->line('b1c'), 'B1(d)' => $takeOn->line('b1d'), 'B1(e)' => $takeOn->line('b1e'), 'B1(f)' => $takeOn->line('b1f'),
                'B2' => $takeOn->line('b2'), 'B3' => $takeOn->line('b3'), 'B4' => $takeOn->line('b4'), 'B5' => $takeOn->line('b5'), 'B6' => $takeOn->line('b6')];
            foreach (array_filter($boxes) as $box => $amount) {
                $byCategory[$box] = ($byCategory[$box] ?? 0.0) + $amount;
                $taxableTotal += $amount;
            }
            // Section C (pension, annuities) is not employment income, so it has its own boxes.
            foreach (array_filter(['C1' => $takeOn->line('c1'), 'C2' => $takeOn->line('c2')]) as $box => $amount) {
                $byCategory[$box] = ($byCategory[$box] ?? 0.0) + $amount;
            }
            $exemptTotal += (float) $takeOn->exempt_allowances + $takeOn->line('f2') + $takeOn->line('f3') + $takeOn->line('f4');
        }

        return [
            'by_category' => array_map(fn (float $v) => round($v, 2), $byCategory),
            // Informational only — already included in one of the by_category totals
            // above (normally B1(a), alongside salary — LHDN wants overtime folded into
            // "gross salary, wages or leave pay" on Form EA, not reported separately),
            // never additive on top of them.
            'overtime_total' => round($overtimeTotal, 2),
            'taxable_total' => round($taxableTotal, 2),
            // Form EA Part F: items that are never taxable, plus the part of a capped
            // item (e.g. the RM6,000/year travel allowance) the cap actually exempted.
            'tax_exempt_total' => round($exemptTotal, 2),
            // The raw amount paid through items that carry a pcb_exempt_cap_yearly,
            // before the cap. Whatever part of it the cap exempted is already out of
            // taxable_total and inside tax_exempt_total above.
            'exempt_cap_candidates_total' => round($capCandidateTotal, 2),
        ];
    }

    /**
     * @param  Collection<int, Payslip>  $payslips
     * @return array{epf_employee: float, socso_employee: float, eis_employee: float, skbbk_employee: float, zakat: float, pcb_total: float, cp38: float}
     */
    private function deductions(Collection $payslips, ?PayrollOpeningFigure $takeOn): array
    {
        return [
            'epf_employee' => round((float) $payslips->sum('epf_employee') + (float) $takeOn?->epf + (float) $takeOn?->additional_epf, 2),
            'socso_employee' => round((float) $payslips->sum('socso_employee') + (float) $takeOn?->socso, 2),
            'eis_employee' => round((float) $payslips->sum('eis_employee') + (float) $takeOn?->eis, 2),
            'skbbk_employee' => round((float) $payslips->sum('skbbk_employee') + (float) $takeOn?->line('skbbk'), 2),
            'zakat' => round((float) $payslips->sum('zakat') + (float) $takeOn?->zakat_paid, 2),
            // Normal + additional/bonus PCB reported as one figure, the way LHDN wants it.
            'pcb_total' => round((float) $payslips->sum(fn (Payslip $p) => $p->pcb + $p->pcb_additional) + (float) $takeOn?->pcb_paid, 2),
            // CP38 is an instalment against an older tax debt, never part of the year's
            // PCB deducted — reported separately, never folded into pcb_total.
            'cp38' => round((float) $payslips->sum('cp38') + (float) $takeOn?->line('d2'), 2),
        ];
    }
}
