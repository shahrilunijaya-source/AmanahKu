<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\FeatureManager;
use App\Support\ProfileCompletion;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View as ViewContract;

/**
 * Staff first-login wizard. A self-service, full-screen flow that captures the
 * personal data HR needs — identity, contact/emergency, bank + statutory,
 * certificates, personality. The essential tier (identity + contact) is hard-gated
 * by EnsureProfileComplete; the rest is encouraged and chased by the dashboard nudge.
 * Every save endpoint acts ONLY on the signed-in employee's own record.
 */
class WelcomeWizardController extends Controller
{
    /** The wizard's full-screen page (own layout, no sidebar). */
    public function show(Request $request): ViewContract
    {
        $employee = $this->actingEmployee($request);
        $completion = app(ProfileCompletion::class);

        return view('screens.welcome', [
            'employee' => $employee,
            'completion' => $completion->summary($employee),
            'payrollEnabled' => $this->payrollEnabled(),
            'salary' => $employee->salaryStructure,
            'certificates' => $employee->documents()->where('category', 'Certificate')->latest()->get(),
            'personalityDone' => $employee->profileTestResult()->whereNotNull('submitted_at')->exists(),
        ]);
    }

    /** Save identity + contact/emergency onto the acting employee (the essential tier). */
    public function savePersonal(Request $request): RedirectResponse
    {
        $employee = $this->actingEmployee($request);

        $data = $request->validate([
            'nric' => ['required', 'string', 'max:20'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'gender' => ['required', 'in:male,female'],
            'marital_status' => ['required', 'in:single,married,divorced,widowed'],
            'phone' => ['required', 'string', 'max:40'],
            'address' => ['required', 'string', 'max:500'],
            'emergency_contact_name' => ['required', 'string', 'max:160'],
            'emergency_contact_phone' => ['required', 'string', 'max:40'],
        ]);

        // Explicit whitelist — never trust employee_id from input; always the own record.
        $employee->update($data);

        AuditLog::record('Completed personal details', $employee->name);

        return redirect()->route('welcome.show')->with('ok', 'Personal details saved.');
    }

    /** Save bank + statutory onto the acting employee's salary structure. */
    public function saveBank(Request $request): RedirectResponse
    {
        abort_unless($this->payrollEnabled(), 404);

        $employee = $this->actingEmployee($request);

        // Bank/statutory follows the personal step — the NRIC it mirrors must already
        // exist, so never persist a salary structure with a null NRIC.
        if (! app(ProfileCompletion::class)->essentialDone($employee)) {
            return redirect()->route('welcome.show')->with('error', 'Complete your personal details first.');
        }

        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:120'],
            'bank_account_no' => ['required', 'string', 'max:40'],
            'epf_no' => ['required', 'string', 'max:40'],
            'socso_no' => ['required', 'string', 'max:40'],
        ]);

        // Single source of truth for NRIC is the employee record; mirror it onto the
        // salary structure (encrypted at rest on both via the model cast).
        $employee->salaryStructure()->updateOrCreate(
            ['employee_id' => $employee->id],
            $data + ['nric' => $employee->nric],
        );

        AuditLog::record('Completed bank & statutory details', $employee->name);

        return redirect()->route('welcome.show')->with('ok', 'Bank & statutory details saved.');
    }

    /** Upload a certificate for the acting employee (own record only). */
    public function uploadCertificate(Request $request): RedirectResponse
    {
        $employee = $this->actingEmployee($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:8192'],
        ]);

        $file = $request->file('file');
        $path = $file->store('employee-documents', 'local');
        abort_unless($path !== false, 500, 'File could not be stored.');

        EmployeeDocument::create([
            'employee_id' => $employee->id,
            'title' => $data['title'],
            'category' => 'Certificate',
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by_employee_id' => $employee->id,
        ]);

        AuditLog::record('Uploaded certificate', $employee->name.' · '.$data['title']);

        return redirect()->route('welcome.show')->with('ok', 'Certificate uploaded.');
    }

    /**
     * Leave the wizard for the dashboard. Only reachable once the essential tier is
     * done (the gate keeps them here until then); encouraged items are chased by the
     * dashboard nudge afterwards.
     */
    public function finish(Request $request): RedirectResponse
    {
        $employee = $this->actingEmployee($request);

        // The gate should keep them here until essentials are done; degrade gracefully
        // rather than error out if that invariant is somehow not yet met.
        if (! app(ProfileCompletion::class)->essentialDone($employee)) {
            return redirect()->route('welcome.show')->with('error', 'Please complete the required details first.');
        }

        return redirect()->route('app.screen', 'dash')->with('ok', 'Welcome to Amanahku.');
    }

    /** The signed-in user's employee record in this workspace — required for the wizard. */
    private function actingEmployee(Request $request): Employee
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee instanceof Employee, 403, 'No employee profile in this workspace.');

        return $employee;
    }

    private function payrollEnabled(): bool
    {
        $tenant = app(CurrentTenant::class)->get();

        return $tenant !== null && app(FeatureManager::class)->screenAllowed($tenant, 'payroll');
    }
}
