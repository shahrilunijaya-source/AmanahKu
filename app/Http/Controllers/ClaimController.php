<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RoutesApprovalsByReportingLine;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\Employee;
use App\Services\FeatureManager;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClaimController extends Controller
{
    use RoutesApprovalsByReportingLine;

    /** The private disk claim receipts live on. */
    private const RECEIPT_DISK = 'local';

    /** Claim types whose reimbursement needs a receipt as proof of spend. Mileage is a
     *  computed rate (no receipt) and "other" is a catch-all, so both keep it optional. */
    private const REQUIRES_RECEIPT = ['expense', 'medical', 'travel'];

    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'type' => ['required', 'in:mileage,medical,expense,travel,other'],
            'title' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // Medical claims share an annual reimbursement ceiling per employee, counted by
        // expense-date year across all non-rejected claims. Reject anything that would
        // push the running total past the cap.
        if ($data['type'] === 'medical') {
            $cap = (float) app(FeatureManager::class)->value(app(CurrentTenant::class)->get(), 'claims.medical_cap');
            $year = Carbon::parse($data['date'])->year;
            $usedThisYear = (float) $employee->claims()
                ->where('type', 'medical')
                ->where('status', '!=', 'rejected')
                ->whereYear('date', $year)
                ->sum('amount');

            if ($usedThisYear + (float) $data['amount'] > $cap) {
                $remaining = max(0, $cap - $usedThisYear);
                throw ValidationException::withMessages([
                    'amount' => 'Medical claims are capped at RM '.number_format($cap, 2).' per year. RM '
                        .number_format($remaining, 2).' left for '.$year.'.',
                ]);
            }
        }

        // Reimbursement needs proof of spend: expense/medical/travel demand a receipt,
        // mileage (a computed rate) and "other" accept an optional one.
        $requiresReceipt = in_array($data['type'], self::REQUIRES_RECEIPT, true);
        $request->validate([
            'receipt' => [$requiresReceipt ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192'],
        ], [
            'receipt.required' => 'A '.$data['type'].' claim needs a receipt or supporting document.',
        ]);

        $receiptPath = null;
        $receiptName = null;
        if ($file = $request->file('receipt')) {
            $receiptPath = $file->store('claim-receipts', self::RECEIPT_DISK);
            abort_unless($receiptPath !== false, 500, 'Receipt could not be stored.');
            $receiptName = $file->getClientOriginalName();
        }

        $employee->claims()->create([
            'type' => $data['type'],
            'title' => $data['title'],
            'amount' => $data['amount'],
            'date' => $data['date'],
            'reason' => $data['reason'] ?? null,
            'receipt_path' => $receiptPath,
            'receipt_name' => $receiptName,
            'status' => 'submitted',
        ]);

        // Step 1 of the gate: routed to the immediate superior (org chart) to verify.
        $this->notifyManagerToVerify(
            $employee,
            'Claim awaiting your verification',
            $data['title'].' · RM '.number_format((float) $data['amount'], 2),
            route('app.screen', 'claims'),
        );

        return back()->with('ok', 'Claim submitted for RM '.number_format((float) $data['amount'], 2).'.');
    }

    /** Step 1: the immediate superior verifies, moving the claim on to management. */
    public function verify(Request $request, Claim $claim): RedirectResponse
    {
        $this->assertVerifier($request, $claim->employee, $claim->tenant_id);
        abort_unless($claim->status === 'submitted', 422, 'Only submitted claims can be verified.');

        // Atomic compare-and-set: two concurrent verifies both pass the check above, but
        // only one flips submitted→verified — the loser skips the duplicate audit + notify.
        $flipped = Claim::whereKey($claim->id)->where('status', 'submitted')->update([
            'status' => 'verified',
            'verified_by_id' => $request->attributes->get('employee')?->id,
            'verified_at' => now(),
        ]);
        if ($flipped === 0) {
            return back()->with('ok', 'Claim verified for '.$claim->employee->name.'.');
        }

        AuditLog::record('Verified claim', $claim->employee->name.' · RM '.number_format($claim->amount, 2));
        $this->notifyManagementToApprove(
            $claim->tenant_id,
            'Claim awaiting approval',
            $claim->employee->name.' · '.$claim->title.' · RM '.number_format($claim->amount, 2).' — verified',
            route('app.screen', 'claims'),
        );
        AppNotification::send(
            $claim->employee->user_id,
            'Claim verified',
            $claim->title.' was verified and is awaiting management approval',
            route('app.screen', 'claims'),
        );

        return back()->with('ok', 'Claim verified for '.$claim->employee->name.'. Sent to management for approval.');
    }

    /** Step 2: management gives final approval. */
    public function approve(Request $request, Claim $claim): RedirectResponse
    {
        $this->assertApprover($request, $claim->employee, $claim->tenant_id, $claim->verified_by_id);
        abort_unless($claim->status === 'verified', 422, 'A claim must be verified by the immediate superior before approval.');

        // Compare-and-set so two concurrent approves don't double-audit / double-notify.
        $flipped = Claim::whereKey($claim->id)->where('status', 'verified')->update(['status' => 'approved']);
        if ($flipped === 0) {
            return back()->with('ok', 'Claim approved for '.$claim->employee->name.'.');
        }

        AuditLog::record('Approved claim', $claim->employee->name.' · RM '.number_format($claim->amount, 2));
        AppNotification::send(
            $claim->employee->user_id,
            'Claim approved',
            $claim->title.' · RM '.number_format($claim->amount, 2),
            route('app.screen', 'claims'),
        );

        return back()->with('ok', 'Claim approved for '.$claim->employee->name.'.');
    }

    public function reject(Request $request, Claim $claim): RedirectResponse
    {
        $this->assertCanReject($request, $claim);

        // Compare-and-set from either pending state so a double-click doesn't double-notify.
        $flipped = Claim::whereKey($claim->id)->whereIn('status', ['submitted', 'verified'])->update(['status' => 'rejected']);
        if ($flipped === 0) {
            return back()->with('ok', 'Claim rejected for '.$claim->employee->name.'.');
        }

        AuditLog::record('Rejected claim', $claim->employee->name);
        AppNotification::send(
            $claim->employee->user_id,
            'Claim declined',
            $claim->title.' was declined',
            route('app.screen', 'claims'),
        );

        return back()->with('ok', 'Claim rejected for '.$claim->employee->name.'.');
    }

    /**
     * Stream a claim receipt through an auth-gated action (never a public URL).
     * Receipts can carry personal/financial detail: only the claimant, their immediate
     * superior (the verifier) and management/HR may view.
     */
    public function receipt(Request $request, Claim $claim): StreamedResponse
    {
        $this->assertSameTenant($claim->tenant_id);
        abort_unless($claim->receipt_path, 404);

        /** @var Employee|null $actor */
        $actor = $request->attributes->get('employee');
        $role = $request->attributes->get('tenantRole');

        $isOwner = $actor && $actor->id === $claim->employee_id;
        $isSuperior = $actor && $claim->employee?->reports_to_id === $actor->id;
        $isPrivileged = in_array($role, ['management', 'hr'], true);

        abort_unless($isOwner || $isSuperior || $isPrivileged, 403);
        abort_unless(Storage::disk(self::RECEIPT_DISK)->exists($claim->receipt_path), 404);

        return Storage::disk(self::RECEIPT_DISK)->download(
            $claim->receipt_path,
            $claim->receipt_name ?? 'claim-receipt',
        );
    }
}
