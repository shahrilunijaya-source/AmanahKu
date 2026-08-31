<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Payslip;
use App\Services\Payroll\Cp8dData;
use App\Services\Payroll\Cp8dLine;
use App\Services\Payroll\FormEData;
use App\Tenancy\CurrentTenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The employer's annual return: Form E (C.P.8 - Pin. 2025) and the C.P.8D employee
 * schedule it's filed alongside. Both cover EVERY employee's tax data company-wide, so
 * unlike Form EA there is no employee-facing route at all — HR/management only,
 * everywhere, same as PayrollExportController's bank/statutory exports.
 *
 * `show()` is the HR-only preview: Form E's own gaps (FormEData::incomplete) plus, per
 * employee, the compulsory C.P.8D fields this app could not fill (Cp8dData::incomplete)
 * — so HR knows before downloading that LHDN will reject the C.P.8D file until those are
 * completed by hand. Generation itself is never blocked on that (matches EaFormController
 * letting HR issue an EA with boxes still blank); the warning is the safeguard.
 */
class FormEController extends Controller
{
    private const ADMIN_ROLES = ['management', 'hr'];

    public function __construct(
        private readonly FormEData $formEData,
        private readonly Cp8dData $cp8dData,
    ) {}

    /** HR-only preview: Form E's gaps, and the per-employee C.P.8D compulsory-field checklist. */
    public function show(Request $request, int $year): View
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $tenant = app(CurrentTenant::class)->get();
        abort_if($tenant === null, 403);

        $formE = $this->formEData->build($tenant, $year);
        $employees = $this->reportableEmployees($tenant->id, $year);
        $cp8dRows = $employees->map(fn (Employee $e) => [
            'employee' => $e,
            'data' => $this->cp8dData->forEmployee($tenant, $e, $year),
        ]);

        return view('screens.form-e-preview', [
            'formE' => $formE,
            'cp8dRows' => $cp8dRows,
            'year' => $year,
            // Employer TIN is compulsory for the C.P.8D filename itself (see cp8d()'s
            // own docblock) — the screen disables that download outright rather than
            // let HR discover the 422 only after clicking.
            'employerTinMissing' => blank($tenant->employer_tin),
        ]);
    }

    /** Form E itself as a PDF. */
    public function pdf(Request $request, int $year): Response
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $tenant = app(CurrentTenant::class)->get();
        abort_if($tenant === null, 403);

        $formE = $this->formEData->build($tenant, $year);

        // Form E carries the employer's TIN and headcount figures — audit every generation.
        AuditLog::record('Downloaded Form E PDF', (string) $year);

        $pdf = Pdf::loadView('pdf.form-e', ['data' => $formE]);

        return $pdf->download("form-e-{$year}.pdf");
    }

    /**
     * The C.P.8D employee schedule as the LHDN-format text file.
     *
     * Refuses outright — never a partial download — when the tenant has no
     * `employer_tin`. Every other gap in this app (missing children headcount, tax
     * borne by employer, ...) is a per-employee blank HR can fill in by hand on the
     * printed forms; this one is different. The employer TIN is baked into the
     * filename itself (Cp8dLine::filename, "P<E no.>_<year>.txt" per the spec), so
     * without it the file cannot even be named correctly — e-CP8D rejects a wrongly
     * named upload before it looks at a single field inside. A file that "downloads
     * successfully" in that state is not a smaller deliverable, it's an invalid one
     * dressed up as a real one.
     */
    public function cp8d(Request $request, int $year): StreamedResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $tenant = app(CurrentTenant::class)->get();
        abort_if($tenant === null, 403);
        abort_if(
            blank($tenant->employer_tin),
            422,
            "The company's Employer TIN is not set. Add it in Company Settings before downloading the C.P.8D file — LHDN rejects a file whose name is missing it."
        );

        $employees = $this->reportableEmployees($tenant->id, $year);
        $rows = $employees->map(fn (Employee $e) => $this->cp8dData->forEmployee($tenant, $e, $year));
        $missingCount = $rows->filter(fn ($row) => $row['incomplete'] !== [])->count();

        // This is everyone's NRIC and tax data in one file — audit every generation,
        // and note when it still has compulsory gaps LHDN will reject it for.
        AuditLog::record(
            'Downloaded C.P.8D file',
            $year.' · '.$rows->count().' employees'
                .($missingCount > 0 ? " · {$missingCount} with compulsory fields still missing" : '')
        );

        $filename = Cp8dLine::filename((string) $tenant->employer_tin, $year);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                // CRLF: the layout PDF and e-CP8D's own documentation say nothing about
                // line endings either way. CRLF is the safer assumption for a Windows-
                // oriented government upload portal (LF-only is the risk of being
                // silently misread, not the reverse) — revisit if e-CP8D ever rejects
                // a file over this.
                fwrite($out, Cp8dLine::format($row)."\r\n");
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/plain']);
    }

    /**
     * Every employee with at least one finalized payslip in the year — same "nothing to
     * report otherwise" rule EaFormController::bulk() uses, and for the same reason: a
     * draft/approved run can still change, so it must never feed a statutory export.
     *
     * This deliberately still includes an employee whose finalized payslip nets to
     * every C.P.8D money field printing blank (e.g. unpaid leave the whole period,
     * zero MTD that month) — they were on payroll that year, which is what both the
     * layout PDF's "employees' particulars" and Form E's Part A counts are keyed off,
     * not "had a non-zero figure to report". An employee who was never run through
     * payroll at all in the year has nothing to report and is correctly excluded by
     * this same query (no Payslip row exists for them to match on).
     *
     * @return Collection<int, Employee>
     */
    private function reportableEmployees(int $tenantId, int $year): Collection
    {
        $employeeIds = Payslip::where('tenant_id', $tenantId)
            ->whereHas('payrollRun', fn ($q) => $q->where('status', 'finalized')->where('period', 'like', $year.'-%'))
            ->distinct()->pluck('employee_id');

        return Employee::where('tenant_id', $tenantId)->whereIn('id', $employeeIds)
            ->get()->sortBy('name')->values();
    }
}
