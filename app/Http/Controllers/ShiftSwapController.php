<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSingleStepApproval;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftSwap;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShiftSwapController extends Controller
{
    use AuthorizesSingleStepApproval;

    /**
     * Build the shift-swap screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Every employee sees: their upcoming shifts (eligible to swap), their own swap
     * requests, and swaps where they are the named counterpart awaiting their accept.
     * Privileged roles additionally receive the approvals queue (data-layer gated) so
     * they can approve/reject — accept-then-approve, or approve a giveaway directly.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, $this->singleStepApproverRoles());

        // Shifts the current employee owns and could offer up — upcoming, not cancelled.
        $mySwappableShifts = $employee
            ? Shift::where('employee_id', $employee->id)
                ->whereDate('date', '>=', now()->toDateString())
                ->where('status', '!=', 'cancelled')
                ->orderBy('date')->orderBy('start_time')->get()
            : new Collection;

        $mySwaps = $employee
            ? ShiftSwap::with(['shift', 'counterpart'])->where('requester_employee_id', $employee->id)->latest()->get()
            : new Collection;

        // Swaps where I'm the named counterpart and the requester is still waiting on me.
        $awaitingMyAcceptance = $employee
            ? ShiftSwap::with(['shift', 'requester'])
                ->where('counterpart_employee_id', $employee->id)
                ->where('status', 'requested')
                ->latest()->get()
            : new Collection;

        // Approval queue: requested giveaways (no counterpart needed) + accepted swaps.
        $pendingSwaps = $privileged
            ? ShiftSwap::with(['shift', 'requester', 'counterpart'])
                ->whereIn('status', ['requested', 'accepted'])
                ->latest()->get()
            : new Collection;

        // Colleagues a requester can name as counterpart (everyone but themselves).
        $employees = $employee
            ? Employee::active()->where('id', '!=', $employee->id)->orderBy('name')->get(['id', 'name'])
            : new Collection;

        return [
            'privileged' => $privileged,
            'mySwappableShifts' => $mySwappableShifts,
            'mySwaps' => $mySwaps,
            'awaitingMyAcceptance' => $awaitingMyAcceptance,
            'pendingSwaps' => $pendingSwaps,
            'employees' => $employees,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'shift_id' => ['required', 'integer', Rule::exists('shifts', 'id')->where('tenant_id', $tenantId)],
            'counterpart_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // Ownership guard: a swap can only be raised on one of the requester's own shifts.
        $shift = Shift::where('id', $data['shift_id'])->where('employee_id', $employee->id)->first();
        abort_unless($shift, 403, 'You can only swap your own shifts.');

        // Can't hand a shift to yourself.
        $counterpartId = $data['counterpart_employee_id'] ?? null;
        if ($counterpartId && (int) $counterpartId === (int) $employee->id) {
            $counterpartId = null;
        }

        ShiftSwap::create([
            'tenant_id' => $tenantId,
            'shift_id' => $shift->id,
            'requester_employee_id' => $employee->id,
            'counterpart_employee_id' => $counterpartId,
            'reason' => $data['reason'] ?? null,
            'status' => 'requested',
        ]);

        AuditLog::record('Requested shift swap', $employee->name.' · '.$shift->date->format('D, j M').' · '.$shift->location);

        // Notify the named counterpart that a swap awaits their acceptance (AK-PROC-02).
        // A no-counterpart giveaway has nobody to notify — the approval queue surfaces it.
        if ($counterpartId) {
            $counterpartUserId = Employee::whereKey($counterpartId)->value('user_id');
            if ($counterpartUserId) {
                AppNotification::send(
                    $counterpartUserId,
                    'Shift swap needs your response',
                    $employee->name.' asked you to take a shift on '.$shift->date->format('D, j M'),
                    route('app.screen', 'shiftswap'),
                );
            }
        }

        return back()->with('ok', 'Swap request submitted.');
    }

    public function accept(Request $request, ShiftSwap $swap): RedirectResponse
    {
        abort_unless($swap->tenant_id === app(CurrentTenant::class)->id(), 403);
        $actor = $request->attributes->get('employee');
        // Only the named counterpart may accept, and only while still requested.
        abort_unless($actor && $swap->counterpart_employee_id === $actor->id, 403, 'This swap is not addressed to you.');
        abort_unless($swap->status === 'requested', 422);

        $swap->update(['status' => 'accepted']);
        AuditLog::record('Accepted shift swap', $actor->name.' accepted swap #'.$swap->id);
        AppNotification::send(
            $swap->requester?->user_id,
            'Swap accepted',
            ($actor->name).' accepted your shift swap — awaiting approval',
            route('app.screen', 'shiftswap'),
        );

        return back()->with('ok', 'Swap accepted — now awaiting approval.');
    }

    public function approve(Request $request, ShiftSwap $swap): RedirectResponse
    {
        $this->authorizeSingleStepApprover($request, $swap, $swap->requester_employee_id);
        // The counterpart must have accepted first — a shift can't be reassigned to someone
        // who never consented (AK-REL-02). A still-'requested' swap is not yet approvable.
        abort_unless($swap->status === 'accepted', 422, 'The counterpart must accept the swap before it can be approved.');

        // The shift must move to a real person: the named counterpart.
        $counterpartId = $swap->counterpart_employee_id;
        abort_unless($counterpartId, 422, 'No counterpart to reassign the shift to.');

        $shift = $swap->shift;
        abort_unless($shift, 422);

        // Both writes are one unit, and the CAS on swap status ensures only one concurrent
        // approve reassigns the shift (no partial reassign-without-approve on a race/crash).
        $approved = DB::transaction(function () use ($swap, $shift, $counterpartId, $request) {
            $flipped = ShiftSwap::whereKey($swap->id)->where('status', 'accepted')->update([
                'status' => 'approved',
                'decided_at' => now(),
                'decided_by_id' => $request->attributes->get('employee')?->id,
            ]);

            if ($flipped === 0) {
                return false;
            }

            $shift->update(['employee_id' => $counterpartId]);

            return true;
        });

        if (! $approved) {
            return back()->with('ok', 'Swap already approved.');
        }

        $to = Employee::find($counterpartId)?->name ?? 'employee';
        AuditLog::record('Approved shift swap', 'Shift #'.$shift->id.' reassigned to '.$to);
        AppNotification::send(
            $swap->requester?->user_id,
            'Swap approved',
            'Your shift was reassigned to '.$to,
            route('app.screen', 'shiftswap'),
        );
        AppNotification::send(
            $swap->counterpart?->user_id,
            'Shift assigned to you',
            'A swapped shift on '.$shift->date->format('D, j M').' is now yours',
            route('app.screen', 'shiftswap'),
        );

        return back()->with('ok', 'Swap approved — shift reassigned to '.$to.'.');
    }

    public function reject(Request $request, ShiftSwap $swap): RedirectResponse
    {
        $this->authorizeSingleStepApprover($request, $swap, $swap->requester_employee_id);
        abort_unless(in_array($swap->status, ['requested', 'accepted'], true), 422);

        // Compare-and-set so a double-click doesn't double-notify the requester.
        $flipped = ShiftSwap::whereKey($swap->id)->whereIn('status', ['requested', 'accepted'])->update([
            'status' => 'rejected',
            'decided_at' => now(),
            'decided_by_id' => $request->attributes->get('employee')?->id,
        ]);
        if ($flipped === 0) {
            return back()->with('ok', 'Swap already actioned.');
        }

        AuditLog::record('Rejected shift swap', 'Swap #'.$swap->id);
        AppNotification::send(
            $swap->requester?->user_id,
            'Swap declined',
            'Your shift swap request was declined',
            route('app.screen', 'shiftswap'),
        );

        return back()->with('ok', 'Swap rejected.');
    }
}
