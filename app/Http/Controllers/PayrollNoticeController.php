<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollNotice;
use App\Services\Payroll\EaFormData;
use App\Services\Payroll\LifecycleNotices;
use App\Tenancy\CurrentTenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec F11: LHDN CP22 / CP22A / CP21, PERKESO Form 2 and KWSP registration notices —
 * marking one filed, recording LHDN's clearance, opening a CP21 by hand, and the
 * PCB 2(II) statement that goes with a CP22A.
 */
class PayrollNoticeController extends Controller
{
    /** @var list<string> */
    private const ADMIN_ROLES = ['management', 'hr'];

    public function __construct(private readonly LifecycleNotices $notices, private readonly EaFormData $eaData) {}

    public function file(Request $request, PayrollNotice $notice): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $this->assertTenant($notice);

        $data = $request->validate([
            'filed_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $notice->forceFill([
            'filed_on' => $data['filed_on'],
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? $notice->note,
            'filed_by_id' => $request->user()?->id,
        ])->save();

        AuditLog::record('Filed statutory notice', strtoupper($notice->type).' · '.($notice->employee !== null ? $notice->employee->name : '').' · '.$data['filed_on']);

        return back()->with('ok', strtoupper($notice->type).' marked filed.');
    }

    public function clear(Request $request, PayrollNotice $notice): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $this->assertTenant($notice);
        abort_unless($notice->type === 'cp22a', 422, 'Only a CP22A carries an LHDN clearance.');

        $data = $request->validate(['cleared_on' => ['required', 'date']]);
        $notice->forceFill(['cleared_on' => $data['cleared_on']])->save();

        AuditLog::record('Recorded LHDN clearance', ($notice->employee !== null ? $notice->employee->name : '').' · '.$data['cleared_on']);

        return back()->with('ok', 'LHDN clearance recorded.');
    }

    /** A CP21 is opened by hand — only HR knows the employee is leaving Malaysia. */
    public function cp21(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_if($employee->last_working_day === null, 422, 'Set the last working day before opening a CP21.');

        $due = CarbonImmutable::parse($employee->last_working_day)->subDays(30);
        $today = CarbonImmutable::parse(now()->toDateString());
        $notice = $this->notices->open($employee, 'cp21', ($due->lt($today) ? $today : $due)->toDateString());

        AuditLog::record('Opened CP21 notice', $employee->name.' · due '.$notice->due_on->toDateString());

        return back()->with('ok', 'CP21 opened for '.$employee->name.'.');
    }

    /** PCB 2(II): the employer's statement of tax deducted, issued with a CP22A. */
    public function pcb2ii(Request $request, PayrollNotice $notice): Response
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $this->assertTenant($notice);
        abort_unless($notice->type === 'cp22a', 404);

        $employee = $notice->employee;
        abort_if($employee === null, 404);
        $tenant = app(CurrentTenant::class)->get();
        abort_if($tenant === null, 403);

        $year = (int) ($notice->due_on !== null ? $notice->due_on->year : now()->year);
        $ea = $this->eaData->forEmployee($tenant, $employee, $year);

        // Carries the employee's NRIC — logged like every other identity-bearing export.
        AuditLog::record('Downloaded PCB 2(II)', $employee->name.' · '.$year);

        return Pdf::loadView('pdf.pcb2ii', [
            'tenant' => $tenant,
            'employee' => $employee,
            'notice' => $notice,
            'year' => $year,
            'ea' => $ea,
            'prefill' => $this->notices->prefill($notice),
        ])->download('pcb2ii-'.$employee->id.'-'.$year.'.pdf');
    }

    /** Route-model binding resolves before the tenant scope is active — assert explicitly. */
    private function assertTenant(PayrollNotice $notice): void
    {
        abort_unless($notice->tenant_id === app(CurrentTenant::class)->id(), 403);
    }
}
