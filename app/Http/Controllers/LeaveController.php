<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RoutesApprovalsByReportingLine;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\AttachmentName;
use App\Timesheet\WeekReconciler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveController extends Controller
{
    use RoutesApprovalsByReportingLine;

    /** The private disk leave supporting documents (MCs, maternity letters) live on. */
    private const ATTACHMENT_DISK = 'local';

    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'leave_type_id' => ['required', 'integer'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'half_day_period' => ['nullable', 'in:am,pm'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // A half day only makes sense on a single date: you cannot take "the morning off"
        // across a range. Reject the combination rather than silently ignoring the marker.
        $isHalfDay = ($data['half_day_period'] ?? null) !== null;
        if ($isHalfDay && ! Carbon::parse($data['date_from'])->isSameDay(Carbon::parse($data['date_to']))) {
            return back()->withInput()->withErrors([
                'half_day_period' => 'Half day leave must start and end on the same day.',
            ]);
        }

        // LeaveType is tenant-scoped; this also rejects ids from other tenants.
        $type = LeaveType::find($data['leave_type_id']);
        abort_unless($type, 422);

        // Planned leave (e.g. Annual) must be applied for a set number of days ahead.
        // Unplanned/emergency leave is the escape hatch and bypasses the notice rule.
        if (! $type->is_unplanned && $type->min_notice_days > 0) {
            $earliest = Carbon::today()->addDays($type->min_notice_days);
            if (Carbon::parse($data['date_from'])->lt($earliest)) {
                return back()->withInput()->withErrors([
                    'date_from' => $type->name.' leave must be applied for at least '.$type->min_notice_days
                        .' day'.($type->min_notice_days > 1 ? 's' : '').' in advance (from '.$earliest->format('j M').'). '
                        .'For an unplanned absence, use Emergency leave.',
                ]);
            }
        }

        // Types like sick/medical, hospitalisation and maternity legally need a
        // supporting document (Employment Act 1955). Demand the file for those, and
        // accept an optional one for the rest.
        $request->validate([
            'attachment' => [$type->requires_attachment ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192'],
        ], [
            'attachment.required' => $type->name.' leave needs a supporting document (e.g. medical certificate).',
        ]);

        // A half day counts as 0.5; otherwise the inclusive whole-day span, with Unijaya's
        // TOT Saturday (first Saturday of the month) counted as 0.5 like everywhere else it
        // appears. `days` flows straight into the balance decrement at approval, so this is
        // the only place the 0.5 originates.
        $days = $isHalfDay ? 0.5 : LeaveRequest::countDays(Carbon::parse($data['date_from']), Carbon::parse($data['date_to']));

        $attachmentPath = null;
        $attachmentName = null;
        if ($file = $request->file('attachment')) {
            $attachmentPath = $file->store('leave-attachments', self::ATTACHMENT_DISK);
            abort_unless($attachmentPath !== false, 500, 'Attachment could not be stored.');
            $attachmentName = $file->getClientOriginalName();
        }

        // tenant_id is auto-filled by the BelongsToTenant trait.
        $leave = $employee->leaveRequests()->create([
            'leave_type_id' => $data['leave_type_id'],
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'half_day_period' => $data['half_day_period'] ?? null,
            'days' => $days,
            'reason' => $data['reason'] ?? null,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            ...$this->openingStatusColumns($request),
        ]);

        $body = $employee->name.' · '.$days.' day'.($days == 1 ? '' : 's');

        if ($this->skipsVerification($request)) {
            // HR reports straight to the directors: no verify step, straight to final approval.
            $this->notifyManagementToApprove(
                $leave->tenant_id,
                'Leave awaiting final approval',
                $body,
                route('app.screen', 'leave'),
            );
        } else {
            // Step 1 of the gate: the request goes to the immediate superior (org chart) to
            // verify. No superior set → it waits at 'submitted' until one is assigned.
            $this->notifyManagerToVerify(
                $employee,
                'Leave awaiting your verification',
                $body,
                route('app.screen', 'leave'),
            );
        }

        return back()->with('ok', "Leave application submitted ({$days} day".($days == 1 ? '' : 's').').');
    }

    /** Step 1: the immediate superior verifies, moving the request on to management. */
    public function verify(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->assertVerifier($request, $leaveRequest->employee, $leaveRequest->tenant_id);
        abort_unless($leaveRequest->status === 'submitted', 422, 'Only submitted requests can be verified.');

        $this->applyVerification($leaveRequest, $request->attributes->get('employee')?->id);

        return back()->with('ok', 'Leave verified for '.$leaveRequest->employee->name.'. Sent to management for approval.');
    }

    /** Step 2: management gives final approval and the matching balance is decremented. */
    public function approve(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->assertApprover($request, $leaveRequest->employee, $leaveRequest->tenant_id, $leaveRequest->verified_by_id);
        abort_unless($leaveRequest->status === 'verified', 422, 'A request must be verified by the immediate superior before approval.');

        $this->applyApproval($leaveRequest, $request->attributes->get('employee')?->id);

        return back()->with('ok', 'Leave approved for '.$leaveRequest->employee->name.'.');
    }

    /**
     * Verify one request in bulk from the superior's queue. Only the actor's own
     * still-submitted reports are touched (scopeToVerify already enforces this), so
     * anything outside their queue is silently skipped rather than erroring the batch.
     */
    public function bulkVerify(Request $request): RedirectResponse
    {
        $ids = $this->requestedIds($request);
        abort_if($ids === [], 422, 'Select at least one request.');

        $actorId = $request->attributes->get('employee')?->id;
        $eligible = $this->scopeToVerify(LeaveRequest::with('employee', 'leaveType'), $request)->whereKey($ids)->get();

        foreach ($eligible as $leaveRequest) {
            $this->applyVerification($leaveRequest, $actorId);
        }

        return back()->with('ok', $eligible->count().' request(s) verified and sent to management.');
    }

    /**
     * Approve many verified requests at once. scopeToApprove limits to verified rows for
     * management; the loop additionally enforces segregation of duties (never your own
     * request, never one you verified) so bulk can't bypass the single-action guards.
     */
    public function bulkApprove(Request $request): RedirectResponse
    {
        $ids = $this->requestedIds($request);
        abort_if($ids === [], 422, 'Select at least one request.');

        $actorId = $request->attributes->get('employee')?->id;
        $eligible = $this->scopeToApprove(LeaveRequest::with('employee', 'leaveType'), $request)
            ->whereKey($ids)
            ->where(fn ($q) => $q->whereNull('verified_by_id')->orWhere('verified_by_id', '!=', $actorId))
            ->where('employee_id', '!=', $actorId)
            ->get();

        $done = 0;
        foreach ($eligible as $leaveRequest) {
            if ($this->applyApproval($leaveRequest, $actorId)) {
                $done++;
            }
        }

        return back()->with('ok', $done.' request(s) approved.');
    }

    /** Normalise the posted id list to a clean array of ints. */
    private function requestedIds(Request $request): array
    {
        return collect($request->input('ids', []))
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();
    }

    /** Flip a submitted request to verified and fan out the notifications. */
    private function applyVerification(LeaveRequest $leaveRequest, ?int $actorId): void
    {
        $leaveRequest->update([
            'status' => 'verified',
            'verified_by_id' => $actorId,
            'verified_at' => now(),
        ]);

        AuditLog::record('Verified leave', $leaveRequest->employee->name.' · '.$leaveRequest->days.'d');
        $this->notifyManagementToApprove(
            $leaveRequest->tenant_id,
            'Leave awaiting approval',
            $leaveRequest->employee->name.' · '.$leaveRequest->days.' day(s) — verified',
            route('app.screen', 'leave'),
        );
        AppNotification::send(
            $leaveRequest->employee->user_id,
            'Leave verified',
            'Your leave was verified and is awaiting final approval',
            route('app.screen', 'leave'),
        );
    }

    /**
     * Flip a verified request to approved and decrement the matching balance, atomically.
     * Returns false if another approve won the race (so the caller doesn't double-count).
     */
    private function applyApproval(LeaveRequest $leaveRequest, ?int $actorId): bool
    {
        $reconciled = 0;
        $originalDays = (float) $leaveRequest->days;
        $unpaidDays = 0.0;

        $flipped = DB::transaction(function () use ($leaveRequest, $actorId, &$reconciled, &$unpaidDays) {
            // Atomic compare-and-set: two concurrent approves can both pass the status
            // check, but only one flips verified→approved here. The loser matches zero
            // rows and must NOT decrement the balance again.
            $ok = LeaveRequest::whereKey($leaveRequest->id)
                ->where('status', 'verified')
                ->update(['status' => 'approved', 'approved_by_id' => $actorId, 'approved_at' => now()]);

            if ($ok === 0) {
                return false;
            }

            // Emergency leave is not its own entitlement — it draws down the balance of
            // another type (Annual). effectiveBalanceTypeId() resolves that redirection.
            $balanceTypeId = $leaveRequest->leaveType?->effectiveBalanceTypeId() ?? $leaveRequest->leave_type_id;

            $balance = $leaveRequest->employee
                ->leaveBalances()
                ->where('leave_type_id', $balanceTypeId)
                ->lockForUpdate()
                ->first();

            $overflow = null;

            if ($balance) {
                // Days the balance cannot pay for do not block the absence — they move
                // onto Unpaid leave, and only the covered days come off the balance.
                ['spend' => $spend, 'unpaid' => $unpaidDays, 'overflow' => $overflow] = $this->absorbOverflowAsUnpaid($leaveRequest, (float) $balance->balance, $actorId);
                $balance->update(['balance' => max(0, $balance->balance - $spend)]);
            }

            // Leave→timesheet is otherwise pull-based: LockedDays only materialises the
            // "On Leave" rows when a week is displayed or saved. A week saved BEFORE this
            // approval would silently disagree with the approved leave until re-saved, so
            // push the leave into any already-stored week here. Same transaction as the
            // flip + balance so the approval never half-succeeds. The row is 'approved' on
            // this connection now, so LockedDays sees it.
            $reconciled = app(WeekReconciler::class)->reconcileForLeave($leaveRequest);
            if ($overflow) {
                $reconciled += app(WeekReconciler::class)->reconcileForLeave($overflow);
            }

            return true;
        });

        if (! $flipped) {
            return false;
        }

        AuditLog::record('Approved leave', $leaveRequest->employee->name.' · '.$originalDays.'d'
            .($unpaidDays > 0 ? ' ('.$unpaidDays.'d unpaid)' : ''));
        if ($reconciled > 0) {
            AuditLog::record('Reconciled timesheet', $leaveRequest->employee->name.' · '.$reconciled.' week(s) updated for approved leave');
        }
        AppNotification::send(
            $leaveRequest->employee->user_id,
            'Leave approved',
            ($leaveRequest->leaveType?->name ?? 'Leave').' · '.$leaveRequest->days.' day(s)'
                .($unpaidDays > 0 ? ' — '.$unpaidDays.' day(s) beyond your balance approved as unpaid leave' : ''),
            route('app.screen', 'leave'),
        );

        return true;
    }

    /**
     * Trim an approved request to the days its balance can actually pay for and move the
     * rest onto Unpaid leave. Emergency leave shares the Annual quota, so once that quota
     * is spent the absence still stands — it just stops being paid.
     *
     * Whole days only: a stranded half day of balance (2.5 left against a 5 day request)
     * stays on the balance rather than being split across a date, and a half day request
     * is paid in full or not at all.
     *
     * @return array{spend: float, unpaid: float, overflow: ?LeaveRequest}
     */
    private function absorbOverflowAsUnpaid(LeaveRequest $leaveRequest, float $balance, ?int $actorId): array
    {
        $days = (float) $leaveRequest->days;

        if ($balance >= $days) {
            return ['spend' => $days, 'unpaid' => 0.0, 'overflow' => null];
        }

        // is_unpaid, not a name match — a company renaming this type (or seeding it in
        // Malay) must not silently stop overflow from converting to unpaid leave. See the
        // 2026_08_25_210000 migration.
        $unpaid = LeaveType::where('is_unpaid', true)->first();

        if (! $unpaid) {
            AuditLog::record('Leave over balance', $leaveRequest->employee->name.' · '
                .round($days - max(0, $balance), 2).'d unconverted — no Unpaid leave type configured');

            return ['spend' => max(0, $balance), 'unpaid' => 0.0, 'overflow' => null];
        }

        $covered = $leaveRequest->isHalfDay() ? 0.0 : floor(max(0, $balance));

        if ($covered <= 0) {
            // Nothing left to pay for it: the whole absence becomes unpaid, no second row.
            $leaveRequest->update(['leave_type_id' => $unpaid->id]);
            $leaveRequest->setRelation('leaveType', $unpaid);

            return ['spend' => 0.0, 'unpaid' => $days, 'overflow' => null];
        }

        $paidTo = $leaveRequest->date_from->copy()->addDays((int) $covered - 1);

        $overflow = LeaveRequest::create([
            'tenant_id' => $leaveRequest->tenant_id,
            'employee_id' => $leaveRequest->employee_id,
            'leave_type_id' => $unpaid->id,
            'date_from' => $paidTo->copy()->addDay(),
            'date_to' => $leaveRequest->date_to,
            'days' => $days - $covered,
            'reason' => $leaveRequest->reason,
            'status' => 'approved',
            'verified_by_id' => $leaveRequest->verified_by_id,
            'verified_at' => $leaveRequest->verified_at,
            'approved_by_id' => $actorId,
            'approved_at' => now(),
        ]);

        $leaveRequest->update(['date_to' => $paidTo, 'days' => $covered]);

        return ['spend' => $covered, 'unpaid' => $days - $covered, 'overflow' => $overflow];
    }

    /** The requester withdraws their own application before anyone approves it. */
    public function cancel(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->assertSameTenant($leaveRequest->tenant_id);
        $actor = $request->attributes->get('employee');
        abort_unless($actor && $actor->id === $leaveRequest->employee_id, 403);

        // Compare-and-set so a cancel can never race past an approval that already
        // decremented the balance — only a still-pending row flips.
        $flipped = LeaveRequest::whereKey($leaveRequest->id)
            ->whereIn('status', ['submitted', 'verified'])
            ->update(['status' => 'cancelled']);
        abort_if($flipped === 0, 422, 'Only an application still awaiting approval can be cancelled.');

        AuditLog::record('Cancelled leave', $actor->name.' · '.$leaveRequest->days.'d');

        return back()->with('ok', 'Leave application cancelled.');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->assertCanReject($request, $leaveRequest);

        $leaveRequest->update([
            'status' => 'rejected',
            'rejected_by_id' => $request->attributes->get('employee')?->id,
            'rejected_at' => now(),
        ]);
        AuditLog::record('Rejected leave', $leaveRequest->employee->name);
        AppNotification::send(
            $leaveRequest->employee->user_id,
            'Leave declined',
            ($leaveRequest->leaveType?->name ?? 'Leave').' request was declined',
            route('app.screen', 'leave'),
        );

        return back()->with('ok', 'Leave rejected for '.$leaveRequest->employee->name.'.');
    }

    /**
     * Stream a leave supporting document through an auth-gated action (never a public
     * URL). Medical certificates and maternity letters are sensitive: only the
     * requester, their immediate superior (the verifier) and management/HR may view.
     */
    public function attachment(Request $request, LeaveRequest $leaveRequest): StreamedResponse
    {
        $this->assertSameTenant($leaveRequest->tenant_id);
        abort_unless($leaveRequest->attachment_path, 404);

        /** @var Employee|null $actor */
        $actor = $request->attributes->get('employee');
        $role = $request->attributes->get('tenantRole');

        $isOwner = $actor && $actor->id === $leaveRequest->employee_id;
        $isSuperior = $actor && $leaveRequest->employee?->reports_to_id === $actor->id;
        $isPrivileged = in_array($role, ['management', 'hr'], true);

        abort_unless($isOwner || $isSuperior || $isPrivileged, 403);
        abort_unless(Storage::disk(self::ATTACHMENT_DISK)->exists($leaveRequest->attachment_path), 404);

        return Storage::disk(self::ATTACHMENT_DISK)->download(
            $leaveRequest->attachment_path,
            AttachmentName::build(
                $leaveRequest->employee?->name,
                'leave',
                $leaveRequest->id,
                $leaveRequest->attachment_path,
                $leaveRequest->date_from,
            ),
        );
    }
}
