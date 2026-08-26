<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Payroll\PayslipPdfData;
use App\Tenancy\CurrentTenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payslip PDFs — the client's current HRMS issues a real PDF file, not a print
 * stylesheet, so this renders through dompdf (pure PHP, no system binary — the
 * production host is shared hosting with no shell access).
 */
class PayrollPdfController extends Controller
{
    private const ADMIN_ROLES = ['management', 'hr'];

    public function __construct(private readonly PayslipPdfData $pdfData) {}

    /** One payslip as a PDF. HR/management: any payslip in their tenant. An employee: their own, finalized only. */
    public function show(Request $request, Payslip $payslip): Response
    {
        abort_unless($payslip->tenant_id === app(CurrentTenant::class)->id(), 403);

        $privileged = $this->hasTenantRole($request, self::ADMIN_ROLES);
        if (! $privileged) {
            $employee = $this->requestingEmployee($request);
            abort_unless($employee && $payslip->employee_id === $employee->id, 403);
        }
        // An "Official Payslip" is only ever issued once its run is finalized — a draft's
        // figures can still change, for HR too, mirroring the CSV exports' finalized-only rule.
        abort_unless($payslip->payrollRun?->status === 'finalized', 422, 'This payslip is not yet issued.');

        $payslip->load(['employee.salaryStructure', 'employee.department', 'employee.employmentType', 'employee.leaveBalances.leaveType', 'payrollRun', 'lines']);

        // Payslip PDFs carry NRIC and bank details — log every generation (I-017/I-018 pattern).
        AuditLog::record('Downloaded payslip PDF', $payslip->employee?->name.' · '.$payslip->payrollRun?->label);

        $pdf = Pdf::loadView('pdf.payslip', ['payslips' => collect([$payslip])->map(fn (Payslip $p) => $this->pdfData->build($p))]);

        return $pdf->download($this->filename($payslip));
    }

    /** Every payslip of a finalized run as one PDF, one payslip per page. HR/management only. */
    public function bulk(Request $request, PayrollRun $run): Response
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        abort_unless($run->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($run->status === 'finalized', 422, 'Only finalized runs can be issued as PDF.');

        $payslips = $run->payslips()
            ->with(['employee.salaryStructure', 'employee.department', 'employee.employmentType', 'employee.leaveBalances.leaveType', 'payrollRun', 'lines'])
            ->get()->sortBy(fn (Payslip $p) => $p->employee?->name)->values();

        AuditLog::record('Downloaded bulk payslip PDF', $run->label.' · '.$payslips->count().' payslips');

        $pdf = Pdf::loadView('pdf.payslip', ['payslips' => $payslips->map(fn (Payslip $p) => $this->pdfData->build($p))]);

        return $pdf->download('payslips-'.$run->period.'.pdf');
    }

    private function filename(Payslip $payslip): string
    {
        // employee is genuinely nullable — Larastan false-positives "nullsafe.neverNull"
        // on ?-> here, so this is written as an explicit null check to sidestep that
        // rather than silence it.
        $employee = $payslip->employee;
        $staffId = ($employee !== null ? $employee->staff_id : null) ?? $payslip->employee_id;

        return 'payslip-'.$staffId.'-'.$payslip->payrollRun?->period.'.pdf';
    }

    /** The acting user's own Employee record in the current tenant, or null (e.g. HR-only login with no linked employee). */
    private function requestingEmployee(Request $request): ?Employee
    {
        $user = $request->user();

        return $user ? Employee::where('tenant_id', app(CurrentTenant::class)->id())->where('user_id', $user->id)->first() : null;
    }
}
