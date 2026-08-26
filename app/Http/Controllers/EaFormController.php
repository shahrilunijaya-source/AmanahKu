<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Payslip;
use App\Services\Payroll\EaFormPdfData;
use App\Tenancy\CurrentTenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Form EA (C.P.8A) — the annual remuneration statement every Malaysian employer must
 * give every employee by 28 February. Authorisation mirrors PayrollPdfController
 * exactly: HR/management may generate anyone's form (and the bulk file); an employee
 * may only fetch their own. An EA form carries NRIC and tax numbers, so every
 * generation is audit-logged, same as a payslip PDF (I-017/I-018 pattern).
 *
 * `show()` is the HR-only on-screen preview: it surfaces the "incomplete boxes" list
 * (EaFormPdfData::ALWAYS_INCOMPLETE_BOXES — figures this app has nowhere to store yet,
 * so HR must write them in by hand before issuing) and, separately, a note that a
 * mid-year joiner needs a SECOND EA from their previous employer — never printed on the
 * form itself, since Form EA is strictly per-employer.
 */
class EaFormController extends Controller
{
    private const ADMIN_ROLES = ['management', 'hr'];

    public function __construct(private readonly EaFormPdfData $pdfData) {}

    /** HR-only preview screen: the incomplete-box checklist for one employee/year before issuing. */
    public function show(Request $request, Employee $employee, int $year): View
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $tenant = app(CurrentTenant::class)->get();
        abort_unless($employee->tenant_id === $tenant?->id, 403);

        $data = $this->pdfData->build($tenant, $employee, $year);

        return view('screens.ea-form-preview', ['data' => $data, 'employee' => $employee]);
    }

    /** One employee's EA form as a PDF. HR/management: any employee. An employee: their own only. */
    public function pdf(Request $request, Employee $employee, int $year): Response
    {
        $tenant = app(CurrentTenant::class)->get();
        abort_unless($employee->tenant_id === $tenant?->id, 403);

        if (! $this->hasTenantRole($request, self::ADMIN_ROLES)) {
            $requester = $this->requestingEmployee($request);
            abort_unless($requester && $requester->id === $employee->id, 403);
        }

        $data = $this->pdfData->build($tenant, $employee, $year);

        // EA forms carry NRIC and tax reference numbers — log every generation.
        AuditLog::record('Downloaded EA form PDF', $employee->name.' · '.$year);

        $pdf = Pdf::loadView('pdf.ea-form', ['forms' => collect([$data])]);

        return $pdf->download($this->filename($employee, $year));
    }

    /** Every employee's EA form for the year as one PDF, one form per page. HR/management only. */
    public function bulk(Request $request, int $year): Response
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $tenant = app(CurrentTenant::class)->get();
        abort_if($tenant === null, 403);

        // Every employee with at least one finalized payslip in the year — an employee
        // with no finalized pay that year has nothing to report on an EA form.
        $employeeIds = Payslip::where('tenant_id', $tenant->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('status', 'finalized')->where('period', 'like', $year.'-%'))
            ->distinct()->pluck('employee_id');
        $employees = Employee::where('tenant_id', $tenant->id)->whereIn('id', $employeeIds)
            ->get()->sortBy('name')->values();

        AuditLog::record('Downloaded bulk EA form PDF', $year.' · '.$employees->count().' employees');

        $forms = $employees->map(fn (Employee $e) => $this->pdfData->build($tenant, $e, $year));

        return Pdf::loadView('pdf.ea-form', ['forms' => $forms])->download("ea-forms-{$year}.pdf");
    }

    private function filename(Employee $employee, int $year): string
    {
        return 'ea-form-'.($employee->staff_id ?? $employee->id)."-{$year}.pdf";
    }

    /** The acting user's own Employee record in the current tenant, or null. */
    private function requestingEmployee(Request $request): ?Employee
    {
        $user = $request->user();

        return $user ? Employee::where('tenant_id', app(CurrentTenant::class)->id())->where('user_id', $user->id)->first() : null;
    }
}
