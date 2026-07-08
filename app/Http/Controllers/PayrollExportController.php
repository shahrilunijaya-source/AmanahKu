<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PayrollRun;
use App\Services\Payroll\BankFile\BankFileRegistry;
use App\Support\Csv;
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

        $payslips = $run->payslips()->with('employee.salaryStructure')->get()
            ->sortBy(fn ($p) => $p->employee?->name)->values();

        AuditLog::record('Exported bank file', $run->label.' · '.$payslips->count().' employees · '
            .$format->label().($format->verified() ? '' : ' (unverified layout)'));

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
                'EIS (Employee)', 'EIS (Employer)', 'PCB',
            ]);

            $fmt = fn ($v) => number_format((float) $v, 2, '.', '');
            foreach ($payslips as $p) {
                $s = $p->employee?->salaryStructure;
                // Name / NRIC / EPF / SOCSO numbers are user-controlled — neutralise CSV injection.
                fputcsv($out, Csv::safeRow([
                    $p->employee?->name, $s?->nric, $s?->epf_no,
                    $fmt($p->epf_employee), $fmt($p->epf_employer),
                    $s?->socso_no, $fmt($p->socso_employee), $fmt($p->socso_employer),
                    $fmt($p->eis_employee), $fmt($p->eis_employer), $fmt($p->pcb),
                ]));
            }

            fputcsv($out, [
                'TOTAL', '', '',
                $fmt($payslips->sum('epf_employee')), $fmt($payslips->sum('epf_employer')),
                '', $fmt($payslips->sum('socso_employee')), $fmt($payslips->sum('socso_employer')),
                $fmt($payslips->sum('eis_employee')), $fmt($payslips->sum('eis_employer')),
                $fmt($payslips->sum('pcb')),
            ]);
            fclose($out);
        }, 'statutory-'.$run->period.'.csv', ['Content-Type' => 'text/csv']);
    }

    /** management/hr only, own tenant, finalized runs only (drafts aren't submittable). */
    private function authorize(Request $request, PayrollRun $run): void
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        abort_unless($run->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($run->status === 'finalized', 422, 'Only finalized runs can be exported.');
    }
}
