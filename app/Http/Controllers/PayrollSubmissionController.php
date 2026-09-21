<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PayrollSubmission;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Spec F12: HR records that a statutory filing actually went in, with its receipt. */
class PayrollSubmissionController extends Controller
{
    /** @var list<string> */
    private const ADMIN_ROLES = ['management', 'hr'];

    public function submit(Request $request, PayrollSubmission $submission): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        // Route-model binding resolves across tenants — check ownership explicitly.
        abort_unless($submission->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'receipt_reference' => ['required', 'string', 'max:80'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'submitted_at' => ['nullable', 'date'],
        ]);

        $submission->forceFill([
            'submitted_at' => $data['submitted_at'] ?? now(),
            'submitted_by_id' => Auth::id(),
            'receipt_reference' => $data['receipt_reference'],
            'amount_paid' => $data['amount_paid'] ?? null,
        ])->save();

        $label = PayrollSubmission::LABELS[$submission->agency][0] ?? $submission->agency;
        AuditLog::record('Recorded statutory submission', $label.' · '.($submission->payrollRun !== null ? $submission->payrollRun->label : (string) $submission->year).' · '.$data['receipt_reference']);

        return back()->with('ok', $label.' marked submitted.');
    }
}
