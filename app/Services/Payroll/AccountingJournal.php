<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Payslip;
use Illuminate\Support\Collection;

/**
 * Spec F16: the double-entry journal for one finalized run, summed from its payslips.
 * Amounts come straight off the payslips and are never editable; only the account
 * codes are the tenant's to set.
 */
class AccountingJournal
{
    /** Line key => [account name, side]. The key is what the tenant maps a code to. */
    public const LINES = [
        'salaries' => ['Salaries and wages', 'debit'],
        'allowances' => ['Allowances', 'debit'],
        'epf_employer' => ['Employer EPF', 'debit'],
        'socso_employer' => ['Employer SOCSO', 'debit'],
        'eis_employer' => ['Employer EIS', 'debit'],
        'hrdf_levy' => ['HRD Corp levy', 'debit'],
        'claims' => ['Staff claims reimbursed', 'debit'],
        'salary_advance' => ['Salary advance paid', 'debit'],
        'net_pay' => ['Net pay payable', 'credit'],
        'epf_payable' => ['EPF payable', 'credit'],
        'socso_payable' => ['SOCSO payable', 'credit'],
        'eis_payable' => ['EIS payable', 'credit'],
        'pcb_payable' => ['PCB and CP38 payable', 'credit'],
        'zakat_payable' => ['Zakat payable', 'credit'],
        'hrdf_payable' => ['HRD Corp payable', 'credit'],
        'other_deductions' => ['Loan recoveries and other deductions', 'credit'],
        'salary_advance_recovered' => ['Salary advance recovered', 'credit'],
    ];

    /**
     * Amount per line key, in sen so the sums stay exact.
     *
     * A mid-month run's pay is an advance, not wages yet: it is debited to the salary
     * advance asset instead of salaries. The month-end run books the full month's wages
     * and credits the advance back out of that asset, so across both runs wages expense
     * is the month-end gross and the advance account nets to zero.
     *
     * @param  Collection<int, Payslip>  $payslips
     * @return array<string, int>
     */
    public static function totals(Collection $payslips, bool $midMonth = false): array
    {
        $sen = fn (string ...$columns): int => (int) $payslips->sum(
            fn (Payslip $p) => array_sum(array_map(fn (string $c) => (int) round((float) $p->{$c} * 100), $columns))
        );

        $statutoryEmployee = $sen('epf_employee', 'socso_employee', 'eis_employee', 'skbbk_employee', 'pcb', 'pcb_additional', 'zakat', 'cp38');

        $wages = $sen('gross') - $sen('allowances_total');

        return [
            'salaries' => $midMonth ? 0 : $wages,
            'allowances' => $sen('allowances_total'),
            'epf_employer' => $sen('epf_employer'),
            'socso_employer' => $sen('socso_employer'),
            'eis_employer' => $sen('eis_employer'),
            'hrdf_levy' => $sen('hrdf_levy'),
            'claims' => $sen('claims_reimbursement'),
            'salary_advance' => $midMonth ? $wages : 0,
            'net_pay' => $sen('net_pay'),
            'epf_payable' => $sen('epf_employee', 'epf_employer'),
            'socso_payable' => $sen('socso_employee', 'skbbk_employee', 'socso_employer'),
            'eis_payable' => $sen('eis_employee', 'eis_employer'),
            'pcb_payable' => $sen('pcb', 'pcb_additional', 'cp38'),
            'zakat_payable' => $sen('zakat'),
            'hrdf_payable' => $sen('hrdf_levy'),
            // Whatever was deducted beyond the statutory lines: loans, advances, one-offs.
            // Taken from total_deductions so a carried-forward shortfall is already out.
            'other_deductions' => $sen('total_deductions') - $statutoryEmployee - $sen('mid_month_advance'),
            'salary_advance_recovered' => $sen('mid_month_advance'),
        ];
    }

    /** True when debits equal credits to the sen. */
    public static function balanced(array $totals): bool
    {
        $side = fn (string $side): int => array_sum(array_map(
            fn (string $key) => self::LINES[$key][1] === $side ? $totals[$key] : 0,
            array_keys(self::LINES),
        ));

        return $side('debit') === $side('credit');
    }

    /**
     * CSV rows, header first. Zero lines are left out.
     *
     * @param  array<string, int>  $totals
     * @param  array<string, string>  $accountCodes
     * @return list<list<string>>
     */
    public static function rows(array $totals, array $accountCodes, string $date, string $description): array
    {
        $rows = [['Date', 'Account Code', 'Account Name', 'Description', 'Debit', 'Credit']];
        foreach (self::LINES as $key => [$name, $side]) {
            if ($totals[$key] === 0) {
                continue;
            }
            $amount = number_format($totals[$key] / 100, 2, '.', '');
            $rows[] = [$date, $accountCodes[$key] ?? '', $name, $description, $side === 'debit' ? $amount : '', $side === 'credit' ? $amount : ''];
        }

        return $rows;
    }
}
