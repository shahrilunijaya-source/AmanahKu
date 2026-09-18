<?php

declare(strict_types=1);

namespace App\Services\Payroll\Statutory;

use App\Models\Payslip;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * Spec F10: a month may now hold several finalized runs (the monthly one, a bonus run,
 * a leaver's final pay), but every agency wants ONE file per employer per month. This
 * folds all of them into one payslip per employee, with the amount columns added up.
 *
 * The payslips it returns are never saved — they exist only to be handed to a
 * StatutoryFile exporter, so those keep the signature they already have.
 */
final class MergedPayslips
{
    /**
     * Amount columns the agency files read. Anything not listed here (overrides, day
     * counts, flags) belongs to a single run and is meaningless once summed.
     *
     * @var list<string>
     */
    private const SUMMED = [
        'basic', 'allowances_total', 'overtime_amount', 'bonus', 'unpaid_deduction',
        'gross', 'epf_employee', 'epf_employer', 'socso_employee', 'socso_employer',
        'eis_employee', 'eis_employer', 'skbbk_employee', 'pcb', 'pcb_additional',
        'zakat', 'cp38', 'hrdf_levy', 'total_deductions', 'net_pay',
    ];

    /** @return Collection<int, Payslip> */
    public static function forPeriod(Tenant $tenant, string $period): Collection
    {
        $payslips = Payslip::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->with('employee.salaryStructure')
            ->whereHas('payrollRun', fn ($q) => $q->where('tenant_id', $tenant->id)
                ->where('period', $period)->where('status', 'finalized'))
            ->get();

        return $payslips->groupBy('employee_id')
            ->map(function (Collection $slips): Payslip {
                /** @var Payslip $first */
                $first = $slips->first();
                if ($slips->count() === 1) {
                    return $first;
                }

                $merged = new Payslip;
                $merged->forceFill(['tenant_id' => $first->tenant_id, 'employee_id' => $first->employee_id]
                    + array_combine(self::SUMMED, array_map(
                        fn (string $column) => round($slips->sum(fn (Payslip $p) => (float) $p->{$column}), 2),
                        self::SUMMED,
                    )));
                $merged->setRelation('employee', $first->employee);

                return $merged;
            })
            ->sortBy(fn (Payslip $p) => $p->employee?->name)->values();
    }
}
