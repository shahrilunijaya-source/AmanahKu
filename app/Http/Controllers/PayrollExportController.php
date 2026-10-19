<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PayrollRun;
use App\Models\PayrollSubmission;
use App\Models\Payslip;
use App\Services\FeatureManager;
use App\Services\Payroll\AccountingJournal;
use App\Services\Payroll\BankFile\BankFileRegistry;
use App\Services\Payroll\HrdCorpLevy;
use App\Services\Payroll\Statutory\MergedPayslips;
use App\Services\Payroll\Statutory\StatutoryFileRegistry;
use App\Services\Payroll\Statutory\ZakatMonth;
use App\Support\Csv;
use App\Support\StatutoryOptions;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollExportController extends Controller
{
    private const ADMIN_ROLES = ['management', 'hr'];

    /**
     * Bank payment file for a finalized run: one row per employee with net pay. The
     * layout is selectable via ?format= (generic CSV by default; bank-specific formats
     * via BankFileRegistry). Unverified bank layouts are noted in the audit trail (I-017).
     */
    public function bankFile(Request $request, PayrollRun $run): StreamedResponse
    {
        $this->authorize($request, $run);

        $format = BankFileRegistry::find($request->query('format'));

        $all = $run->payslips()->with('employee.salaryStructure')->get()
            ->sortBy(fn ($p) => $p->employee?->name)->values();
        // Spec F10: a final pay held for an unsettled CP22A is not paid out yet, so it
        // must not reach the bank. It appears here again once HR releases the hold.
        $payslips = $all->reject(fn (Payslip $p) => (bool) $p->held_for_cp22a)->values();
        $withheld = $all->count() - $payslips->count();

        AuditLog::record('Exported bank file', $run->label.' · '.$payslips->count().' employees · '
            .$format->label().($format->verified() ? '' : ' (unverified layout)')
            .($withheld > 0 ? ' · '.$withheld.' withheld pending CP22A' : ''));

        $rows = $format->rows($payslips, $run);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                // Employee names / account fields are user-controlled — neutralise CSV injection.
                fputcsv($out, Csv::safeRow($row));
            }
            fclose($out);
        }, $format->filename($run), ['Content-Type' => 'text/csv']);
    }

    /**
     * EPF/SOCSO/EIS (+ PCB) contribution report for a finalized run — the figures HR
     * reconciles against the KWSP/PERKESO/LHDN submissions. Totals row at the foot.
     */
    public function statutoryReport(Request $request, PayrollRun $run): StreamedResponse
    {
        $this->authorize($request, $run);

        $payslips = $run->payslips()->with('employee.salaryStructure')->get()
            ->sortBy(fn ($p) => $p->employee?->name)->values();

        // NRIC is decrypted into this export — log who pulled the PII (I-018).
        AuditLog::record('Exported statutory report', $run->label.' · '.$payslips->count().' employees · includes NRIC');

        return response()->streamDownload(function () use ($payslips) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Employee', 'NRIC', 'EPF No', 'EPF (Employee)', 'EPF (Employer)',
                'SOCSO No', 'SOCSO (Employee)', 'SOCSO (Employer)',
                'EIS (Employee)', 'EIS (Employer)', 'PCB (Normal)', 'PCB (Additional)', 'Zakat', 'CP38',
            ]);

            $fmt = fn ($v) => number_format((float) $v, 2, '.', '');
            foreach ($payslips as $p) {
                $s = $p->employee?->salaryStructure;
                // Name / NRIC / EPF / SOCSO numbers are user-controlled — neutralise CSV injection.
                // NRIC is the employee record's, not salary_structures' — see the
                // reconcile migration (2026_08_25_200300): one source of truth.
                fputcsv($out, Csv::safeRow([
                    $p->employee?->name, $p->employee?->nric, $s?->epf_no,
                    $fmt($p->epf_employee), $fmt($p->epf_employer),
                    $s?->socso_no, $fmt($p->socso_employee), $fmt($p->socso_employer),
                    $fmt($p->eis_employee), $fmt($p->eis_employer),
                    $fmt($p->pcb), $fmt($p->pcb_additional), $fmt($p->zakat), $fmt($p->cp38),
                ]));
            }

            fputcsv($out, [
                'TOTAL', '', '',
                $fmt($payslips->sum('epf_employee')), $fmt($payslips->sum('epf_employer')),
                '', $fmt($payslips->sum('socso_employee')), $fmt($payslips->sum('socso_employer')),
                $fmt($payslips->sum('eis_employee')), $fmt($payslips->sum('eis_employer')),
                $fmt($payslips->sum('pcb')), $fmt($payslips->sum('pcb_additional')),
                $fmt($payslips->sum('zakat')), $fmt($payslips->sum('cp38')),
            ]);
            fclose($out);
        }, 'statutory-'.$run->period.'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Payroll register for one finalized run: every payslip line per employee (earnings,
     * deductions, employer cost) with a TOTAL row, the sheet HR and finance check a run against.
     * Lines the payslip doesn't store on their own (one-off earnings, loans and other
     * deductions) are derived from gross and total deductions so every row adds up.
     */
    public function register(Request $request, PayrollRun $run): StreamedResponse
    {
        $this->authorize($request, $run);

        $payslips = $run->payslips()->with('employee.department')->get()
            ->sortBy(fn ($p) => $p->employee?->name)->values();

        AuditLog::record('Exported payroll register', $run->label.' · '.$payslips->count().' employees');

        $money = ['basic', 'allowances', 'overtime', 'bonus', 'other_earnings', 'unpaid', 'gross',
            'epf', 'socso', 'eis', 'skbbk', 'pcb', 'zakat', 'cp38', 'advance', 'other_deductions', 'total_deductions',
            'claims', 'net', 'epf_er', 'socso_er', 'eis_er', 'hrdf', 'cost'];
        $rows = $payslips->map(fn (Payslip $p) => array_combine($money, self::registerLine($p)));

        return response()->streamDownload(function () use ($payslips, $rows, $money) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Staff ID', 'Employee', 'Department',
                'Basic', 'Fixed allowances', 'Overtime', 'Bonus', 'Other earnings', 'Unpaid leave', 'Gross pay',
                'EPF', 'SOCSO', 'EIS', 'SKBBK', 'PCB', 'Zakat', 'CP38', 'Mid-month advance', 'Loans & other', 'Total deductions',
                'Claims reimbursed', 'Net pay',
                'EPF (Employer)', 'SOCSO (Employer)', 'EIS (Employer)', 'HRDF levy', 'Total employer cost',
            ]);
            $fmt = fn (float $v) => number_format($v, 2, '.', '');
            foreach ($payslips as $i => $p) {
                // Only the text columns are user-controlled; the figures are ours, and a
                // negative one must stay a number for Excel, not be quoted as a formula.
                fputcsv($out, [
                    ...Csv::safeRow([$p->employee?->staff_id, $p->employee?->name, $p->employee?->department?->name]),
                    ...array_map($fmt, array_values($rows[$i])),
                ]);
            }
            fputcsv($out, ['TOTAL', '', '', ...array_map(fn ($k) => $fmt($rows->sum($k)), $money)]);
            fclose($out);
        }, 'payroll-register-'.$run->period.'-'.$run->kind.'-'.$run->id.'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Payroll summary for one year: a row per month over every finalized run in it. A
     * mid-month run is an advance on that month's wages, so its gross is not counted twice:
     * gross, deductions and employer cost come from the other runs, while net paid adds every
     * run (the advance went out mid-month, the month-end run paid the rest).
     */
    public function summary(Request $request, int $year): StreamedResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);

        $runs = PayrollRun::where('tenant_id', app(CurrentTenant::class)->id())
            ->where('status', 'finalized')->where('period', 'like', $year.'-%')
            ->with('payslips')->orderBy('period')->get();

        $months = $runs->groupBy('period')->map(function ($group, string $period) {
            $all = $group->flatMap->payslips;
            $pay = $group->reject->isMidMonth()->flatMap->payslips;
            $statutory = $pay->sum('epf_employee') + $pay->sum('socso_employee') + $pay->sum('eis_employee') + $pay->sum('skbbk_employee');
            $tax = $pay->sum(fn ($p) => (float) ($p->pcb_override ?? $p->pcb) + (float) $p->pcb_additional) + $pay->sum('zakat') + $pay->sum('cp38');

            return [
                'month' => $period,
                'staff' => $all->pluck('employee_id')->unique()->count(),
                'gross' => (float) $pay->sum('gross'),
                'statutory' => (float) $statutory,
                'tax' => (float) $tax,
                'other' => (float) ($pay->sum('total_deductions') - $pay->sum('mid_month_advance') - $statutory - $tax),
                'claims' => (float) $all->sum('claims_reimbursement'),
                'net' => (float) $all->sum('net_pay'),
                'employer' => (float) ($pay->sum('epf_employer') + $pay->sum('socso_employer') + $pay->sum('eis_employer') + $pay->sum('hrdf_levy')),
                'cost' => (float) $pay->sum('employer_cost'),
            ];
        })->values();

        AuditLog::record('Exported payroll summary', $year.' · '.$months->count().' months');

        return response()->streamDownload(function () use ($months) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Month', 'Staff paid', 'Gross pay', 'EPF + SOCSO + EIS (Employee)', 'PCB + zakat + CP38',
                'Loans & other', 'Claims reimbursed', 'Net paid', 'Employer contributions', 'Total employer cost']);
            $fmt = fn (float $v) => number_format($v, 2, '.', '');
            $keys = ['gross', 'statutory', 'tax', 'other', 'claims', 'net', 'employer', 'cost'];
            foreach ($months as $m) {
                fputcsv($out, [$m['month'], $m['staff'], ...array_map(fn ($k) => $fmt($m[$k]), $keys)]);
            }
            fputcsv($out, ['TOTAL', '', ...array_map(fn ($k) => $fmt((float) $months->sum($k)), $keys)]);
            fclose($out);
        }, 'payroll-summary-'.$year.'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * One register row's money columns, in header order.
     *
     * @return list<float>
     */
    private static function registerLine(Payslip $p): array
    {
        $f = fn (string $k) => (float) $p->{$k};
        // Additions and one-off individual earnings have no column of their own, so they are what gross has left.
        $otherEarnings = $f('gross') - $f('basic') - $f('allowances_total') - $f('overtime_amount') - $f('bonus') + $f('unpaid_deduction');
        $pcb = (float) ($p->pcb_override ?? $p->pcb) + $f('pcb_additional');
        $named = $f('epf_employee') + $f('socso_employee') + $f('eis_employee') + $f('skbbk_employee')
            + $pcb + $f('zakat') + $f('cp38') + $f('mid_month_advance');

        return [
            $f('basic'), $f('allowances_total'), $f('overtime_amount'), $f('bonus'), round($otherEarnings, 2), -$f('unpaid_deduction'), $f('gross'),
            $f('epf_employee'), $f('socso_employee'), $f('eis_employee'), $f('skbbk_employee'), $pcb, $f('zakat'), $f('cp38'),
            $f('mid_month_advance'), round($f('total_deductions') - $named, 2), $f('total_deductions'),
            $f('claims_reimbursement'), $f('net_pay'),
            $f('epf_employer'), $f('socso_employer'), $f('eis_employer'), $f('hrdf_levy'), $f('employer_cost'),
        ];
    }

    /** management/hr only, own tenant, finalized runs only (drafts aren't submittable). */
    /**
     * Agency upload file (spec F6/F7) for a finalized run: KWSP Form A, PERKESO Borang 8A,
     * LHDN CP39 or the HRD Corp levy file. Every one carries NRICs, so every download is
     * audited; an unverified layout is named as such in the trail.
     */
    public function statutoryFile(Request $request, PayrollRun $run, string $key): StreamedResponse
    {
        $this->authorize($request, $run);
        $file = StatutoryFileRegistry::find($key) ?? abort(404);
        $tenant = app(CurrentTenant::class)->get();
        if ($key === 'hrdcorp') {
            abort_if(HrdCorpLevy::rate((string) app(FeatureManager::class)->value($tenant, 'payroll.hrdf')) <= 0, 422, 'HRD Corp levy is switched off for this company.');
        }
        $gap = $file->missingEmployerNumber($tenant);
        abort_if($gap !== null, 422, (string) $gap);

        // Spec F10: one file per employer per month, whatever the month was paid in —
        // the monthly run, a bonus run and a leaver's final pay are folded into one row
        // per employee before the exporter sees them.
        $payslips = $tenant === null
            ? collect()
            : MergedPayslips::forPeriod($tenant, $run->period, $file->includesBonusRuns());
        $body = $file->build($run, $tenant, $payslips);

        // Spec F12: the first download of a statutory file marks that filing "file ready".
        $agency = ['kwsp-form-a' => 'epf', 'perkeso-8a' => 'socso_eis', 'cp39' => 'pcb', 'hrdcorp' => 'hrdcorp'][$key] ?? null;
        if ($agency !== null) {
            // One filing per agency per month (StatutoryCalendar::open matches on the
            // period), so the row may hang off the monthly run while the download is
            // started from the bonus or final run of the same month.
            PayrollSubmission::whereHas('payrollRun', fn ($q) => $q->where('period', $run->period))
                ->where('agency', $agency)
                ->whereNull('downloaded_at')->update(['downloaded_at' => now()]);
        }

        AuditLog::record('Exported statutory file', $run->label.' · '.$file->label().' · '.$payslips->count().' employees · includes NRIC'.($file->verified() ? '' : ' (unverified layout)'));

        return response()->streamDownload(function () use ($body) {
            echo $body;
        }, $file->filename($run, $tenant), ['Content-Type' => $file->contentType()]);
    }

    /**
     * Spec F16: double-entry journal CSV for one finalized run, for import into SQL
     * Account or AutoCount. Refused outright if debits and credits do not match.
     */
    public function journal(Request $request, PayrollRun $run): StreamedResponse
    {
        $this->authorize($request, $run);

        $totals = AccountingJournal::totals($run->payslips()->get(), $run->isMidMonth());
        abort_unless(AccountingJournal::balanced($totals), 422, 'Journal does not balance. Nothing was exported.');

        $codes = app(CurrentTenant::class)->get()->journal_accounts ?? [];
        $date = ($run->payment_date ?? $run->finalized_at)->toDateString();
        $rows = AccountingJournal::rows($totals, $codes, $date, 'Payroll '.$run->label);

        AuditLog::record('Exported accounting journal', $run->label.' · '.(count($rows) - 1).' lines');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, Csv::safeRow($row));
            }
            fclose($out);
        }, 'journal-'.$run->period.'-'.$run->kind.'-'.$run->id.'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Payroll → Form → Zakat as CSV, for whichever authority is filtered. Carries NRICs, so audited. */
    public function zakat(Request $request, string $period): StreamedResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $authority = $request->query('authority');
        abort_unless($authority === null || $authority === ZakatMonth::NONE || array_key_exists($authority, StatutoryOptions::ZAKAT_AUTHORITIES), 404);

        $data = ZakatMonth::for(app(CurrentTenant::class)->get(), $period, $authority);
        AuditLog::record('Exported zakat listing', $period.' · '.($authority ?? 'all').' · '.count($data['rows']).' staff');

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Staff ID', 'IC no.', 'Zakat authority', 'Amount (RM)']);
            foreach ($data['rows'] as $r) {
                fputcsv($out, Csv::safeRow([$r['name'], $r['staff_id'], $r['ic'], $r['authority'] ?? 'Not set', number_format($r['amount'], 2, '.', '')]));
            }
            fputcsv($out, ['Total', '', '', '', number_format($data['total'], 2, '.', '')]);
            fclose($out);
        }, 'zakat-'.$period.($authority !== null ? '-'.$authority : '').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function authorize(Request $request, PayrollRun $run): void
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        abort_unless($run->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($run->status === 'finalized', 422, 'Only finalized runs can be exported.');
    }
}
