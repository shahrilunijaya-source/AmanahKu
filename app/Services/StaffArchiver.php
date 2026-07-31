<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LoanRequest;
use App\Models\OvertimeRequest;
use App\Models\TravelRequest;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;

/**
 * Archives a staff member and severs their LIVE ties so "archived" means detached from all
 * responsibility — reports move up-chain, reporting pivots drop, open tasks pass to their
 * manager, and pending requests close. History (approved leave, payslips, past records) is
 * left intact. Shared by the HR "Archive staff" action (EmployeeController::destroy) and the
 * scheduled auto-archive of departed staff (ArchiveDepartedStaff), so both take one path.
 */
class StaffArchiver
{
    /**
     * Archive + detach in a single transaction. Idempotent: a no-op returning false when the
     * person is already archived. The caller owns authorization and tenant scoping.
     */
    public function archive(Employee $employee): bool
    {
        if ($employee->isArchived()) {
            return false;
        }

        DB::transaction(function () use ($employee) {
            $employee->update(['archived_at' => now()]);
            $this->detach($employee);
        });

        AuditLog::record('Archived employee', $employee->name);

        return true;
    }

    /**
     * Sever the archived person's live ties. Runs inside archive()'s transaction. Public so a
     * one-time backfill can reconcile staff who were archived before the cascade existed.
     */
    public function detach(Employee $employee): void
    {
        // 1. Re-point their direct reports up the chain (to this person's own manager, or
        //    none) so no active employee is left reporting to an archived manager.
        Employee::where('tenant_id', $employee->tenant_id)
            ->where('reports_to_id', $employee->id)
            ->update(['reports_to_id' => $employee->reports_to_id]);

        // 2. Drop dotted-line reporting-line pivots in BOTH directions — as a report (their
        //    own extra managers) and as a manager (people who list them as an extra manager).
        DB::table('employee_manager')
            ->where('employee_id', $employee->id)
            ->orWhere('manager_id', $employee->id)
            ->delete();

        // 3. Hand their open task cards to their manager so the work stays owned and visible.
        //    With no manager the card is left as-is (it drops off active boards but is never
        //    silently reassigned to nobody).
        if ($employee->reports_to_id) {
            WorkItem::where('employee_id', $employee->id)
                ->where('status', '!=', 'done')
                ->update(['employee_id' => $employee->reports_to_id]);
        }

        // 4. Close their still-pending requests — an archived person holds no live request.
        //    Approved/rejected/paid history is untouched (only submitted/verified move). Covers
        //    every request type: leave, claims, overtime, loans, travel.
        foreach ([LeaveRequest::class, Claim::class, OvertimeRequest::class, LoanRequest::class, TravelRequest::class] as $model) {
            $model::where('employee_id', $employee->id)
                ->whereIn('status', ['submitted', 'verified'])
                ->update(['status' => 'rejected']);
        }

        // 5. Release any company assets back to the pool — an archived person holds no kit,
        //    and the asset becomes reassignable. Retired/maintenance assets are left as-is.
        Asset::where('employee_id', $employee->id)
            ->where('status', 'assigned')
            ->update(['employee_id' => null, 'status' => 'available']);
    }
}
