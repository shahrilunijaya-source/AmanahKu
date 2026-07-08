<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HR "Leave Setup" — set each employee's opening leave balance per leave type.
 *
 * This is the migration path for carry-forward: balances from a previous system are
 * entered here as the starting point. Balances are written to the per-type
 * leave_balances table — the same rows accrual, carry-forward and leave approval
 * read and write — NOT the legacy employees.leave_balance scalar (which the profile
 * and dashboard cards now also read from leave_balances). Privileged (management / HR) only.
 */
class LeaveSetupController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /** Data for the Leave Setup screen: the staff × leave-type opening-balance matrix. */
    public function screenData(Request $request): array
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $types = LeaveType::orderBy('name')->get();
        $staff = Employee::active()->with('leaveBalances')->orderBy('name')->get();

        // Pre-fill grid: [employee_id][leave_type_id] => current balance (missing row => no key).
        $matrix = $staff->mapWithKeys(fn (Employee $e) => [
            $e->id => $e->leaveBalances->keyBy('leave_type_id')->map(fn ($b) => (float) $b->balance),
        ]);

        return [
            'leaveTypes' => $types,
            'setupStaff' => $staff,
            'balanceMatrix' => $matrix,
        ];
    }

    /**
     * Save the whole grid. Each filled cell is an opening balance that OVERWRITES the
     * current per-type balance (upsert on employee_id + leave_type_id). Blank cells are
     * left untouched. Only ids belonging to this tenant's active staff and leave types
     * are honoured; a forged employee/type id in the payload is ignored, so the grid can
     * never write a balance across tenants.
     */
    public function save(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $validated = $request->validate([
            'balances' => ['array'],
            'balances.*' => ['array'],
            'balances.*.*' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ]);

        // Whitelist writable ids from the tenant's own data (both models are tenant-scoped).
        $staffIds = Employee::active()->pluck('id')->flip();
        $typeIds = LeaveType::pluck('id')->flip();

        $updated = 0;

        DB::transaction(function () use ($validated, $staffIds, $typeIds, &$updated) {
            foreach ($validated['balances'] ?? [] as $employeeId => $byType) {
                if (! is_array($byType) || ! $staffIds->has((int) $employeeId)) {
                    continue;
                }

                foreach ($byType as $typeId => $value) {
                    if ($value === null || $value === '' || ! $typeIds->has((int) $typeId)) {
                        continue;
                    }

                    LeaveBalance::updateOrCreate(
                        ['employee_id' => (int) $employeeId, 'leave_type_id' => (int) $typeId],
                        ['balance' => (float) $value],
                    );
                    $updated++;
                }
            }
        });

        AuditLog::record('Set opening leave balances', $updated.' balance(s) updated');

        return back()->with('ok', "Leave balances saved ({$updated} updated).");
    }
}
